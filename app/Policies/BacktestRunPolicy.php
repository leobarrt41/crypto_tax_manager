<?php

namespace App\Policies;

use App\Models\BacktestRun;
use App\Models\User;

class BacktestRunPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function view(User $user, BacktestRun $run): bool
    {
        return (int) $user->id === (int) $run->user_id;
    }

    public function delete(User $user, BacktestRun $run): bool
    {
        return (int) $user->id === (int) $run->user_id;
    }
}
