<?php

namespace App\Services;

use App\Models\UserApiKey;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use LogicException;

class TradingExecutionGuard
{
    public function operationKeyForStrategy(int $userId, int $strategyId): string
    {
        return "user:{$userId}:strategy:{$strategyId}";
    }

    public function runForStrategy(int $userId, int $strategyId, callable $callback): mixed
    {
        return $this->runExclusively($this->operationKeyForStrategy($userId, $strategyId), $callback);
    }

    public function assertRealOrderSubmissionAllowed(UserApiKey $apiKey): void
    {
        if (!config('trading.real_orders_enabled')) {
            throw new LogicException(
                'O envio de ordens reais está bloqueado. A Fase 0 do Trading Bot permite apenas preparação, auditoria e simulação.'
            );
        }

        if (!$apiKey->trading_enabled) {
            throw new LogicException('A chave selecionada não está habilitada para trading.');
        }

        throw new LogicException(
            'O envio de ordens reais ainda não está implementado para esta operação. Nenhuma ordem foi enviada à exchange.'
        );
    }

    /**
     * Obtém lock exclusivo para uma futura operação de trading.
     *
     * O callback só será executado quando nenhum worker estiver processando
     * o mesmo escopo. Isso previne sinais e ordens duplicados em reinícios.
     */
    public function runExclusively(string $operationKey, callable $callback): mixed
    {
        $lock = Cache::lock(
            sprintf('trading:operation:%s', $operationKey),
            max(1, (int) config('trading.lock_seconds', 120))
        );

        if (!$lock->get()) {
            throw new LockTimeoutException("A operação de trading {$operationKey} já está em processamento.");
        }

        try {
            return $callback();
        } finally {
            optional($lock)->release();
        }
    }
}
