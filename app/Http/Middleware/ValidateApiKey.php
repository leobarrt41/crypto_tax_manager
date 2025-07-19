<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ValidateApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $apiKey = $request->header('X-API-Key') ?? $request->get('api_key');
        
        if (!$apiKey) {
            return response()->json([
                'error' => 'API Key é obrigatória'
            ], 401);
        }

        // Validar API key (exemplo simples)
        $validKeys = [
            config('app.backtesting_api_key', 'crypto_tax_backtesting_2024'),
            config('app.system_api_key', 'crypto_tax_system_2024'),
        ];

        if (!in_array($apiKey, $validKeys)) {
            return response()->json([
                'error' => 'API Key inválida'
            ], 401);
        }

        return $next($request);
    }
}