<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class TradingStrategyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->unique()->words(3, true),
            'description' => fake()->sentence(),
            'type' => 'rule_based',
            'parameters' => [],
            'mode' => 'paper',
            'is_active' => false,
            'archived_at' => null,
        ];
    }
}
