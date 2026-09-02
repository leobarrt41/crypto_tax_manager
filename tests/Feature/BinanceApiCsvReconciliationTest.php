<?php

namespace Tests\Feature;

use App\Models\Exchange;
use App\Models\FifoInventoryGap;
use App\Models\Transaction;
use App\Models\TransactionReconciliation;
use App\Models\User;
use App\Models\UserApiKey;
use App\Services\BinanceApiCsvReconciliationService;
use App\Services\BinanceImportService;
use App\Services\FifoCalculatorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use ReflectionMethod;
use Tests\TestCase;

class BinanceApiCsvReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private UserApiKey $apiKey;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['email_verified_at' => now()]);
        $exchange = Exchange::query()->create(['name' => 'binance', 'country_code' => 'MT']);
        $this->apiKey = UserApiKey::query()->create([
            'user_id' => $this->user->id,
            'exchange_id' => $exchange->id,
            'api_key' => 'read-only-key',
            'secret_key' => 'read-only-secret',
            'read_enabled' => true,
            'trading_enabled' => false,
        ]);
    }

    public function test_reconciliation_preserves_raw_records_and_fifo_uses_only_documented_csv_convert(): void
    {
        $this->transaction('buy', 'BRL', '2400', 'USDT', '400', '2025-01-01 08:00:00', '2400');
        $api = $this->apiConvert('api-quote-1');
        $csv = $this->csvConvert('csv-row-1');
        $sale = $this->transaction('sell', '1MBABYDOGE', '122216.76', 'BRL', '3000', '2025-01-03 08:00:00', '3000');
        $apiRaw = $this->rawFields($api);
        $csvRaw = $this->rawFields($csv);

        $result = app(BinanceApiCsvReconciliationService::class)->reconcileTransaction($csv);

        $this->assertSame('reconciled', $result['status']);
        $reconciliation = TransactionReconciliation::query()->sole();
        $this->assertSame($csv->id, $reconciliation->canonical_transaction_id);
        $this->assertSame($api->id, $reconciliation->matched_transaction_id);
        $this->assertSame('2513.2905916201', $reconciliation->matching_evidence['csv_received_value_brl']);
        $this->assertSame($apiRaw, $this->rawFields($api->fresh()));
        $this->assertSame($csvRaw, $this->rawFields($csv->fresh()));

        $stats = app(FifoCalculatorService::class)->recalculateForUser($this->user->id);

        $this->assertSame(3, $stats['transactions_read']);
        $this->assertSame(FifoInventoryGap::COST_KNOWN, $csv->fresh()->to_cost_status);
        $this->assertSame(2513.2905916201, (float) $sale->fresh()->cost_basis_brl);
        $this->assertDatabaseCount('fifo_inventory_gaps', 0);
        $this->assertDatabaseCount('transactions', 4);
    }

    public function test_csv_upload_automatically_reconciles_existing_api_convert_with_different_reference(): void
    {
        $api = $this->apiConvert('api-reference');
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
        $csv = implode("\n", [
            'id,datetime_tz_GMT-03:00,type,label,market_model_type,order_type,sent_amount,sent_currency,sent_value_BRL,sent_address,received_amount,received_currency,received_value_BRL,received_address,fee_amount,fee_currency,fee_value_BRL',
            'csv-reference,2025-01-02 08:48:38,Trade,,CONVERT,,400,USDT,2518.58,,122216.76,1MBABYDOGE,2513.2905916201,,0,,0',
        ]);

        $this->actingAs($this->user)->post(route('transactions.import.csv'), [
            'file' => UploadedFile::fake()->createWithContent('2025.csv', $csv),
            'format' => 'binance',
            'skip_duplicates' => true,
            'source_type' => 'exchange',
            'source_id' => $this->apiKey->id,
            'coverage_year' => 2025,
            'coverage_month' => 1,
        ])->assertRedirect(route('transactions.index'));

        $reconciliation = TransactionReconciliation::query()->sole();
        $this->assertSame($api->id, $reconciliation->matched_transaction_id);
        $this->assertSame('csv-reference', $reconciliation->canonicalTransaction->reference);
        $this->assertDatabaseCount('transactions', 2);
    }

    public function test_automatic_api_import_reconciles_when_csv_was_imported_first(): void
    {
        $csv = $this->csvConvert('csv-first');
        $service = new BinanceImportService($this->user, $this->apiKey->id);
        $method = new ReflectionMethod($service, 'saveConversion');
        $method->setAccessible(true);
        $method->invoke($service, [
            'quoteId' => 'api-after-csv',
            'createTime' => $csv->date->getTimestampMs(),
            'fromAsset' => 'USDT',
            'fromAmount' => '400',
            'toAsset' => '1MBABYDOGE',
            'toAmount' => '122216.76',
        ]);

        $reconciliation = TransactionReconciliation::query()->sole();
        $this->assertSame($csv->id, $reconciliation->canonical_transaction_id);
        $this->assertSame('api-after-csv', $reconciliation->matchedTransaction->reference);
    }

    public function test_matching_is_idempotent_and_rejects_different_amount_or_ambiguous_candidates(): void
    {
        $api = $this->apiConvert('api-1');
        $different = $this->csvConvert('csv-different', ['to_amount' => '122216.75']);
        $service = app(BinanceApiCsvReconciliationService::class);
        $this->assertSame('no_match', $service->reconcileTransaction($different)['status']);

        $csv = $this->csvConvert('csv-exact');
        $this->apiConvert('api-2');
        $this->assertSame('ambiguous', $service->reconcileTransaction($csv)['status']);
        $this->assertDatabaseCount('transaction_reconciliations', 0);

        $api->delete();
        $this->assertSame('reconciled', $service->reconcileTransaction($csv)['status']);
        $this->assertSame('already_reconciled', $service->reconcileTransaction($csv)['status']);
        $this->assertDatabaseCount('transaction_reconciliations', 1);
    }

    public function test_command_is_dry_run_by_default_and_requires_apply_to_persist(): void
    {
        $this->apiConvert('api-command');
        $this->csvConvert('csv-command');

        $this->assertSame(0, Artisan::call('binance:reconcile-api-csv', [
            'user_id' => $this->user->id,
            'year' => 2025,
        ]));
        $this->assertDatabaseCount('transaction_reconciliations', 0);

        Artisan::call('binance:reconcile-api-csv', [
            'user_id' => $this->user->id,
            'year' => 2025,
            '--apply' => true,
        ]);
        $this->assertDatabaseCount('transaction_reconciliations', 1);
    }

    private function apiConvert(string $reference): Transaction
    {
        return $this->transaction('convert', 'USDT', '400', '1MBABYDOGE', '122216.76', '2025-01-02 08:48:38', '2518.58', [
            'reference' => $reference,
            'pricing_status' => 'completed',
            'to_cost_status' => FifoInventoryGap::COST_ESTIMATED,
            'to_cost_evidence_type' => 'historical_market_quote',
        ]);
    }

    private function csvConvert(string $reference, array $attributes = []): Transaction
    {
        return $this->transaction('convert', 'USDT', '400', '1MBABYDOGE', '122216.76', '2025-01-02 08:48:38', '2518.58', array_merge([
            'reference' => $reference,
            'import_metadata' => [
                'format' => 'binance_annual_csv',
                'brl_values' => [
                    'sent_value_brl' => '2518.58',
                    'received_value_brl' => '2513.2905916201',
                    'selected_source' => 'sent_value_brl',
                ],
            ],
        ], $attributes));
    }

    private function transaction(
        string $type,
        ?string $fromAsset,
        ?string $fromAmount,
        ?string $toAsset,
        ?string $toAmount,
        string $date,
        ?string $totalBrl,
        array $attributes = [],
    ): Transaction {
        return Transaction::query()->create(array_merge([
            'user_id' => $this->user->id,
            'source_type' => UserApiKey::class,
            'source_id' => $this->apiKey->id,
            'type' => $type,
            'operation' => in_array($type, ['sell', 'withdrawal'], true) ? 'saida' : 'entrada',
            'from_asset' => $fromAsset,
            'from_amount' => $fromAmount,
            'to_asset' => $toAsset,
            'to_amount' => $toAmount,
            'total_brl' => $totalBrl,
            'date' => $date,
        ], $attributes));
    }

    /** @return array<string, mixed> */
    private function rawFields(Transaction $transaction): array
    {
        $raw = $transaction->only([
            'from_asset', 'from_amount', 'to_asset', 'to_amount', 'total_brl',
            'reference', 'txid', 'source_type', 'source_id', 'import_metadata',
        ]);
        $raw['date'] = $transaction->date?->toIso8601String();

        return $raw;
    }
}
