<?php

namespace Tests\Feature;

use App\Http\Controllers\TransactionController;
use App\Models\Exchange;
use App\Models\TaxMonthlySummary;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserApiKey;
use App\Services\FifoCalculatorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use ReflectionMethod;
use Tests\TestCase;

class BinanceAnnualCsvImportTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private UserApiKey $source;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['email_verified_at' => now()]);
        $exchange = Exchange::query()->create([
            'name' => 'binance',
            'country_code' => 'MT',
            'description' => 'Binance sintética para testes',
        ]);
        $this->source = UserApiKey::query()->create([
            'user_id' => $this->user->id,
            'exchange_id' => $exchange->id,
            'api_key' => 'synthetic-read-only-key',
            'secret_key' => 'synthetic-read-only-secret',
            'read_enabled' => true,
            'trading_enabled' => false,
        ]);
    }

    public function test_annual_trade_preserves_sent_brl_fee_and_auditable_brl_selection_without_http(): void
    {
        Http::fake();

        $transaction = $this->mapImportedRow([
            'id' => 'synthetic-trade-001',
            'datetime_tz_BRT' => '2022-01-15-10:30:00',
            'type' => ' Trade ',
            'market_model_type' => 'Spot',
            'sent_amount' => '2',
            'sent_currency' => 'ETH',
            'sent_value_BRL' => '10000.00',
            'received_amount' => '0.10',
            'received_currency' => 'BTC',
            'received_value_BRL' => '11000.00',
            'fee_amount' => '0.01',
            'fee_currency' => 'BNB',
            'fee_value_BRL' => '1.23',
        ]);

        $this->assertSame('trade', $transaction['type']);
        $this->assertSame('ETH', $transaction['from_asset']);
        $this->assertSame('BTC', $transaction['to_asset']);
        $this->assertSame(10000.0, $transaction['total_brl']);
        $this->assertSame('sent_value_brl', $transaction['import_metadata']['brl_values']['selected_source']);
        $this->assertSame(11000.0, $transaction['import_metadata']['brl_values']['received_value_brl']);
        $this->assertSame(0.01, $transaction['commission']);
        $this->assertSame('BNB', $transaction['commission_asset']);
        $this->assertSame(1.23, $transaction['commission_value_brl']);
        $this->assertSame('2022-01-15 10:30:00', $transaction['date']->format('Y-m-d H:i:s'));
        $this->assertSame('America/Sao_Paulo', $transaction['date']->getTimezone()->getName());
        Http::assertNothingSent();
    }

    public function test_annual_credit_and_debit_rows_keep_only_the_real_leg_and_require_reconciliation(): void
    {
        $credit = $this->mapImportedRow($this->oneSidedRow('Deposit', receivedAsset: 'BTC', receivedAmount: '0.25', receivedBrl: '2500.50'));
        $debit = $this->mapImportedRow($this->oneSidedRow(' Withdrawal ', sentAsset: 'ETH', sentAmount: '1.75', sentBrl: '8750.25'));

        $this->assertSame('deposit', $credit['type']);
        $this->assertNull($credit['from_asset']);
        $this->assertNull($credit['from_amount']);
        $this->assertSame('BTC', $credit['to_asset']);
        $this->assertSame(0.25, $credit['to_amount']);
        $this->assertSame(2500.5, $credit['total_brl']);
        $this->assertSame('received_value_brl', $credit['import_metadata']['brl_values']['selected_source']);
        $this->assertSame('pending_transfer_reconciliation', $credit['reconciliation_status']);

        $this->assertSame('withdrawal', $debit['type']);
        $this->assertSame('ETH', $debit['from_asset']);
        $this->assertSame(1.75, $debit['from_amount']);
        $this->assertNull($debit['to_asset']);
        $this->assertNull($debit['to_amount']);
        $this->assertSame(8750.25, $debit['total_brl']);
        $this->assertSame('sent_value_brl', $debit['import_metadata']['brl_values']['selected_source']);
        $this->assertSame('pending_transfer_reconciliation', $debit['reconciliation_status']);
    }

    public function test_all_supported_annual_types_and_synthetic_74_row_distribution_are_recognized(): void
    {
        $rows = [];
        foreach (array_fill(0, 49, 'Trade') as $index => $type) {
            $rows[] = $this->twoSidedRow($type, "synthetic-trade-{$index}");
        }
        foreach (array_fill(0, 9, 'Buy') as $index => $type) {
            $rows[] = $this->twoSidedRow($type, "synthetic-buy-{$index}");
        }
        $rows[] = $this->twoSidedRow('Sell', 'synthetic-sell-0');
        foreach (array_fill(0, 7, 'Deposit') as $index => $type) {
            $rows[] = $this->oneSidedRow($type, "synthetic-deposit-{$index}", receivedAsset: 'BTC', receivedAmount: '0.1', receivedBrl: '1000');
        }
        foreach (array_fill(0, 6, 'Send') as $index => $type) {
            $rows[] = $this->oneSidedRow($type, "synthetic-send-{$index}", sentAsset: 'ETH', sentAmount: '0.1', sentBrl: '1000');
        }
        $rows[] = $this->oneSidedRow('Receive', 'synthetic-receive-0', receivedAsset: 'ETH', receivedAmount: '0.1', receivedBrl: '1000');
        $rows[] = $this->oneSidedRow('Withdrawal', 'synthetic-withdrawal-0', sentAsset: 'BTC', sentAmount: '0.1', sentBrl: '1000');

        $this->assertCount(74, $rows);
        foreach ($rows as $row) {
            $this->assertNotNull($this->mapImportedRow($row), "A linha sintética {$row['id']} deve ser reconhecida.");
        }

        $convert = $this->mapImportedRow($this->twoSidedRow('Trade', 'synthetic-convert', ['market_model_type' => 'Convert']));
        $this->assertSame('convert', $convert['type']);
        $this->assertNull($this->mapImportedRow($this->oneSidedRow('Trade', 'synthetic-invalid', sentAsset: 'USDT', sentAmount: '100', sentBrl: '500')));
    }

    public function test_csv_import_is_deduplicated_by_annual_id_and_preserves_fee_brl_without_http(): void
    {
        Http::fake();
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
        $content = $this->csvContent([
            $this->oneSidedRow('Deposit', 'synthetic-dedupe-deposit', receivedAsset: 'BTC', receivedAmount: '0.20', receivedBrl: '2000', extra: [
                'fee_amount' => '0.01', 'fee_currency' => 'BNB', 'fee_value_BRL' => '2.5',
            ]),
            $this->oneSidedRow('Send', 'synthetic-dedupe-send', sentAsset: 'BTC', sentAmount: '0.05', sentBrl: '600'),
        ]);

        $this->submitCsv($content)->assertRedirect(route('transactions.index'));
        $this->assertDatabaseCount('transactions', 2);
        $this->assertDatabaseHas('transactions', [
            'reference' => 'synthetic-dedupe-deposit',
            'commission_asset' => 'BNB',
            'commission_value_brl' => 2.5,
            'reconciliation_status' => 'pending_transfer_reconciliation',
        ]);

        $this->submitCsv($content)->assertRedirect(route('transactions.index'));
        $this->assertDatabaseCount('transactions', 2);
        Http::assertNothingSent();
    }

    public function test_pending_one_sided_movements_do_not_create_fifo_cost_or_taxable_disposal_until_reconciled(): void
    {
        Transaction::query()->create([
            'user_id' => $this->user->id,
            'source_type' => UserApiKey::class,
            'source_id' => $this->source->id,
            'from_asset' => 'BRL',
            'from_amount' => 1000,
            'to_asset' => 'BTC',
            'to_amount' => 0.1,
            'total_brl' => 1000,
            'type' => 'buy',
            'date' => '2022-01-01 09:00:00',
        ]);
        $pendingDebit = Transaction::query()->create([
            'user_id' => $this->user->id,
            'source_type' => UserApiKey::class,
            'source_id' => $this->source->id,
            'from_asset' => 'BTC',
            'from_amount' => 0.05,
            'total_brl' => 600,
            'type' => 'send',
            'reconciliation_status' => 'pending_transfer_reconciliation',
            'date' => '2022-01-10 09:00:00',
        ]);
        $pendingCredit = Transaction::query()->create([
            'user_id' => $this->user->id,
            'source_type' => UserApiKey::class,
            'source_id' => $this->source->id,
            'to_asset' => 'ETH',
            'to_amount' => 1,
            'total_brl' => 5000,
            'type' => 'receive',
            'reconciliation_status' => 'pending_transfer_reconciliation',
            'date' => '2022-01-11 09:00:00',
        ]);

        app(FifoCalculatorService::class)->recalculateForUser($this->user->id);

        $this->assertNull($pendingDebit->fresh()->cost_basis_brl);
        $this->assertNull($pendingDebit->fresh()->profit_loss_brl);
        $this->assertFalse((bool) $pendingDebit->fresh()->fifo_processed);
        $this->assertNull($pendingCredit->fresh()->cost_basis_brl);
        $this->assertNull($pendingCredit->fresh()->profit_loss_brl);
        $this->assertDatabaseCount('tax_monthly_summaries', 0);
        $this->assertSame(0, TaxMonthlySummary::query()->count());
    }

    /** @return array<string, mixed> */
    private function mapImportedRow(array $row): ?array
    {
        $controller = app(TransactionController::class);
        $method = new ReflectionMethod($controller, 'mapImportedRowToTransactionData');
        $method->setAccessible(true);

        return $method->invoke($controller, $row, 'binance', UserApiKey::class, $this->source->id);
    }

    private function submitCsv(string $content)
    {
        return $this->actingAs($this->user)->post(route('transactions.import.csv'), [
            'file' => UploadedFile::fake()->createWithContent('synthetic-annual.csv', $content),
            'format' => 'binance',
            'skip_duplicates' => true,
            'source_type' => 'exchange',
            'source_id' => $this->source->id,
            'coverage_year' => 2022,
            'coverage_month' => 1,
        ]);
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function csvContent(array $rows): string
    {
        $headers = [
            'id', 'datetime_tz_BRT', 'type', 'market_model_type',
            'sent_amount', 'sent_currency', 'sent_value_BRL',
            'received_amount', 'received_currency', 'received_value_BRL',
            'fee_amount', 'fee_currency', 'fee_value_BRL',
        ];
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, $headers);
        foreach ($rows as $row) {
            fputcsv($handle, array_map(fn (string $header) => $row[$header] ?? '', $headers));
        }
        rewind($handle);
        $content = stream_get_contents($handle);
        fclose($handle);

        return $content;
    }

    /** @param array<string, mixed> $extra
     *  @return array<string, mixed>
     */
    private function twoSidedRow(string $type, string $id, array $extra = []): array
    {
        return array_merge([
            'id' => $id,
            'datetime_tz_BRT' => '2022-01-15-10:30:00',
            'type' => $type,
            'market_model_type' => 'Spot',
            'sent_amount' => '100',
            'sent_currency' => 'USDT',
            'sent_value_BRL' => '500',
            'received_amount' => '0.01',
            'received_currency' => 'BTC',
            'received_value_BRL' => '505',
            'fee_amount' => '0',
            'fee_currency' => '',
            'fee_value_BRL' => '0',
        ], $extra);
    }

    /** @param array<string, mixed> $extra
     *  @return array<string, mixed>
     */
    private function oneSidedRow(
        string $type,
        string $id = 'synthetic-one-sided',
        ?string $sentAsset = null,
        ?string $sentAmount = null,
        ?string $sentBrl = null,
        ?string $receivedAsset = null,
        ?string $receivedAmount = null,
        ?string $receivedBrl = null,
        array $extra = [],
    ): array {
        return array_merge([
            'id' => $id,
            'datetime_tz_BRT' => '2022-01-16-11:45:00',
            'type' => $type,
            'market_model_type' => 'Wallet',
            'sent_amount' => $sentAmount ?? '',
            'sent_currency' => $sentAsset ?? '',
            'sent_value_BRL' => $sentBrl ?? '',
            'received_amount' => $receivedAmount ?? '',
            'received_currency' => $receivedAsset ?? '',
            'received_value_BRL' => $receivedBrl ?? '',
            'fee_amount' => '0',
            'fee_currency' => '',
            'fee_value_BRL' => '0',
        ], $extra);
    }
}
