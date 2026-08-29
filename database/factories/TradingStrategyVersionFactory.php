<?php

namespace Database\Factories;

use App\Models\TradingStrategy;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class TradingStrategyVersionFactory extends Factory
{
    public function definition(): array
    {
        $definition = [
            'schema_version' => 1,
            'logic' => 'all',
            'conditions' => [],
            'risk' => [],
        ];

        return [
            'trading_strategy_id' => TradingStrategy::factory(),
            'version' => 1,
            'definition' => $definition,
            'definition_hash' => hash('sha256', json_encode($definition, JSON_UNESCAPED_SLASHES)),
            'status' => 'draft',
            'created_by' => User::factory(),
        ];
    }
}
