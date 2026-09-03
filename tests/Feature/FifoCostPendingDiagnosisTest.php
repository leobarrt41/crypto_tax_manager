<?php

namespace Tests\Feature;

use App\Models\FifoInventoryGap;
use App\Models\Transaction;
use App\Models\User;
use App\Services\FifoCostPendingDiagnosisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FifoCostPendingDiagnosisTest extends TestCase
{
    use RefreshDatabase;

    public function test_known_documented_convert_is_not_included_without_a_cost_gap(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $this->source($user, 'convert', 'XRP', '2026-01-01 12:00:00', [
            'to_cost_status' => FifoInventoryGap::COST_KNOWN,
            'to_cost_evidence_type' => 'binance_annual_csv_received_value_brl',
            'import_metadata' => $this->annualMetadata('250.10'),
        ]);

        $response = $this->diagnose($user);

        $response->assertOk()->assertJsonPath('total', 0);
    }

    public function test_documented_convert_still_pending_is_high_confidence_residual_bug_without_mutation(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $source = $this->source($user, 'convert', 'XRP', '2026-01-01 12:00:00', [
            'to_cost_status' => FifoInventoryGap::COST_PENDING,
            'import_metadata' => $this->annualMetadata('250.10'),
        ]);
        $original = $source->fresh()->getAttributes();
        $this->gap($user, $source, 'XRP', '123.456789012');

        $this->diagnose($user)
            ->assertOk()
            ->assertJsonPath('diagnoses.0.primary_category', 'convert_documented_value_not_recognized')
            ->assertJsonPath('diagnoses.0.confidence', 'high')
            ->assertJsonPath('diagnoses.0.historical_quote_is_documentary', false)
            ->assertJsonPath('diagnoses.0.documented_value_available', true)
            ->assertJsonPath('diagnoses.0.cost_evidence_kind', 'binance_annual_csv_received_value_brl')
            ->assertJsonPath('diagnoses.0.pending_quantity', '123.456789012000');

        $this->assertSame($original, $source->fresh()->getAttributes());
    }

    public function test_convert_with_only_historical_quote_remains_estimated_and_not_documentary(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $source = $this->source($user, 'convert', 'SOL', '2026-01-01 12:00:00', [
            'total_brl' => '999.1234567890',
            'pricing_status' => 'completed',
            'to_cost_status' => FifoInventoryGap::COST_ESTIMATED,
            'to_cost_evidence_type' => 'historical_market_quote',
        ]);
        $this->gap($user, $source, 'SOL', '1.250000000000');

        $this->diagnose($user)
            ->assertJsonPath('diagnoses.0.primary_category', 'historical_quote_only_estimated')
            ->assertJsonPath('diagnoses.0.secondary_category', 'convert_missing_documented_received_value')
            ->assertJsonPath('diagnoses.0.historical_quote_available', true)
            ->assertJsonPath('diagnoses.0.historical_quote_is_documentary', false)
            ->assertJsonPath('diagnoses.0.documented_value_available', false)
            ->assertJsonPath('diagnoses.0.cost_evidence_kind', 'historical_market_quote');

        $this->assertSame(FifoInventoryGap::COST_ESTIMATED, $source->fresh()->to_cost_status);
    }

    public function test_reward_is_classified_from_recorded_type_without_inferring_alpha(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $source = $this->source($user, 'asset_dividend', 'GAIX', '2026-01-01 12:00:00');
        $this->gap($user, $source, 'GAIX', '10');

        $this->diagnose($user)
            ->assertJsonPath('diagnoses.0.primary_category', 'reward_or_distribution_missing_cost')
            ->assertJsonPath('diagnoses.0.confidence', 'high')
            ->assertJsonMissing(['Binance Alpha']);
    }

    public function test_external_deposit_is_classified_separately(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $source = $this->source($user, 'deposit', 'BTC', '2026-01-01 12:00:00');
        $this->gap($user, $source, 'BTC', '0.123456789012');

        $this->diagnose($user)
            ->assertJsonPath('diagnoses.0.primary_category', 'external_deposit_missing_cost')
            ->assertJsonPath('diagnoses.0.requires_csv_or_statement', true)
            ->assertJsonPath('diagnoses.0.requires_binance_future_search', true);
    }

    public function test_purchase_without_brl_value_is_classified_and_filters_are_applied(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $btc = $this->source($user, 'buy', 'BTC', '2026-01-01 12:00:00');
        $eth = $this->source($user, 'reward', 'ETH', '2026-01-02 12:00:00');
        $this->gap($user, $btc, 'BTC', '0.1');
        $this->gap($user, $eth, 'ETH', '2');

        $this->actingAs($user)->getJson(route('reports.relatorio-ir.cost-pending-diagnosis.data', [
            'year' => 2026,
            'asset' => 'btc',
            'category' => 'acquisition_missing_brl_value',
        ]))
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('diagnoses.0.asset', 'BTC')
            ->assertJsonPath('diagnoses.0.primary_category', 'acquisition_missing_brl_value');
    }

    public function test_prior_lot_before_first_import_is_classified_as_unknown_history(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $affected = $this->affected($user, 'BTC', '2026-01-02 12:00:00');
        $this->gapFromLot($user, $affected, 'BTC', '1', '2021-12-31 23:00:00');

        $this->diagnose($user)
            ->assertJsonPath('diagnoses.0.primary_category', 'pre_import_history_unknown')
            ->assertJsonPath('diagnoses.0.confidence', 'medium');
    }

    public function test_unlinked_internal_transfer_uses_reconciliation_evidence(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $source = $this->source($user, 'deposit', 'ETH', '2026-01-01 12:00:00', [
            'reconciliation_status' => 'pending_transfer_reconciliation',
        ]);
        $this->gap($user, $source, 'ETH', '2');

        $this->diagnose($user)
            ->assertJsonPath('diagnoses.0.primary_category', 'possible_internal_transfer_unlinked')
            ->assertJsonPath('diagnoses.0.confidence', 'medium');
    }

    public function test_diagnosis_is_repeatable_read_only_and_isolated_by_user(): void
    {
        $owner = User::factory()->create(['email_verified_at' => now()]);
        $other = User::factory()->create(['email_verified_at' => now()]);
        $ownerSource = $this->source($owner, 'reward', 'DENT', '2026-01-01 12:00:00');
        $otherSource = $this->source($other, 'deposit', 'BTC', '2026-01-01 12:00:00');
        $this->gap($owner, $ownerSource, 'DENT', '139.412362351910');
        $this->gap($other, $otherSource, 'BTC', '1');
        $transactionsBefore = Transaction::query()->count();
        $gapsBefore = FifoInventoryGap::query()->count();

        $first = app(FifoCostPendingDiagnosisService::class)->forUser($owner, 2026);
        $second = app(FifoCostPendingDiagnosisService::class)->forUser($owner, 2026);

        unset($first['computed_at'], $second['computed_at']);
        $this->assertSame($first, $second);
        $this->assertSame(1, $first['total']);
        $this->assertSame($owner->id, $first['diagnoses'][0]['user_id']);
        $this->assertSame($transactionsBefore, Transaction::query()->count());
        $this->assertSame($gapsBefore, FifoInventoryGap::query()->count());
    }

    public function test_diagnosis_does_not_change_fiscal_report_blocking(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $source = $this->source($user, 'reward', 'FHE', '2026-01-01 12:00:00');
        $this->gap($user, $source, 'FHE', '5');

        $this->actingAs($user)
            ->get(route('reports.relatorio-ir.export-csv', ['year' => 2026]))
            ->assertStatus(422);
        $this->diagnose($user)->assertOk();
        $this->actingAs($user)
            ->get(route('reports.relatorio-ir.export-csv', ['year' => 2026]))
            ->assertStatus(422);
    }

    private function diagnose(User $user)
    {
        return $this->actingAs($user)->getJson(route('reports.relatorio-ir.cost-pending-diagnosis.data', [
            'year' => 2026,
        ]));
    }

    private function source(User $user, string $type, string $asset, string $date, array $attributes = []): Transaction
    {
        return Transaction::query()->create(array_merge([
            'user_id' => $user->id,
            'source_type' => User::class,
            'source_id' => $user->id,
            'type' => $type,
            'operation' => 'entrada',
            'from_asset' => $type === 'convert' ? 'USDT' : null,
            'from_amount' => $type === 'convert' ? '100' : null,
            'to_asset' => $asset,
            'to_amount' => '10',
            'date' => $date,
        ], $attributes));
    }

    private function affected(User $user, string $asset, string $date): Transaction
    {
        return Transaction::query()->create([
            'user_id' => $user->id,
            'source_type' => User::class,
            'source_id' => $user->id,
            'type' => 'sell',
            'operation' => 'saida',
            'from_asset' => $asset,
            'from_amount' => '1',
            'to_asset' => 'BRL',
            'to_amount' => '100',
            'total_brl' => '100',
            'date' => $date,
        ]);
    }

    private function gap(User $user, Transaction $source, string $asset, string $quantity): FifoInventoryGap
    {
        $affected = $this->affected($user, $asset, $source->date->copy()->addHour()->toDateTimeString());

        return $this->gapFromLot($user, $affected, $asset, $quantity, $source->date->toDateTimeString());
    }

    private function gapFromLot(User $user, Transaction $affected, string $asset, string $quantity, string $lotDate): FifoInventoryGap
    {
        return FifoInventoryGap::query()->create([
            'user_id' => $user->id,
            'transaction_id' => $affected->id,
            'asset' => $asset,
            'required_quantity' => $quantity,
            'available_quantity' => $quantity,
            'missing_quantity' => '0',
            'pending_cost_quantity' => $quantity,
            'occurred_at' => $affected->date,
            'status' => FifoInventoryGap::STATUS_OPEN,
            'quantity_status' => FifoInventoryGap::QUANTITY_COMPLETE,
            'cost_status' => FifoInventoryGap::COST_PENDING,
            'reason' => 'pending_acquisition_cost',
            'consumed_lots' => [[
                'lot_date' => $lotDate,
                'lot_qty' => $quantity,
                'lot_cost_brl' => null,
                'cost_status' => FifoInventoryGap::COST_PENDING,
                'lot_source' => 'transaction',
            ]],
        ]);
    }

    private function annualMetadata(string $receivedValue): array
    {
        return [
            'format' => 'binance_annual_csv',
            'brl_values' => [
                'received_value_brl' => $receivedValue,
                'selected_source' => 'received_value_brl',
            ],
        ];
    }
}
