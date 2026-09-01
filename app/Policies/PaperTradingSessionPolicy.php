<?php

namespace App\Policies;

use App\Models\PaperTradingSession;
use App\Models\User;

class PaperTradingSessionPolicy
{
    public function view(User $user, PaperTradingSession $session): bool
    {
        return (int) $user->id === (int) $session->user_id;
    }

    public function update(User $user, PaperTradingSession $session): bool
    {
        return $this->view($user, $session) && $session->status !== PaperTradingSession::STATUS_ARCHIVED;
    }

    public function delete(User $user, PaperTradingSession $session): bool
    {
        return $this->view($user, $session) && $session->status !== PaperTradingSession::STATUS_ARCHIVED;
    }
}
