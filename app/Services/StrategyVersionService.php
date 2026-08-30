<?php

namespace App\Services;

use App\Models\TradingStrategy;
use App\Models\TradingStrategyVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use LogicException;

class StrategyVersionService
{
    public function __construct(
        private readonly StrategyDefinitionValidator $validator,
        private readonly TradingAuditLogger $auditLogger,
    ) {
    }

    /** @param array<string, mixed> $definition */
    public function createStrategy(User $user, string $name, ?string $description, array $definition): TradingStrategy
    {
        $normalizedDefinition = $this->validator->validate($definition);

        return DB::transaction(function () use ($user, $name, $description, $normalizedDefinition): TradingStrategy {
            $strategy = TradingStrategy::create([
                'user_id' => $user->id,
                'name' => $name,
                'description' => $description,
                'type' => 'rule_based',
                'parameters' => [],
                'mode' => 'paper',
                'is_active' => false,
            ]);

            $version = $this->createVersion($strategy, $user, $normalizedDefinition, 'draft');
            $strategy->update(['current_version_id' => $version->id]);

            $this->auditLogger->record(
                $user->id,
                'strategy_created',
                "Estratégia '{$strategy->name}' criada para backtesting.",
                'info',
                $strategy->id,
                ['strategy_version_id' => $version->id, 'definition_hash' => $version->definition_hash],
                'strategy_version_service',
            );

            return $strategy->fresh('currentVersion');
        });
    }

    /** @param array<string, mixed> $definition */
    public function createNewVersion(TradingStrategy $strategy, User $user, string $name, ?string $description, array $definition): TradingStrategyVersion
    {
        $this->assertOwnedAndEditable($strategy, $user);
        $normalizedDefinition = $this->validator->validate($definition);

        return DB::transaction(function () use ($strategy, $user, $name, $description, $normalizedDefinition): TradingStrategyVersion {
            $strategy = TradingStrategy::query()->lockForUpdate()->findOrFail($strategy->id);
            $strategy->update([
                'name' => $name,
                'description' => $description,
            ]);

            $version = $this->createVersion($strategy, $user, $normalizedDefinition, 'draft');
            $strategy->update(['current_version_id' => $version->id]);

            $this->auditLogger->record(
                $user->id,
                'strategy_version_created',
                "Versão {$version->version} da estratégia '{$strategy->name}' criada.",
                'info',
                $strategy->id,
                ['strategy_version_id' => $version->id, 'definition_hash' => $version->definition_hash],
                'strategy_version_service',
            );

            return $version;
        });
    }

    public function archive(TradingStrategy $strategy, User $user): void
    {
        if ($strategy->user_id !== $user->id) {
            throw new LogicException('A estratégia pertence a outro usuário.');
        }

        DB::transaction(function () use ($strategy, $user): void {
            $strategy->update([
                'archived_at' => now(),
                'is_active' => false,
            ]);

            $strategy->versions()->whereIn('status', ['draft', 'validated'])->update(['status' => 'archived']);

            $this->auditLogger->record(
                $user->id,
                'strategy_archived',
                "Estratégia '{$strategy->name}' arquivada.",
                'info',
                $strategy->id,
                ['strategy_id' => $strategy->id],
                'strategy_version_service',
            );
        });
    }

    private function assertOwnedAndEditable(TradingStrategy $strategy, User $user): void
    {
        if ($strategy->user_id !== $user->id) {
            throw new LogicException('A estratégia pertence a outro usuário.');
        }

        if ($strategy->archived_at !== null) {
            throw new LogicException('Estratégias arquivadas não podem receber novas versões.');
        }
    }

    /** @param array<string, mixed> $definition */
    private function createVersion(TradingStrategy $strategy, User $user, array $definition, string $status): TradingStrategyVersion
    {
        $nextVersion = ((int) $strategy->versions()->max('version')) + 1;
        $json = json_encode($definition, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return TradingStrategyVersion::create([
            'trading_strategy_id' => $strategy->id,
            'version' => $nextVersion,
            'definition' => $definition,
            'definition_hash' => hash('sha256', $json),
            'status' => $status,
            'created_by' => $user->id,
        ]);
    }
}
