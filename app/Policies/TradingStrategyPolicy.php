<?php

namespace App\Policies;

use App\Models\TradingStrategy;
use App\Models\TradingStrategyVersion;
use App\Models\User;

class TradingStrategyPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, TradingStrategy $strategy): bool
    {
        return $strategy->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, TradingStrategy $strategy): bool
    {
        return $strategy->user_id === $user->id;
    }

    public function archive(User $user, TradingStrategy $strategy): bool
    {
        return $this->update($user, $strategy);
    }

    public function viewVersion(User $user, TradingStrategyVersion $version): bool
    {
        return $version->strategy->user_id === $user->id;
    }
}
