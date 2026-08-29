<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Proteção de execução real
    |--------------------------------------------------------------------------
    |
    | Esta chave é deliberadamente falsa por padrão. A Fase 0 não permite que
    | nenhuma ordem seja enviada a exchanges, mesmo que uma chave de API tenha
    | permissão de negociação.
    |
    */
    'real_orders_enabled' => env('TRADING_REAL_ORDERS_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Processamento assíncrono e exclusão mútua
    |--------------------------------------------------------------------------
    */
    'queue' => env('TRADING_QUEUE', 'trading'),
    'lock_seconds' => (int) env('TRADING_LOCK_SECONDS', 120),
];
