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
use Carbon\Carbon;
use Illuminate\Database\QueryException;
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
        $api = $this->apiConvert('api-quote-1', ['txid' => 'stable-event-1']);
        $csv = $this->csvConvert('csv-row-1', ['txid' => 'stable-event-1']);
        $sale = $this->transaction('sell', '1MBABYDOGE', '122216.76', 'BRL', '3000', '2025-01-03 08:00:00', '3000');
        $apiRaw = $this->rawFields($api);
        $csvRaw = $this->rawFields($csv);

        $result = app(BinanceApiCsvReconciliationService::class)->reconcileTransaction($csv);

        $this->assertSame('pending_review', $result['status']);
        $reconciliation = TransactionReconciliation::query()->sole();
        $this->assertSame($csv->id, $reconciliation->canonical_transaction_id);
        $this->assertSame($api->id, $reconciliation->matched_transaction_id);
        $this->assertSame('high', $reconciliation->confidence);
        $this->assertSame('txid', $reconciliation->matching_evidence['stable_id']['field']);
        $this->assertSame($apiRaw, $this->rawFields($api->fresh()));
        $this->assertSame($csvRaw, $this->rawFields($csv->fresh()));

        app(BinanceApiCsvReconciliationService::class)->transition(
            $reconciliation,
            TransactionReconciliation::STATUS_CONFIRMED,
            $this->user,
            'Identificador estável conferido no teste.',
        );

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

        $this->actingAs($this->user)->post(route('transactions.import.csv'), [
            'file' => UploadedFile::fake()->createWithContent('2025.csv', $csv),
            'format' => 'binance',
            'skip_duplicates' => true,
            'source_type' => 'exchange',
            'source_id' => $this->apiKey->id,
            'coverage_year' => 2025,
            'coverage_month' => 1,
        ])->assertRedirect(route('transactions.index'));

        $this->assertDatabaseCount('transactions', 2);
        $reconciliation = TransactionReconciliation::query()->sole();
        $this->assertSame(TransactionReconciliation::STATUS_PENDING_REVIEW, $reconciliation->status);
        $this->assertSame($api->id, $reconciliation->matched_transaction_id);
        $this->assertSame('csv-reference', $reconciliation->canonicalTransaction->reference);
        $this->assertDatabaseCount('transactions', 2);
        $this->assertDatabaseCount('transaction_import_evidences', 0);
        $this->assertSame(2, app(FifoCalculatorService::class)->recalculateForUser($this->user->id)['transactions_read']);
    }

    public function test_reimport_enriches_api_same_reference_without_creating_another_transaction(): void
    {
        $this->transaction('buy', 'BRL', '2400', 'USDT', '400', '2025-01-01 08:00:00', '2400');
        $api = $this->apiConvert('legacy-reference', ['date' => '2025-01-02 11:48:38']);
        $sale = $this->transaction('sell', '1MBABYDOGE', '122216.76', 'BRL', '3000', '2025-01-03 08:00:00', '3000');
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
        $csv = implode("\n", [
            'id,datetime_tz_GMT-03:00,type,label,market_model_type,order_type,sent_amount,sent_currency,sent_value_BRL,sent_address,received_amount,received_currency,received_value_BRL,received_address,fee_amount,fee_currency,fee_value_BRL',
            'legacy-reference,2025-01-02 08:48:38,Trade,,CONVERT,,400,USDT,2518.58,,122216.76,1MBABYDOGE,2513.2905916201,,0,,0',
        ]);

        $this->actingAs($this->user)->post(route('transactions.import.csv'), [
            'file' => UploadedFile::fake()->createWithContent('2025.csv', $csv),
            'format' => 'binance',
            'skip_duplicates' => true,
            'source_type' => 'exchange',
            'source_id' => $this->apiKey->id,
            'coverage_year' => 2025,
            'coverage_month' => 1,
        ])->assertSessionHas('success', fn (string $message): bool => str_contains($message, '1 transações existentes receberam evidência documental'));

        $this->actingAs($this->user)->post(route('transactions.import.csv'), [
            'file' => UploadedFile::fake()->createWithContent('2025.csv', $csv),
            'format' => 'binance',
            'skip_duplicates' => true,
            'source_type' => 'exchange',
            'source_id' => $this->apiKey->id,
            'coverage_year' => 2025,
            'coverage_month' => 1,
        ])->assertRedirect(route('transactions.index'));

        $this->assertDatabaseCount('transactions', 3);
        $this->assertDatabaseCount('transaction_import_evidences', 1);
        $this->assertDatabaseCount('transaction_reconciliations', 0);
        $this->assertNull($api->fresh()->import_metadata);

        $stats = app(BinanceApiCsvReconciliationService::class)->reconcileUserYear($this->user->id, 2025);
        $this->assertSame(0, $stats['csv_transactions_scanned']);
        app(FifoCalculatorService::class)->recalculateForUser($this->user->id);
        $this->assertSame(FifoInventoryGap::COST_KNOWN, $api->fresh()->to_cost_status);
        $this->assertSame(2513.2905916201, (float) $sale->fresh()->cost_basis_brl);
        $this->assertDatabaseCount('fifo_inventory_gaps', 0);
    }

    public function test_same_reference_with_divergent_economic_event_preserves_csv_idempotently(): void
    {
        $api = $this->apiConvert('shared-divergent', [
            'type' => 'sell',
            'from_asset' => 'BTC',
            'from_amount' => '1',
        ]);
        $before = $this->rawFields($api);
        $line = 'shared-divergent,2025-01-02 08:48:38,Trade,,CONVERT,,400,USDT,2518.58,,122216.76,1MBABYDOGE,2513.2905916201,,0,,0';

        $this->importAnnualCsvLine($line);
        $this->importAnnualCsvLine($line);

        $this->assertDatabaseCount('transactions', 2);
        $this->assertDatabaseCount('transaction_import_evidences', 0);
        $this->assertDatabaseCount('transaction_reconciliations', 0);
        $this->assertSame($before, $this->rawFields($api->fresh()));
        $this->assertDatabaseHas('transactions', ['import_origin' => 'binance_annual_csv']);
    }

    public function test_same_reference_with_real_timestamp_difference_preserves_csv_idempotently(): void
    {
        $this->apiConvert('shared-time', ['date' => '2025-01-02 12:00:00']);
        $line = 'shared-time,2025-01-02 08:48:38,Trade,,CONVERT,,400,USDT,2518.58,,122216.76,1MBABYDOGE,2513.2905916201,,0,,0';

        $this->importAnnualCsvLine($line);
        $this->importAnnualCsvLine($line);

        $this->assertDatabaseCount('transactions', 2);
        $this->assertDatabaseCount('transaction_import_evidences', 0);
        $this->assertDatabaseCount('transaction_reconciliations', 0);
    }

    public function test_same_reference_with_legacy_unknown_preserves_both_rows_idempotently(): void
    {
        $this->mock(\App\Services\TransactionImportEvidenceService::class)
            ->shouldNotReceive('attachAnnualCsvEvidence');
        $legacy = $this->transaction(
            'convert',
            'USDT',
            '400',
            '1MBABYDOGE',
            '122216.76',
            '2025-01-02 08:48:38',
            null,
            ['reference' => 'shared-legacy', 'import_origin' => 'legacy_unknown'],
        );
        $before = $this->rawFields($legacy);
        $line = 'shared-legacy,2025-01-02 08:48:38,Trade,,CONVERT,,400,USDT,2518.58,,122216.76,1MBABYDOGE,2513.2905916201,,0,,0';

        $this->importAnnualCsvLine($line);
        $this->importAnnualCsvLine($line);

        $this->assertDatabaseCount('transactions', 2);
        $this->assertDatabaseCount('transaction_import_evidences', 0);
        $this->assertDatabaseCount('transaction_reconciliations', 0);
        $this->assertSame($before, $this->rawFields($legacy->fresh()));
        $this->assertDatabaseHas('transactions', ['import_origin' => 'binance_annual_csv']);
    }

    public function test_same_reference_with_manual_transaction_preserves_both_rows_idempotently(): void
    {
        $this->mock(\App\Services\TransactionImportEvidenceService::class)
            ->shouldNotReceive('attachAnnualCsvEvidence');
        $manual = $this->transaction(
            'convert',
            'USDT',
            '400',
            '1MBABYDOGE',
            '122216.76',
            '2025-01-02 08:48:38',
            null,
            ['reference' => 'shared-manual', 'import_origin' => 'manual'],
        );
        $before = $this->rawFields($manual);
        $line = 'shared-manual,2025-01-02 08:48:38,Trade,,CONVERT,,400,USDT,2518.58,,122216.76,1MBABYDOGE,2513.2905916201,,0,,0';

        $this->importAnnualCsvLine($line);
        $this->importAnnualCsvLine($line);

        $this->assertDatabaseCount('transactions', 2);
        $this->assertDatabaseCount('transaction_import_evidences', 0);
        $this->assertDatabaseCount('transaction_reconciliations', 0);
        $this->assertSame($before, $this->rawFields($manual->fresh()));
        $this->assertDatabaseHas('transactions', ['import_origin' => 'binance_annual_csv']);
    }

    public function test_technical_csv_reference_is_user_scoped_and_decimal_canonical(): void
    {
        $controller = app(\App\Http\Controllers\TransactionController::class);
        $identityMethod = new ReflectionMethod($controller, 'withBinanceAnnualCsvRowIdentity');
        $identityMethod->setAccessible(true);
        $referenceMethod = new ReflectionMethod($controller, 'annualCsvCollisionReference');
        $referenceMethod->setAccessible(true);
        $base = [
            'user_id' => $this->user->id,
            'import_origin' => 'binance_annual_csv',
            'reference' => 'same-binance-reference',
            'type' => 'convert',
            'operation' => 'convert',
            'from_asset' => 'usdt',
            'from_amount' => '400',
            'to_asset' => 'btc',
            'to_amount' => '0.01',
            'date' => Carbon::parse('2025-01-02 08:48:38', 'America/Sao_Paulo'),
            'import_metadata' => ['format' => 'binance_annual_csv', 'existing_key' => 'preserved'],
        ];

        $reference = fn (array $data): string => $referenceMethod->invoke(
            $controller,
            $identityMethod->invoke($controller, $data),
        );

        $this->assertSame($reference($base), $reference([...$base, 'from_amount' => '400.0']));
        $this->assertSame($reference($base), $reference([...$base, 'from_amount' => '400.0000000000']));
        $this->assertNotSame(
            $reference([...$base, 'from_amount' => '9007199254740992.0000000001']),
            $reference([...$base, 'from_amount' => '9007199254740992.0000000002']),
        );

        $otherUser = User::factory()->create(['email_verified_at' => now()]);
        $this->assertNotSame($reference($base), $reference([...$base, 'user_id' => $otherUser->id]));
        $identified = $identityMethod->invoke($controller, $base);
        $this->assertSame('preserved', data_get($identified, 'import_metadata.existing_key'));
        $this->assertSame('same-binance-reference', data_get($identified, 'import_metadata.source_reference'));
    }

    public function test_legacy_unknown_never_receives_csv_evidence_automatically(): void
    {
        $legacy = $this->transaction('convert', 'USDT', '0.0000000100', 'SHIB', '1000', '2025-01-02 08:48:38', null, [
            'reference' => 'scientific-reference',
        ]);
        $mapped = [
            'user_id' => $this->user->id,
            'type' => 'convert',
            'from_asset' => 'USDT',
            'from_amount' => 1.0E-8,
            'to_asset' => 'SHIB',
            'to_amount' => 1000.0,
            'date' => $legacy->date,
            'reference' => 'scientific-reference',
            'import_metadata' => [
                'format' => 'binance_annual_csv',
                'brl_values' => ['received_value_brl' => '0.01'],
            ],
        ];

        $evidence = app(\App\Services\TransactionImportEvidenceService::class)
            ->attachAnnualCsvEvidence($legacy, $mapped);

        $this->assertNull($evidence);
        $this->assertDatabaseCount('transaction_import_evidences', 0);
    }

    public function test_same_stable_reference_with_divergent_economic_event_does_not_attach_evidence(): void
    {
        $api = $this->apiConvert('stable-divergent');
        $mapped = [
            'type' => 'sell',
            'from_asset' => 'BTC',
            'from_amount' => '401',
            'to_asset' => '1MBABYDOGE',
            'to_amount' => '122216.76',
            'date' => $api->date,
            'reference' => 'stable-divergent',
            'import_metadata' => ['format' => 'binance_annual_csv'],
        ];

        $evidence = app(\App\Services\TransactionImportEvidenceService::class)
            ->attachAnnualCsvEvidence($api, $mapped);

        $this->assertNull($evidence);
        $this->assertDatabaseCount('transaction_import_evidences', 0);
    }

    public function test_same_wall_clock_in_utc_and_brt_but_more_than_five_seconds_apart_does_not_attach_evidence(): void
    {
        $api = $this->apiConvert('timezone-reference', [
            'date' => '2025-01-02 08:48:38+00:00',
        ]);
        $mapped = [
            'type' => 'convert',
            'from_asset' => 'USDT',
            'from_amount' => '400',
            'to_asset' => '1MBABYDOGE',
            'to_amount' => '122216.76',
            'date' => Carbon::parse('2025-01-02 08:48:38', 'America/Sao_Paulo'),
            'reference' => 'timezone-reference',
            'import_metadata' => ['format' => 'binance_annual_csv'],
        ];

        $evidence = app(\App\Services\TransactionImportEvidenceService::class)
            ->attachAnnualCsvEvidence($api, $mapped);

        $this->assertNull($evidence);
        $this->assertDatabaseCount('transaction_import_evidences', 0);
    }

    public function test_stable_evidence_reimport_is_idempotent(): void
    {
        $api = $this->apiConvert('idempotent-reference');
        $mapped = [
            'type' => 'convert',
            'from_asset' => 'USDT',
            'from_amount' => '400',
            'to_asset' => '1MBABYDOGE',
            'to_amount' => '122216.76',
            'date' => $api->date,
            'reference' => 'idempotent-reference',
            'import_metadata' => [
                'format' => 'binance_annual_csv',
                'brl_values' => ['received_value_brl' => '2513.2905916201'],
            ],
        ];
        $service = app(\App\Services\TransactionImportEvidenceService::class);

        $first = $service->attachAnnualCsvEvidence($api, $mapped);
        $second = $service->attachAnnualCsvEvidence($api, $mapped);

        $this->assertSame($first?->id, $second?->id);
        $this->assertDatabaseCount('transaction_import_evidences', 1);
        $this->assertDatabaseCount('transaction_reconciliations', 0);
        $this->assertDatabaseCount('transactions', 1);
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
        $this->assertSame(TransactionReconciliation::STATUS_PENDING_REVIEW, $reconciliation->status);
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
        $this->assertSame('pending_review', $service->reconcileTransaction($csv)['status']);
        $this->assertSame('already_reconciled', $service->reconcileTransaction($csv)['status']);
        $this->assertDatabaseHas('transaction_reconciliations', ['status' => TransactionReconciliation::STATUS_PENDING_REVIEW]);
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

    public function test_api_with_non_null_metadata_is_identified_by_explicit_origin(): void
    {
        $api = $this->apiConvert('api-with-payload', [
            'import_metadata' => ['endpoint' => '/sapi/v1/convert/tradeFlow'],
        ]);
        $csv = $this->csvConvert('csv-without-common-id');

        $result = app(BinanceApiCsvReconciliationService::class)->reconcileTransaction($api);

        $this->assertSame('pending_review', $result['status']);
        $this->assertSame('binance_api', $api->fresh()->import_origin);
        $this->assertSame($csv->id, $result['reconciliation']->canonical_transaction_id);
    }

    public function test_heuristic_match_does_not_change_fifo_until_explicit_confirmation(): void
    {
        $this->transaction('buy', 'BRL', '2400', 'USDT', '800', '2025-01-01 08:00:00', '2400');
        $this->apiConvert('api-heuristic');
        $csv = $this->csvConvert('csv-heuristic');
        $this->transaction('sell', '1MBABYDOGE', '122216.76', 'BRL', '3000', '2025-01-03 08:00:00', '3000');

        $result = app(BinanceApiCsvReconciliationService::class)->reconcileTransaction($csv);
        $this->assertSame(TransactionReconciliation::STATUS_PENDING_REVIEW, $result['reconciliation']->status);
        $this->assertSame(4, app(FifoCalculatorService::class)->recalculateForUser($this->user->id)['transactions_read']);

        app(BinanceApiCsvReconciliationService::class)
            ->transition($result['reconciliation'], TransactionReconciliation::STATUS_CONFIRMED, $this->user);
        $this->assertSame(3, app(FifoCalculatorService::class)->recalculateForUser($this->user->id)['transactions_read']);
    }

    public function test_one_csv_cannot_reconcile_two_api_rows_and_ambiguous_match_is_not_persisted(): void
    {
        $this->apiConvert('api-a');
        $this->apiConvert('api-b');
        $csv = $this->csvConvert('csv-only');

        $result = app(BinanceApiCsvReconciliationService::class)->reconcileTransaction($csv);

        $this->assertSame('ambiguous', $result['status']);
        $this->assertSame(2, $result['candidates']);
        $this->assertDatabaseCount('transaction_reconciliations', 0);
    }

    public function test_reconciled_transaction_deletion_is_blocked_to_preserve_audit(): void
    {
        $api = $this->apiConvert('api-delete', ['txid' => 'stable-delete']);
        $csv = $this->csvConvert('csv-delete', ['txid' => 'stable-delete']);
        $service = app(BinanceApiCsvReconciliationService::class);
        $pending = $service->reconcileTransaction($csv)['reconciliation'];
        $service->transition($pending, TransactionReconciliation::STATUS_CONFIRMED, $this->user);

        try {
            $api->delete();
            $this->fail('A exclusão deveria ter sido bloqueada.');
        } catch (\LogicException) {
            $this->assertDatabaseCount('transaction_reconciliations', 1);
            $this->assertDatabaseCount('transaction_reconciliation_events', 1);
        }
    }

    public function test_database_prevents_one_csv_from_reconciling_two_api_transactions(): void
    {
        $api = $this->apiConvert('api-unique-a', ['txid' => 'unique-a']);
        $csv = $this->csvConvert('csv-unique', ['txid' => 'unique-a']);
        $first = app(BinanceApiCsvReconciliationService::class)->reconcileTransaction($csv)['reconciliation'];
        $otherApi = $this->apiConvert('api-unique-b');

        $this->expectException(QueryException::class);
        TransactionReconciliation::query()->create([
            ...$first->only(['user_id', 'canonical_transaction_id', 'match_type', 'confidence', 'fingerprint', 'status', 'matching_evidence', 'reconciled_at']),
            'matched_transaction_id' => $otherApi->id,
        ]);
    }

    public function test_database_prevents_one_api_from_reconciling_two_csv_transactions(): void
    {
        $api = $this->apiConvert('api-matched-unique');
        $firstCsv = $this->csvConvert('csv-matched-a');
        $first = app(BinanceApiCsvReconciliationService::class)->reconcileTransaction($firstCsv)['reconciliation'];
        $otherCsv = $this->csvConvert('csv-matched-b', ['date' => '2025-01-02 08:49:38']);

        $this->expectException(QueryException::class);
        TransactionReconciliation::query()->create([
            ...$first->only(['user_id', 'matched_transaction_id', 'match_type', 'confidence', 'fingerprint', 'status', 'matching_evidence', 'reconciled_at']),
            'canonical_transaction_id' => $otherCsv->id,
        ]);
    }

    public function test_review_transitions_preserve_evidence_and_each_transition_timestamp(): void
    {
        $this->apiConvert('api-review');
        $csv = $this->csvConvert('csv-review');
        $service = app(BinanceApiCsvReconciliationService::class);
        $pending = $service->reconcileTransaction($csv)['reconciliation'];
        $evidence = $pending->matching_evidence;

        $confirmed = $service->transition($pending, TransactionReconciliation::STATUS_CONFIRMED, $this->user, 'Conferido');
        $this->assertNotNull($confirmed->pending_review_at);
        $this->assertNotNull($confirmed->confirmed_at);
        $this->assertSame($evidence, $confirmed->matching_evidence);
        $this->assertSame(1, app(FifoCalculatorService::class)->recalculateForUser($this->user->id)['transactions_read']);
        $this->assertSame(1, app(FifoCalculatorService::class)->recalculateForUser($this->user->id)['transactions_read']);

        $revoked = $service->transition($confirmed, TransactionReconciliation::STATUS_REVOKED, $this->user, 'Revisão posterior');
        $this->assertNotNull($revoked->revoked_at);
        $this->assertSame($evidence, $revoked->matching_evidence);
        $this->assertSame(2, app(FifoCalculatorService::class)->recalculateForUser($this->user->id)['transactions_read']);
        $this->assertDatabaseHas('transaction_reconciliation_events', [
            'reconciliation_id' => $pending->id,
            'actor_user_id' => $this->user->id,
            'previous_status' => TransactionReconciliation::STATUS_PENDING_REVIEW,
            'new_status' => TransactionReconciliation::STATUS_CONFIRMED,
            'reason' => 'Conferido',
        ]);
        $this->assertDatabaseHas('transaction_reconciliation_events', [
            'reconciliation_id' => $pending->id,
            'actor_user_id' => $this->user->id,
            'previous_status' => TransactionReconciliation::STATUS_CONFIRMED,
            'new_status' => TransactionReconciliation::STATUS_REVOKED,
            'reason' => 'Revisão posterior',
        ]);
        $this->assertDatabaseCount('transaction_reconciliation_events', 2);
    }

    public function test_explicit_manual_origin_is_preserved_without_inference(): void
    {
        $manual = $this->transaction('deposit', null, null, 'BTC', '0.1', '2025-01-02 10:00:00', null, [
            'import_origin' => 'manual',
        ]);

        $this->assertSame('manual', $manual->fresh()->import_origin);
    }

    public function test_rejection_is_audited_and_keeps_both_rows_in_fifo(): void
    {
        $this->transaction('buy', 'BRL', '2400', 'USDT', '800', '2025-01-01 08:00:00', '2400');
        $this->apiConvert('api-reject');
        $csv = $this->csvConvert('csv-reject');
        $this->transaction('sell', '1MBABYDOGE', '122216.76', 'BRL', '3000', '2025-01-03 08:00:00', '3000');
        $service = app(BinanceApiCsvReconciliationService::class);
        $pending = $service->reconcileTransaction($csv)['reconciliation'];

        $rejected = $service->transition($pending, TransactionReconciliation::STATUS_REJECTED, $this->user, 'Não são a mesma operação');

        $this->assertNotNull($rejected->rejected_at);
        $this->assertSame(4, app(FifoCalculatorService::class)->recalculateForUser($this->user->id)['transactions_read']);
        $this->assertDatabaseHas('transaction_reconciliation_events', [
            'reconciliation_id' => $pending->id,
            'actor_user_id' => $this->user->id,
            'event_type' => TransactionReconciliation::STATUS_REJECTED,
            'previous_status' => TransactionReconciliation::STATUS_PENDING_REVIEW,
            'new_status' => TransactionReconciliation::STATUS_REJECTED,
        ]);
    }

    public function test_review_command_requires_actor_and_covers_confirm_reject_revoke_and_invalid_cases(): void
    {
        $this->apiConvert('api-command-review');
        $csv = $this->csvConvert('csv-command-review');
        $pending = app(BinanceApiCsvReconciliationService::class)->reconcileTransaction($csv)['reconciliation'];
        $other = User::factory()->create(['email_verified_at' => now()]);

        $this->assertSame(1, Artisan::call('binance:review-reconciliation', [
            'reconciliation_id' => $pending->id,
            'decision' => 'confirm',
            'actor_user_id' => $other->id,
        ]));

        $this->assertSame(0, Artisan::call('binance:review-reconciliation', [
            'reconciliation_id' => $pending->id,
            'decision' => 'confirm',
            'actor_user_id' => $this->user->id,
        ]));
        $this->assertSame(0, Artisan::call('binance:review-reconciliation', [
            'reconciliation_id' => $pending->id,
            'decision' => 'revoke',
            'actor_user_id' => $this->user->id,
        ]));
        $this->assertSame(1, Artisan::call('binance:review-reconciliation', [
            'reconciliation_id' => 999999,
            'decision' => 'confirm',
            'actor_user_id' => $this->user->id,
        ]));

        $this->apiConvert('api-command-reject');
        $rejectCsv = $this->csvConvert('csv-command-reject');
        $rejectPending = app(BinanceApiCsvReconciliationService::class)->reconcileTransaction($rejectCsv)['reconciliation'];
        $this->assertSame(0, Artisan::call('binance:review-reconciliation', [
            'reconciliation_id' => $rejectPending->id,
            'decision' => 'reject',
            'actor_user_id' => $this->user->id,
        ]));
        $this->assertSame(1, Artisan::call('binance:review-reconciliation', [
            'reconciliation_id' => $rejectPending->id,
            'decision' => 'confirm',
            'actor_user_id' => $this->user->id,
        ]));
        $this->assertDatabaseCount('transaction_reconciliation_events', 3);
    }

    private function apiConvert(string $reference, array $attributes = []): Transaction
    {
        return $this->transaction('convert', 'USDT', '400', '1MBABYDOGE', '122216.76', '2025-01-02 08:48:38', '2518.58', [
            'reference' => $reference,
            'import_origin' => 'binance_api',
            'pricing_status' => 'completed',
            'to_cost_status' => FifoInventoryGap::COST_ESTIMATED,
            'to_cost_evidence_type' => 'historical_market_quote',
            ...$attributes,
        ]);
    }

    private function importAnnualCsvLine(string $line): void
    {
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
        $csv = implode("\n", [
            'id,datetime_tz_GMT-03:00,type,label,market_model_type,order_type,sent_amount,sent_currency,sent_value_BRL,sent_address,received_amount,received_currency,received_value_BRL,received_address,fee_amount,fee_currency,fee_value_BRL',
            $line,
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
    }

    private function csvConvert(string $reference, array $attributes = []): Transaction
    {
        return $this->transaction('convert', 'USDT', '400', '1MBABYDOGE', '122216.76', '2025-01-02 08:48:38', '2518.58', array_merge([
            'reference' => $reference,
            'import_origin' => 'binance_annual_csv',
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
