<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TradingBotMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        // Verificar se o trading bot está habilitado
        if (!config('app.trading_bot_enabled', true)) {
            return response()->json([
                'error' => 'Trading bot está desabilitado'
            ], 403);
        }

        // Verificar se o usuário tem permissão para usar o trading bot
        if (!$request->user()->can('use-trading-bot')) {
            return response()->json([
                'error' => 'Sem permissão para usar o trading bot'
            ], 403);
        }

        return $next($request);
    }
}