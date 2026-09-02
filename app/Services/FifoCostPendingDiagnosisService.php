<?php

namespace App\Services;

use App\Models\FifoInventoryGap;
use App\Models\Transaction;
use App\Models\User;
use App\Support\DecimalMath;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class FifoCostPendingDiagnosisService
{
    public const CLASSIFIER_VERSION = 'fifo-cost-pending-v1';

    private const CONVERT_TYPES = ['convert', 'trade', 'swap'];

    private const REWARD_TYPES = ['asset_dividend', 'airdrop', 'distribution', 'reward', 'earn', 'staking', 'mining'];

    private const ACQUISITION_TYPES = ['buy', 'fiat_buy'];

    public function __construct(private readonly DecimalMath $decimal) {}

    /** @return array<string, mixed> */
    public function forUser(User $user, int $year, array $filters = []): array
    {
        $status = $filters['status'] ?? FifoInventoryGap::STATUS_OPEN;
        $gaps = FifoInventoryGap::query()
            ->where('user_id', $user->id)
            ->whereYear('occurred_at', $year)
            ->where('status', $status)
            ->where('cost_status', '!=', FifoInventoryGap::COST_KNOWN)
            ->where('pending_cost_quantity', '>', 0)
            ->with('transaction')
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();

        $diagnoses = $gaps
            ->map(fn (FifoInventoryGap $gap): array => $this->diagnose($gap))
            ->when(
                ! empty($filters['asset']),
                fn (Collection $items): Collection => $items->where('asset', strtoupper((string) $filters['asset'])),
            )
            ->when(
                ! empty($filters['category']),
                fn (Collection $items): Collection => $items->where('primary_category', $filters['category']),
            )
            ->values();

        return [
            'classifier_version' => self::CLASSIFIER_VERSION,
            'computed_at' => now('UTC')->toIso8601String(),
            'year' => $year,
            'filters' => [
                'asset' => $filters['asset'] ?? null,
                'category' => $filters['category'] ?? null,
                'status' => $status,
            ],
            'total' => $diagnoses->count(),
            'counts_by_category' => $diagnoses
                ->countBy('primary_category')
                ->sortKeys()
                ->all(),
            'diagnoses' => $diagnoses->all(),
            'notices' => [
                'Nenhum custo é preenchido ou tratado como zero por este diagnóstico.',
                'Cotação histórica estimada não equivale a custo documental confirmado.',
                'Esta análise auxilia a conciliação, mas não substitui contador ou tributarista.',
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function diagnose(FifoInventoryGap $gap): array
    {
        $gap->loadMissing('transaction');
        $source = $this->locateSourceTransaction($gap);
        $classification = $this->classify($gap, $source);

        return [
            'gap_id' => $gap->id,
            'user_id' => $gap->user_id,
            'asset' => strtoupper($gap->asset),
            'pending_quantity' => (string) $gap->pending_cost_quantity,
            'occurred_at_utc' => $gap->occurred_at?->copy()->utc()->toIso8601String(),
            'affected_transaction_id' => $gap->transaction_id,
            'source_transaction_id' => $source?->id,
            'source_lot_reference' => $classification['source_lot_reference'],
            'primary_category' => $classification['category'],
            'secondary_category' => $classification['secondary_category'],
            'confidence' => $classification['confidence'],
            'classifier_version' => self::CLASSIFIER_VERSION,
            'evidence' => $classification['evidence'],
            'missing_fields' => $classification['missing_fields'],
            'recommended_action' => $classification['recommended_action'],
            'requires_csv_or_statement' => $classification['requires_csv_or_statement'],
            'requires_binance_future_search' => $classification['requires_binance_future_search'],
            'requires_manual_confirmation' => $classification['requires_manual_confirmation'],
            'historical_quote_available' => $classification['historical_quote_available'],
            'historical_quote_is_documentary' => $classification['historical_quote_is_documentary'],
            'blocks_fiscal_report' => $gap->status === FifoInventoryGap::STATUS_OPEN,
            'explanation_for_user' => $classification['explanation'],
        ];
    }

    private function locateSourceTransaction(FifoInventoryGap $gap): ?Transaction
    {
        $pendingLots = collect($gap->consumed_lots ?? [])
            ->filter(fn (array $lot): bool => ($lot['cost_status'] ?? null) !== FifoInventoryGap::COST_KNOWN);

        foreach ($pendingLots as $lot) {
            if (($lot['lot_source'] ?? null) !== 'transaction' || empty($lot['lot_date'])) {
                continue;
            }

            $date = CarbonImmutable::parse($lot['lot_date'])->utc();
            $source = Transaction::query()
                ->where('user_id', $gap->user_id)
                ->where('id', '!=', $gap->transaction_id)
                ->where('date', $date)
                ->where(function ($query) use ($gap): void {
                    $query->where('to_asset', strtoupper($gap->asset))
                        ->orWhere(function ($nested) use ($gap): void {
                            $nested->whereNull('to_asset')->where('from_asset', strtoupper($gap->asset));
                        });
                })
                ->orderBy('id')
                ->first();

            if ($source !== null) {
                return $source;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function classify(FifoInventoryGap $gap, ?Transaction $source): array
    {
        $base = $this->baseClassification($gap, $source);

        if ($source !== null && in_array(strtolower((string) $source->type), self::CONVERT_TYPES, true)) {
            return $this->classifyConvert($source, $base);
        }

        if ($source !== null && in_array(strtolower((string) $source->type), self::REWARD_TYPES, true)) {
            return array_replace($base, [
                'category' => 'reward_or_distribution_missing_cost',
                'confidence' => 'high',
                'recommended_action' => 'Apresente o extrato ou documento que informe o valor da recompensa em reais na data do recebimento.',
                'requires_csv_or_statement' => true,
                'missing_fields' => ['documented_acquisition_cost_brl'],
                'explanation' => 'A quantidade recebida como recompensa ou distribuição foi encontrada, mas não há custo documental em reais. O tipo veio do registro importado; nenhum programa Binance foi inferido pelo símbolo.',
            ]);
        }

        if ($source !== null && $source->reconciliation_status === 'pending_transfer_reconciliation') {
            return array_replace($base, [
                'category' => 'possible_internal_transfer_unlinked',
                'confidence' => 'medium',
                'recommended_action' => 'Concilie este crédito com a retirada correspondente e preserve o custo histórico da carteira de origem.',
                'requires_csv_or_statement' => true,
                'missing_fields' => ['linked_transfer_transaction_id', 'carried_cost_basis_brl'],
                'explanation' => 'O registro é unilateral e está pendente de conciliação. Ele pode ser uma transferência entre contas próprias, mas isso precisa ser comprovado pelo vínculo com a outra ponta.',
            ]);
        }

        if ($source !== null && in_array(strtolower((string) $source->type), ['deposit', 'receive'], true)) {
            return array_replace($base, [
                'category' => 'external_deposit_missing_cost',
                'confidence' => 'high',
                'recommended_action' => 'Informe o extrato da origem do depósito para transportar o custo histórico ou documentar a natureza do recebimento.',
                'requires_csv_or_statement' => true,
                'requires_binance_future_search' => true,
                'missing_fields' => ['origin_statement', 'documented_acquisition_cost_brl'],
                'explanation' => 'A quantidade entrou por depósito externo, mas o depósito sozinho não informa quando nem por quanto o ativo foi adquirido.',
            ]);
        }

        if ($source !== null && in_array(strtolower((string) $source->type), self::ACQUISITION_TYPES, true)) {
            return array_replace($base, [
                'category' => 'acquisition_missing_brl_value',
                'confidence' => 'high',
                'recommended_action' => 'Revise o CSV ou comprovante da compra e informe o valor total da aquisição em BRL.',
                'requires_csv_or_statement' => true,
                'missing_fields' => ['documented_acquisition_cost_brl'],
                'explanation' => 'A compra foi localizada, mas o registro não contém valor de aquisição válido em reais.',
            ]);
        }

        if ($source === null && $this->hasEvidenceBeforeImportedHistory($gap)) {
            return array_replace($base, [
                'category' => 'pre_import_history_unknown',
                'confidence' => 'medium',
                'recommended_action' => 'Importe o CSV ou extrato do período anterior à primeira transação atualmente registrada.',
                'requires_csv_or_statement' => true,
                'missing_fields' => ['source_transaction', 'prior_import_period'],
                'explanation' => 'O lote indicado é anterior ao primeiro registro importado disponível. É necessário completar o histórico anterior.',
            ]);
        }

        return array_replace($base, [
            'category' => $source === null ? 'unclassified' : 'unsupported_or_insufficient_evidence',
            'confidence' => 'low',
            'recommended_action' => 'Revise a transação e os documentos de origem; os campos atuais não permitem confirmar o custo com segurança.',
            'requires_csv_or_statement' => true,
            'requires_manual_confirmation' => true,
            'missing_fields' => ['documented_acquisition_cost_brl', ...($source === null ? ['source_transaction'] : [])],
            'explanation' => 'A quantidade foi localizada, mas as evidências disponíveis não permitem determinar com segurança a origem e o custo documental.',
        ]);
    }

    /** @return array<string, mixed> */
    private function classifyConvert(Transaction $source, array $base): array
    {
        $brlValues = is_array(data_get($source->import_metadata, 'brl_values'))
            ? data_get($source->import_metadata, 'brl_values')
            : [];
        $received = $brlValues['received_value_brl'] ?? null;
        $hasDocumentedReceived = $this->isPositive($received)
            && data_get($source->import_metadata, 'format') === 'binance_annual_csv';
        $hasHistoricalQuote = $this->isPositive($source->total_brl)
            && ($source->to_cost_evidence_type === 'historical_market_quote' || $source->pricing_status === 'completed');

        $evidence = array_replace($base['evidence'], [
            'transaction_type' => $source->type,
            'import_format' => data_get($source->import_metadata, 'format'),
            'selected_brl_source' => $brlValues['selected_source'] ?? null,
            'received_value_brl_present' => $this->isPositive($received),
            'total_brl_present' => $this->isPositive($source->total_brl),
            'pricing_status' => $source->pricing_status,
            'to_cost_status' => $source->to_cost_status,
            'to_cost_evidence_type' => $source->to_cost_evidence_type,
        ]);

        if ($hasDocumentedReceived && $source->to_cost_status !== FifoInventoryGap::COST_KNOWN) {
            return array_replace($base, [
                'category' => 'convert_documented_value_not_recognized',
                'confidence' => 'high',
                'evidence' => $evidence,
                'recommended_action' => 'Encaminhe esta ocorrência para revisão da lógica: há valor recebido documental, mas ele não foi reconhecido.',
                'requires_manual_confirmation' => true,
                'missing_fields' => [],
                'historical_quote_available' => $hasHistoricalQuote,
                'historical_quote_is_documentary' => true,
                'explanation' => 'O CSV anual contém valor recebido em reais, mas o custo da perna recebida não foi marcado como conhecido. Isso é candidato a correção de lógica.',
            ]);
        }

        if (! $hasDocumentedReceived && $hasHistoricalQuote) {
            return array_replace($base, [
                'category' => 'historical_quote_only_estimated',
                'secondary_category' => 'convert_missing_documented_received_value',
                'confidence' => 'high',
                'evidence' => $evidence,
                'recommended_action' => 'Apresente o CSV anual ou comprovante do Convert com o valor recebido em BRL.',
                'requires_csv_or_statement' => true,
                'missing_fields' => ['import_metadata.brl_values.received_value_brl'],
                'historical_quote_available' => true,
                'historical_quote_is_documentary' => false,
                'explanation' => 'Encontramos uma cotação histórica estimada para a conversão, mas não um documento com o valor recebido em reais. A estimativa não será promovida automaticamente a custo fiscal conhecido.',
            ]);
        }

        return array_replace($base, [
            'category' => 'convert_missing_documented_received_value',
            'confidence' => 'high',
            'evidence' => $evidence,
            'recommended_action' => 'Apresente o CSV anual ou comprovante Binance que contenha o valor recebido em BRL.',
            'requires_csv_or_statement' => true,
            'requires_binance_future_search' => true,
            'missing_fields' => ['import_metadata.brl_values.received_value_brl', 'documented_acquisition_cost_brl'],
            'explanation' => 'A conversão e a quantidade recebida foram encontradas, mas não há valor documental de aquisição em reais.',
        ]);
    }

    /** @return array<string, mixed> */
    private function baseClassification(FifoInventoryGap $gap, ?Transaction $source): array
    {
        $pendingLot = collect($gap->consumed_lots ?? [])->first(
            fn (array $lot): bool => ($lot['cost_status'] ?? null) !== FifoInventoryGap::COST_KNOWN,
        );

        return [
            'category' => 'unclassified',
            'secondary_category' => null,
            'confidence' => 'low',
            'recommended_action' => 'Revise a documentação de origem.',
            'requires_csv_or_statement' => false,
            'requires_binance_future_search' => false,
            'requires_manual_confirmation' => false,
            'historical_quote_available' => false,
            'historical_quote_is_documentary' => false,
            'evidence' => [
                'gap_reason' => $gap->reason,
                'source_transaction_type' => $source?->type,
                'source_type' => $source?->source_type,
                'source_reference_present' => ! empty($source?->reference),
                'reconciliation_status' => $source?->reconciliation_status,
            ],
            'missing_fields' => [],
            'explanation' => '',
            'source_lot_reference' => is_array($pendingLot) ? ($pendingLot['lot_date'] ?? null) : null,
        ];
    }

    private function hasEvidenceBeforeImportedHistory(FifoInventoryGap $gap): bool
    {
        $firstImportedAt = Transaction::query()
            ->where('user_id', $gap->user_id)
            ->min('date');
        if ($firstImportedAt === null) {
            return false;
        }

        return collect($gap->consumed_lots ?? [])->contains(function (array $lot) use ($firstImportedAt): bool {
            if (empty($lot['lot_date'])) {
                return false;
            }

            return CarbonImmutable::parse($lot['lot_date'])->utc()->lt(CarbonImmutable::parse($firstImportedAt)->utc());
        });
    }

    private function isPositive(mixed $value): bool
    {
        return is_numeric($value) && $this->decimal->compare((string) $value, '0') > 0;
    }
}
