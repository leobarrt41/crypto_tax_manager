<?php

namespace Tests\Feature;

use App\Models\FifoInventoryGap;
use App\Models\Transaction;
use App\Models\User;
use App\Services\FifoCalculatorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FifoInventoryGapTest extends TestCase
{
    use RefreshDatabase;

    public function test_complete_acquisition_history_does_not_create_a_fifo_gap(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $this->transaction($user, 'buy', 'BRL', 1000, 'BTC', 0.1, '2022-01-01 09:00:00', 1000);
        $sale = $this->transaction($user, 'sell', 'BTC', 0.1, 'BRL', 1500, '2022-01-02 09:00:00', 1500);

        app(FifoCalculatorService::class)->recalculateForUser($user->id);

        $this->assertDatabaseCount('fifo_inventory_gaps', 0);
        $this->assertSame('complete', $sale->fresh()->fifo_status);
        $this->assertSame(1000.0, (float) $sale->fresh()->cost_basis_brl);
        $this->assertSame(500.0, (float) $sale->fresh()->profit_loss_brl);
    }

    public function test_sale_without_sufficient_history_creates_a_single_precise_gap_and_no_zero_cost(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $this->transaction($user, 'buy', 'BRL', 500, 'BTC', 0.05, '2022-01-01 09:00:00', 500);
        $sale = $this->transaction($user, 'sell', 'BTC', 0.10, 'BRL', 1500, '2022-01-02 09:00:00', 1500);

        $stats = app(FifoCalculatorService::class)->recalculateForUser($user->id);
        $gap = FifoInventoryGap::query()->sole();

        $this->assertSame(1, $stats['fifo_gaps_open']);
        $this->assertSame($user->id, $gap->user_id);
        $this->assertSame($sale->id, $gap->transaction_id);
        $this->assertSame('BTC', $gap->asset);
        $this->assertSame('0.100000000000', $gap->required_quantity);
        $this->assertSame('0.050000000000', $gap->available_quantity);
        $this->assertSame('0.050000000000', $gap->missing_quantity);
        $this->assertSame(FifoInventoryGap::STATUS_OPEN, $gap->status);
        $this->assertSame('insufficient_acquisition_history', $gap->reason);
        $this->assertSame('incomplete', $sale->fresh()->fifo_status);
        $this->assertFalse((bool) $sale->fresh()->fifo_processed);
        $this->assertNull($sale->fresh()->cost_basis_brl);
        $this->assertNull($sale->fresh()->profit_loss_brl);
        $this->assertCount(1, json_decode((string) $sale->fresh()->fifo_lots, true, 512, JSON_THROW_ON_ERROR));

        app(FifoCalculatorService::class)->recalculateForUser($user->id);
        $this->assertDatabaseCount('fifo_inventory_gaps', 1);
        $this->assertSame(FifoInventoryGap::STATUS_OPEN, $gap->fresh()->status);
    }

    public function test_confirmed_quantity_without_cost_partially_reduces_inventory_gap_without_creating_zero_cost(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $this->transaction($user, 'asset_dividend', null, null, 'DENT', 100, '2022-01-01 09:00:00', null, [
            'cost_status' => FifoInventoryGap::COST_PENDING,
            'quantity_status' => FifoInventoryGap::QUANTITY_COMPLETE,
        ]);
        $sale = $this->transaction($user, 'sell', 'DENT', 139, 'BRL', 1500, '2022-01-02 09:00:00', 1500);

        app(FifoCalculatorService::class)->recalculateForUser($user->id);
        $gap = FifoInventoryGap::query()->sole();

        $this->assertSame(FifoInventoryGap::QUANTITY_INCOMPLETE, $gap->quantity_status);
        $this->assertSame(FifoInventoryGap::COST_PENDING, $gap->cost_status);
        $this->assertSame(39.0, (float) $gap->missing_quantity);
        $this->assertSame(100.0, (float) $gap->pending_cost_quantity);
        $this->assertSame('insufficient_quantity_and_pending_cost', $gap->reason);
        $this->assertSame('incomplete', $sale->fresh()->fifo_status);
        $this->assertSame(FifoInventoryGap::COST_PENDING, $sale->fresh()->cost_status);
        $this->assertNull($sale->fresh()->cost_basis_brl);
        $this->assertNull($sale->fresh()->profit_loss_brl);
    }

    public function test_confirmed_quantity_without_cost_keeps_export_blocked_even_when_quantity_is_fully_covered(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $this->transaction($user, 'asset_dividend', null, null, 'DENT', 139, '2022-01-01 09:00:00', null, [
            'cost_status' => FifoInventoryGap::COST_PENDING,
            'quantity_status' => FifoInventoryGap::QUANTITY_COMPLETE,
        ]);
        $sale = $this->transaction($user, 'sell', 'DENT', 139, 'BRL', 1500, '2022-01-02 09:00:00', 1500);

        app(FifoCalculatorService::class)->recalculateForUser($user->id);
        $gap = FifoInventoryGap::query()->sole();

        $this->assertSame(FifoInventoryGap::QUANTITY_COMPLETE, $gap->quantity_status);
        $this->assertSame(FifoInventoryGap::COST_PENDING, $gap->cost_status);
        $this->assertSame(0.0, (float) $gap->missing_quantity);
        $this->assertSame(139.0, (float) $gap->pending_cost_quantity);
        $this->assertSame('pending_acquisition_cost', $gap->reason);
        $this->assertNull($sale->fresh()->cost_basis_brl);
        $this->assertNull($sale->fresh()->profit_loss_brl);

        $this->actingAs($user)
            ->get(route('reports.relatorio-ir.export-csv', ['year' => 2022]))
            ->assertStatus(422)
            ->assertJsonPath('fifo_status', 'incomplete');
    }

    public function test_convert_keeps_received_acquisition_cost_independent_from_pending_disposal_cost(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $this->transaction($user, 'asset_dividend', null, null, 'DENT', 100, '2022-01-01 09:00:00', null, [
            'cost_status' => FifoInventoryGap::COST_PENDING,
            'quantity_status' => FifoInventoryGap::QUANTITY_COMPLETE,
        ]);
        $convert = $this->transaction($user, 'convert', 'DENT', 100, 'XRP', 50, '2022-01-02 09:00:00', 300, [
            'import_metadata' => [
                'brl_values' => [
                    'sent_value_brl' => 300,
                    'received_value_brl' => 320,
                    'selected_source' => 'sent_value_brl',
                ],
            ],
        ]);
        $sale = $this->transaction($user, 'sell', 'XRP', 50, 'BRL', 500, '2022-01-03 09:00:00', 500);

        app(FifoCalculatorService::class)->recalculateForUser($user->id);

        $this->assertSame(FifoInventoryGap::COST_PENDING, $convert->fresh()->from_cost_status);
        $this->assertSame(FifoInventoryGap::COST_KNOWN, $convert->fresh()->to_cost_status);
        $this->assertSame('binance_annual_csv_received_value_brl', $convert->fresh()->to_cost_evidence_type);
        $this->assertSame(320.0, (float) $convert->fresh()->to_cost_basis_brl);
        $this->assertNull($convert->fresh()->cost_status);
        $this->assertSame('complete', $sale->fresh()->fifo_status);
        $this->assertSame(320.0, (float) $sale->fresh()->cost_basis_brl);
        $this->assertSame(180.0, (float) $sale->fresh()->profit_loss_brl);
        $this->assertDatabaseCount('fifo_inventory_gaps', 1);
        $this->assertSame($convert->id, FifoInventoryGap::query()->sole()->transaction_id);
    }

    public function test_convert_market_quote_is_not_promoted_to_documented_acquisition_cost(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $this->transaction($user, 'buy', 'BRL', 200, 'DENT', 100, '2022-01-01 09:00:00', 200);
        $convert = $this->transaction($user, 'convert', 'DENT', 100, 'XRP', 50, '2022-01-02 09:00:00', 300, [
            'pricing_status' => 'completed',
        ]);
        $sale = $this->transaction($user, 'sell', 'XRP', 50, 'BRL', 500, '2022-01-03 09:00:00', 500);

        app(FifoCalculatorService::class)->recalculateForUser($user->id);

        $this->assertSame(FifoInventoryGap::COST_KNOWN, $convert->fresh()->from_cost_status);
        $this->assertSame(FifoInventoryGap::COST_ESTIMATED, $convert->fresh()->to_cost_status);
        $this->assertSame('historical_market_quote', $convert->fresh()->to_cost_evidence_type);
        $gap = FifoInventoryGap::query()->sole();
        $this->assertSame($sale->id, $gap->transaction_id);
        $this->assertSame(FifoInventoryGap::QUANTITY_COMPLETE, $gap->quantity_status);
        $this->assertSame(FifoInventoryGap::COST_PENDING, $gap->cost_status);
        $this->assertNull($sale->fresh()->cost_basis_brl);
        $this->assertNull($sale->fresh()->profit_loss_brl);
    }

    public function test_credit_after_sale_does_not_resolve_the_prior_fifo_gap(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $sale = $this->transaction($user, 'sell', 'FHE', 10, 'BRL', 500, '2022-01-02 09:00:00', 500);
        $fifo = app(FifoCalculatorService::class);
        $fifo->recalculateForUser($user->id);
        $gap = FifoInventoryGap::query()->sole();

        $this->transaction($user, 'asset_dividend', null, null, 'FHE', 10, '2022-01-03 09:00:00', null, [
            'cost_status' => FifoInventoryGap::COST_PENDING,
            'quantity_status' => FifoInventoryGap::QUANTITY_COMPLETE,
        ]);
        $fifo->recalculateForUser($user->id);

        $this->assertSame(FifoInventoryGap::STATUS_OPEN, $gap->fresh()->status);
        $this->assertSame(10.0, (float) $gap->fresh()->missing_quantity);
        $this->assertSame('incomplete', $sale->fresh()->fifo_status);
    }

    public function test_new_prior_acquisition_resolves_existing_gap_on_recalculation(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $sale = $this->transaction($user, 'sell', 'BTC', 0.1, 'BRL', 1200, '2022-01-03 09:00:00', 1200);
        $fifo = app(FifoCalculatorService::class);
        $fifo->recalculateForUser($user->id);
        $gap = FifoInventoryGap::query()->sole();

        $this->transaction($user, 'buy', 'BRL', 900, 'BTC', 0.1, '2022-01-01 09:00:00', 900);
        $stats = $fifo->recalculateForUser($user->id);

        $this->assertSame(1, $stats['fifo_gaps_resolved']);
        $this->assertSame(FifoInventoryGap::STATUS_RESOLVED, $gap->fresh()->status);
        $this->assertNotNull($gap->fresh()->resolved_at);
        $this->assertSame('complete', $sale->fresh()->fifo_status);
        $this->assertSame(900.0, (float) $sale->fresh()->cost_basis_brl);
        $this->assertSame(300.0, (float) $sale->fresh()->profit_loss_brl);
    }

    public function test_acquisition_history_endpoint_is_partial_and_export_is_blocked_only_for_affected_period(): void
    {
        $owner = User::factory()->create(['email_verified_at' => now()]);
        $other = User::factory()->create(['email_verified_at' => now()]);
        $this->transaction($owner, 'sell', 'ETH', 1, 'BRL', 10000, '2022-03-05 09:00:00', 10000);
        app(FifoCalculatorService::class)->recalculateForUser($owner->id);

        $this->actingAs($owner)
            ->getJson(route('reports.relatorio-ir.acquisition-history', ['year' => 2022]))
            ->assertOk()
            ->assertJsonPath('status', 'incomplete')
            ->assertJsonPath('is_official_export_available', false)
            ->assertJsonPath('open_gaps_count', 1)
            ->assertJsonPath('quantity_missing_count', 1)
            ->assertJsonPath('cost_pending_count', 0)
            ->assertJsonPath('gaps.0.asset', 'ETH')
            ->assertJsonPath('gaps.0.transaction.id', 1);

        $this->actingAs($owner)
            ->get(route('reports.relatorio-ir.export-csv', ['year' => 2022]))
            ->assertStatus(422)
            ->assertJsonPath('fifo_status', 'incomplete')
            ->assertJsonPath('open_gaps_count', 1);

        $this->actingAs($owner)
            ->get(route('reports.relatorio-ir.export-csv', ['year' => 2023]))
            ->assertOk();

        $this->actingAs($other)
            ->getJson(route('reports.relatorio-ir.acquisition-history', ['year' => 2022]))
            ->assertOk()
            ->assertJsonPath('status', 'complete')
            ->assertJsonPath('open_gaps_count', 0);
    }

    public function test_acquisition_history_counts_missing_quantity_and_pending_cost_separately(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $this->transaction($user, 'sell', 'ETH', 1, 'BRL', 10000, '2022-03-05 09:00:00', 10000);
        $this->transaction($user, 'asset_dividend', null, null, 'DENT', 10, '2022-03-01 09:00:00', null, [
            'cost_status' => FifoInventoryGap::COST_PENDING,
            'quantity_status' => FifoInventoryGap::QUANTITY_COMPLETE,
        ]);
        $this->transaction($user, 'sell', 'DENT', 10, 'BRL', 300, '2022-03-06 09:00:00', 300);

        app(FifoCalculatorService::class)->recalculateForUser($user->id);

        $this->actingAs($user)
            ->getJson(route('reports.relatorio-ir.acquisition-history', ['year' => 2022]))
            ->assertOk()
            ->assertJsonPath('open_gaps_count', 2)
            ->assertJsonPath('quantity_missing_count', 1)
            ->assertJsonPath('cost_pending_count', 1);

        $this->actingAs($user)
            ->get(route('reports.relatorio-ir.export-csv', ['year' => 2022]))
            ->assertStatus(422)
            ->assertJsonPath('quantity_missing_count', 1)
            ->assertJsonPath('cost_pending_count', 1);
    }

    public function test_manual_history_correction_requires_confirmation_when_an_acquisition_already_exists(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $this->transaction($user, 'buy', 'BRL', 1000, 'BTC', 0.1, '2021-12-30 09:00:00', 1000);
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);

        $payload = [
            'fiscal_year' => 2022,
            'asset' => 'BTC',
            'quantity' => 0.1,
            'total_cost_brl' => 1000,
            'source' => 'Documento sintético',
        ];

        $this->actingAs($user)
            ->postJson(route('reports.relatorio-ir.opening-balances.store'), $payload)
            ->assertStatus(422)
            ->assertJsonPath('requires_manual_confirmation', true);
        $this->assertDatabaseCount('fifo_opening_balances', 0);

        $this->actingAs($user)
            ->postJson(route('reports.relatorio-ir.opening-balances.store'), [
                ...$payload,
                'confirm_manual_correction' => true,
            ])
            ->assertOk()
            ->assertJsonPath('success', true);
        $this->assertDatabaseHas('fifo_opening_balances', ['user_id' => $user->id, 'asset' => 'BTC']);
    }

    private function transaction(
        User $user,
        string $type,
        ?string $fromAsset,
        ?float $fromAmount,
        ?string $toAsset,
        ?float $toAmount,
        string $date,
        ?float $totalBrl,
        array $attributes = [],
    ): Transaction {
        return Transaction::query()->create(array_merge([
            'user_id' => $user->id,
            'source_type' => User::class,
            'source_id' => $user->id,
            'from_asset' => $fromAsset,
            'from_amount' => $fromAmount,
            'to_asset' => $toAsset,
            'to_amount' => $toAmount,
            'type' => $type,
            'operation' => in_array($type, ['sell', 'withdrawal', 'send'], true) ? 'saida' : 'entrada',
            'total_brl' => $totalBrl,
            'date' => $date,
        ], $attributes));
    }
}
