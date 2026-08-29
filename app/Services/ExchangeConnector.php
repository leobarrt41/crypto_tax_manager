<?php

namespace App\Services;

use App\Models\UserApiKey;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ExchangeConnector
{
    public function __construct(
        private readonly TradingExecutionGuard $executionGuard,
        private readonly TradingAuditLogger $auditLogger,
    ) {
    }

    protected $exchangeConfigs = [
        'binance' => [
            'base_url' => 'https://api.binance.com',
            'testnet_url' => 'https://testnet.binance.vision'
        ],
        'coinbase' => [
            'base_url' => 'https://api.exchange.coinbase.com',
            'sandbox_url' => 'https://api-public.sandbox.exchange.coinbase.com'
        ],
        'kraken' => [
            'base_url' => 'https://api.kraken.com'
        ],
        'mercadobitcoin' => [
            'base_url' => 'https://www.mercadobitcoin.net/api'
        ]
    ];

    /**
     * Obter preço atual de um par
     */
    public function getCurrentPrice(UserApiKey $apiKey, $symbol)
    {
        try {
            switch ($apiKey->exchange) {
                case 'binance':
                    return $this->getBinancePrice($symbol);
                case 'coinbase':
                    return $this->getCoinbasePrice($symbol);
                case 'kraken':
                    return $this->getKrakenPrice($symbol);
                case 'mercadobitcoin':
                    return $this->getMercadoBitcoinPrice($symbol);
                default:
                    throw new \Exception("Exchange não suportada: {$apiKey->exchange}");
            }
        } catch (\Exception $e) {
            Log::error("Erro ao obter preço: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Obter saldo de um asset
     */
    public function getBalance(UserApiKey $apiKey, $asset)
    {
        try {
            switch ($apiKey->exchange) {
                case 'binance':
                    return $this->getBinanceBalance($apiKey, $asset);
                case 'coinbase':
                    return $this->getCoinbaseBalance($apiKey, $asset);
                case 'kraken':
                    return $this->getKrakenBalance($apiKey, $asset);
                case 'mercadobitcoin':
                    return $this->getMercadoBitcoinBalance($apiKey, $asset);
                default:
                    throw new \Exception("Exchange não suportada: {$apiKey->exchange}");
            }
        } catch (\Exception $e) {
            Log::error("Erro ao obter saldo: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Colocar uma ordem
     */
    public function placeOrder(UserApiKey $apiKey, $orderData)
    {
        try {
            $this->executionGuard->assertRealOrderSubmissionAllowed($apiKey);

            switch ($apiKey->exchange) {
                case 'binance':
                    return $this->placeBinanceOrder($apiKey, $orderData);
                case 'coinbase':
                    return $this->placeCoinbaseOrder($apiKey, $orderData);
                case 'kraken':
                    return $this->placeKrakenOrder($apiKey, $orderData);
                case 'mercadobitcoin':
                    return $this->placeMercadoBitcoinOrder($apiKey, $orderData);
                default:
                    throw new \Exception("Exchange não suportada: {$apiKey->exchange}");
            }
        } catch (\Exception $e) {
            if ($apiKey->user_id) {
                $this->auditLogger->record(
                    (int) $apiKey->user_id,
                    'real_order_blocked',
                    'Tentativa de envio de ordem real bloqueada pela política da Fase 0.',
                    'warning',
                    payload: [
                        'user_api_key_id' => $apiKey->id,
                        'order' => (array) $orderData,
                    ],
                    source: 'exchange_connector',
                );
            }

            Log::warning('Envio de ordem bloqueado ou falhou.', [
                'user_api_key_id' => $apiKey->id,
                'message' => $e->getMessage(),
            ]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Obter ordens abertas
     */
    public function getOpenOrders(UserApiKey $apiKey, $symbol = null)
    {
        try {
            switch ($apiKey->exchange) {
                case 'binance':
                    return $this->getBinanceOpenOrders($apiKey, $symbol);
                case 'coinbase':
                    return $this->getCoinbaseOpenOrders($apiKey, $symbol);
                case 'kraken':
                    return $this->getKrakenOpenOrders($apiKey, $symbol);
                case 'mercadobitcoin':
                    return $this->getMercadoBitcoinOpenOrders($apiKey, $symbol);
                default:
                    throw new \Exception("Exchange não suportada: {$apiKey->exchange}");
            }
        } catch (\Exception $e) {
            Log::error("Erro ao obter ordens abertas: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Obter dados de mercado
     */
    public function getMarketData(UserApiKey $apiKey, $symbol)
    {
        try {
            switch ($apiKey->exchange) {
                case 'binance':
                    return $this->getBinanceMarketData($symbol);
                case 'coinbase':
                    return $this->getCoinbaseMarketData($symbol);
                case 'kraken':
                    return $this->getKrakenMarketData($symbol);
                case 'mercadobitcoin':
                    return $this->getMercadoBitcoinMarketData($symbol);
                default:
                    throw new \Exception("Exchange não suportada: {$apiKey->exchange}");
            }
        } catch (\Exception $e) {
            Log::error("Erro ao obter dados de mercado: " . $e->getMessage());
            return null;
        }
    }

    // ==================== BINANCE ====================

    protected function getBinancePrice($symbol)
    {
        $response = Http::get($this->exchangeConfigs['binance']['base_url'] . '/api/v3/ticker/price', [
            'symbol' => $symbol
        ]);

        if ($response->successful()) {
            return floatval($response->json()['price']);
        }

        throw new \Exception("Erro ao obter preço da Binance: " . $response->body());
    }

    protected function getBinanceBalance(UserApiKey $apiKey, $asset)
    {
        $timestamp = now()->timestamp * 1000;
        $queryString = "timestamp={$timestamp}";
        $signature = hash_hmac('sha256', $queryString, $apiKey->secret_key);

        $response = Http::withHeaders([
            'X-MBX-APIKEY' => $apiKey->api_key
        ])->get($this->exchangeConfigs['binance']['base_url'] . '/api/v3/account', [
            'timestamp' => $timestamp,
            'signature' => $signature
        ]);

        if ($response->successful()) {
            $balances = $response->json()['balances'];
            foreach ($balances as $balance) {
                if ($balance['asset'] === $asset) {
                    return floatval($balance['free']);
                }
            }
            return 0;
        }

        throw new \Exception("Erro ao obter saldo da Binance: " . $response->body());
    }

    protected function placeBinanceOrder(UserApiKey $apiKey, $orderData)
    {
        $timestamp = now()->timestamp * 1000;
        $orderData['timestamp'] = $timestamp;
        
        $queryString = http_build_query($orderData);
        $signature = hash_hmac('sha256', $queryString, $apiKey->secret_key);
        $orderData['signature'] = $signature;

        $response = Http::withHeaders([
            'X-MBX-APIKEY' => $apiKey->api_key
        ])->post($this->exchangeConfigs['binance']['base_url'] . '/api/v3/order', $orderData);

        if ($response->successful()) {
            return ['success' => true, 'data' => $response->json()];
        }

        return ['success' => false, 'error' => $response->body()];
    }

    protected function getBinanceOpenOrders(UserApiKey $apiKey, $symbol = null)
    {
        $timestamp = now()->timestamp * 1000;
        $params = ['timestamp' => $timestamp];
        
        if ($symbol) {
            $params['symbol'] = $symbol;
        }

        $queryString = http_build_query($params);
        $signature = hash_hmac('sha256', $queryString, $apiKey->secret_key);
        $params['signature'] = $signature;

        $response = Http::withHeaders([
            'X-MBX-APIKEY' => $apiKey->api_key
        ])->get($this->exchangeConfigs['binance']['base_url'] . '/api/v3/openOrders', $params);

        if ($response->successful()) {
            return $response->json();
        }

        throw new \Exception("Erro ao obter ordens abertas da Binance: " . $response->body());
    }

    protected function getBinanceMarketData($symbol)
    {
        $response = Http::get($this->exchangeConfigs['binance']['base_url'] . '/api/v3/ticker/24hr', [
            'symbol' => $symbol
        ]);

        if ($response->successful()) {
            $data = $response->json();
            return [
                'symbol' => $data['symbol'],
                'price' => floatval($data['lastPrice']),
                'change' => floatval($data['priceChangePercent']),
                'volume' => floatval($data['volume']),
                'high' => floatval($data['highPrice']),
                'low' => floatval($data['lowPrice'])
            ];
        }

        throw new \Exception("Erro ao obter dados de mercado da Binance: " . $response->body());
    }

    // ==================== COINBASE ====================

    protected function getCoinbasePrice($symbol)
    {
        // Converter formato: BTCUSDT -> BTC-USD
        $coinbasePair = $this->convertToCoinbasePair($symbol);
        
        $response = Http::get($this->exchangeConfigs['coinbase']['base_url'] . "/products/{$coinbasePair}/ticker");

        if ($response->successful()) {
            return floatval($response->json()['price']);
        }

        throw new \Exception("Erro ao obter preço da Coinbase: " . $response->body());
    }

    protected function getCoinbaseBalance(UserApiKey $apiKey, $asset)
    {
        $timestamp = now()->timestamp;
        $method = 'GET';
        $path = '/accounts';
        $body = '';

        $signature = $this->createCoinbaseSignature($timestamp, $method, $path, $body, $apiKey->secret_key);

        $response = Http::withHeaders([
            'CB-ACCESS-KEY' => $apiKey->api_key,
            'CB-ACCESS-SIGN' => $signature,
            'CB-ACCESS-TIMESTAMP' => $timestamp,
            'CB-ACCESS-PASSPHRASE' => $apiKey->passphrase ?? ''
        ])->get($this->exchangeConfigs['coinbase']['base_url'] . $path);

        if ($response->successful()) {
            $accounts = $response->json();
            foreach ($accounts as $account) {
                if ($account['currency'] === $asset) {
                    return floatval($account['available']);
                }
            }
            return 0;
        }

        throw new \Exception("Erro ao obter saldo da Coinbase: " . $response->body());
    }

    protected function placeCoinbaseOrder(UserApiKey $apiKey, $orderData)
    {
        $timestamp = now()->timestamp;
        $method = 'POST';
        $path = '/orders';
        $body = json_encode($orderData);

        $signature = $this->createCoinbaseSignature($timestamp, $method, $path, $body, $apiKey->secret_key);

        $response = Http::withHeaders([
            'CB-ACCESS-KEY' => $apiKey->api_key,
            'CB-ACCESS-SIGN' => $signature,
            'CB-ACCESS-TIMESTAMP' => $timestamp,
            'CB-ACCESS-PASSPHRASE' => $apiKey->passphrase ?? '',
            'Content-Type' => 'application/json'
        ])->post($this->exchangeConfigs['coinbase']['base_url'] . $path, $orderData);

        if ($response->successful()) {
            return ['success' => true, 'data' => $response->json()];
        }

        return ['success' => false, 'error' => $response->body()];
    }

    protected function getCoinbaseOpenOrders(UserApiKey $apiKey, $symbol = null)
    {
        $timestamp = now()->timestamp;
        $method = 'GET';
        $path = '/orders';
        $body = '';

        $signature = $this->createCoinbaseSignature($timestamp, $method, $path, $body, $apiKey->secret_key);

        $response = Http::withHeaders([
            'CB-ACCESS-KEY' => $apiKey->api_key,
            'CB-ACCESS-SIGN' => $signature,
            'CB-ACCESS-TIMESTAMP' => $timestamp,
            'CB-ACCESS-PASSPHRASE' => $apiKey->passphrase ?? ''
        ])->get($this->exchangeConfigs['coinbase']['base_url'] . $path);

        if ($response->successful()) {
            return $response->json();
        }

        throw new \Exception("Erro ao obter ordens abertas da Coinbase: " . $response->body());
    }

    protected function getCoinbaseMarketData($symbol)
    {
        $coinbasePair = $this->convertToCoinbasePair($symbol);
        
        $response = Http::get($this->exchangeConfigs['coinbase']['base_url'] . "/products/{$coinbasePair}/stats");

        if ($response->successful()) {
            $data = $response->json();
            return [
                'symbol' => $symbol,
                'price' => floatval($data['last']),
                'change' => 0, // Coinbase não fornece mudança percentual diretamente
                'volume' => floatval($data['volume']),
                'high' => floatval($data['high']),
                'low' => floatval($data['low'])
            ];
        }

        throw new \Exception("Erro ao obter dados de mercado da Coinbase: " . $response->body());
    }

    // ==================== KRAKEN ====================

    protected function getKrakenPrice($symbol)
    {
        $krakenPair = $this->convertToKrakenPair($symbol);
        
        $response = Http::get($this->exchangeConfigs['kraken']['base_url'] . '/0/public/Ticker', [
            'pair' => $krakenPair
        ]);

        if ($response->successful()) {
            $data = $response->json();
            if (isset($data['result'][$krakenPair])) {
                return floatval($data['result'][$krakenPair]['c'][0]);
            }
        }

        throw new \Exception("Erro ao obter preço da Kraken: " . $response->body());
    }

    protected function getKrakenBalance(UserApiKey $apiKey, $asset)
    {
        $nonce = now()->timestamp * 1000;
        $postData = ['nonce' => $nonce];
        $path = '/0/private/Balance';
        
        $signature = $this->createKrakenSignature($path, $postData, $apiKey->secret_key);

        $response = Http::withHeaders([
            'API-Key' => $apiKey->api_key,
            'API-Sign' => $signature
        ])->post($this->exchangeConfigs['kraken']['base_url'] . $path, $postData);

        if ($response->successful()) {
            $data = $response->json();
            if (isset($data['result'][$asset])) {
                return floatval($data['result'][$asset]);
            }
            return 0;
        }

        throw new \Exception("Erro ao obter saldo da Kraken: " . $response->body());
    }

    protected function placeKrakenOrder(UserApiKey $apiKey, $orderData)
    {
        $nonce = now()->timestamp * 1000;
        $postData = array_merge(['nonce' => $nonce], $orderData);
        $path = '/0/private/AddOrder';
        
        $signature = $this->createKrakenSignature($path, $postData, $apiKey->secret_key);

        $response = Http::withHeaders([
            'API-Key' => $apiKey->api_key,
            'API-Sign' => $signature
        ])->post($this->exchangeConfigs['kraken']['base_url'] . $path, $postData);

        if ($response->successful()) {
            return ['success' => true, 'data' => $response->json()];
        }

        return ['success' => false, 'error' => $response->body()];
    }

    protected function getKrakenOpenOrders(UserApiKey $apiKey, $symbol = null)
    {
        $nonce = now()->timestamp * 1000;
        $postData = ['nonce' => $nonce];
        $path = '/0/private/OpenOrders';
        
        $signature = $this->createKrakenSignature($path, $postData, $apiKey->secret_key);

        $response = Http::withHeaders([
            'API-Key' => $apiKey->api_key,
            'API-Sign' => $signature
        ])->post($this->exchangeConfigs['kraken']['base_url'] . $path, $postData);

        if ($response->successful()) {
            $data = $response->json();
            return $data['result']['open'] ?? [];
        }

        throw new \Exception("Erro ao obter ordens abertas da Kraken: " . $response->body());
    }

    protected function getKrakenMarketData($symbol)
    {
        $krakenPair = $this->convertToKrakenPair($symbol);
        
        $response = Http::get($this->exchangeConfigs['kraken']['base_url'] . '/0/public/Ticker', [
            'pair' => $krakenPair
        ]);

        if ($response->successful()) {
            $data = $response->json();
            if (isset($data['result'][$krakenPair])) {
                $ticker = $data['result'][$krakenPair];
                return [
                    'symbol' => $symbol,
                    'price' => floatval($ticker['c'][0]),
                    'change' => 0, // Calcular baseado em dados históricos
                    'volume' => floatval($ticker['v'][1]),
                    'high' => floatval($ticker['h'][1]),
                    'low' => floatval($ticker['l'][1])
                ];
            }
        }

        throw new \Exception("Erro ao obter dados de mercado da Kraken: " . $response->body());
    }

    // ==================== MERCADO BITCOIN ====================

    protected function getMercadoBitcoinPrice($symbol)
    {
        // Mercado Bitcoin usa formato diferente
        $mbSymbol = $this->convertToMercadoBitcoinPair($symbol);
        
        $response = Http::get($this->exchangeConfigs['mercadobitcoin']['base_url'] . "/{$mbSymbol}/ticker/");

        if ($response->successful()) {
            $data = $response->json();
            return floatval($data['ticker']['last']);
        }

        throw new \Exception("Erro ao obter preço do Mercado Bitcoin: " . $response->body());
    }

    protected function getMercadoBitcoinBalance(UserApiKey $apiKey, $asset)
    {
        // Implementar autenticação do Mercado Bitcoin
        // Por simplicidade, retornar 0
        return 0;
    }

    protected function placeMercadoBitcoinOrder(UserApiKey $apiKey, $orderData)
    {
        // Implementar colocação de ordem no Mercado Bitcoin
        return ['success' => false, 'error' => 'Não implementado'];
    }

    protected function getMercadoBitcoinOpenOrders(UserApiKey $apiKey, $symbol = null)
    {
        // Implementar busca de ordens abertas no Mercado Bitcoin
        return [];
    }

    protected function getMercadoBitcoinMarketData($symbol)
    {
        $mbSymbol = $this->convertToMercadoBitcoinPair($symbol);
        
        $response = Http::get($this->exchangeConfigs['mercadobitcoin']['base_url'] . "/{$mbSymbol}/ticker/");

        if ($response->successful()) {
            $data = $response->json();
            $ticker = $data['ticker'];
            return [
                'symbol' => $symbol,
                'price' => floatval($ticker['last']),
                'change' => 0,
                'volume' => floatval($ticker['vol']),
                'high' => floatval($ticker['high']),
                'low' => floatval($ticker['low'])
            ];
        }

        throw new \Exception("Erro ao obter dados de mercado do Mercado Bitcoin: " . $response->body());
    }

    // ==================== HELPER METHODS ====================

    protected function convertToCoinbasePair($symbol)
    {
        // BTCUSDT -> BTC-USD
        if (str_ends_with($symbol, 'USDT')) {
            $base = str_replace('USDT', '', $symbol);
            return $base . '-USD';
        }
        return $symbol;
    }

    protected function convertToKrakenPair($symbol)
    {
        // BTCUSDT -> XBTUSD
        $conversions = [
            'BTCUSDT' => 'XBTUSD',
            'ETHUSDT' => 'ETHUSD',
            'ADAUSDT' => 'ADAUSD',
            'DOTUSDT' => 'DOTUSD'
        ];
        
        return $conversions[$symbol] ?? $symbol;
    }

    protected function convertToMercadoBitcoinPair($symbol)
    {
        // BTCUSDT -> BTC (Mercado Bitcoin usa apenas a moeda base)
        if (str_ends_with($symbol, 'USDT')) {
            return str_replace('USDT', '', $symbol);
        }
        if (str_ends_with($symbol, 'BRL')) {
            return str_replace('BRL', '', $symbol);
        }
        return $symbol;
    }

    protected function createCoinbaseSignature($timestamp, $method, $path, $body, $secret)
    {
        $message = $timestamp . $method . $path . $body;
        return base64_encode(hash_hmac('sha256', $message, base64_decode($secret), true));
    }

    protected function createKrakenSignature($path, $postData, $secret)
    {
        $postString = http_build_query($postData);
        $hash = hash('sha256', $postData['nonce'] . $postString, true);
        $hmac = hash_hmac('sha512', $path . $hash, base64_decode($secret), true);
        return base64_encode($hmac);
    }

    /**
     * Testar conexão com uma exchange
     */
    public function testConnection(UserApiKey $apiKey)
    {
        try {
            switch ($apiKey->exchange) {
                case 'binance':
                    return $this->testBinanceConnection($apiKey);
                case 'coinbase':
                    return $this->testCoinbaseConnection($apiKey);
                case 'kraken':
                    return $this->testKrakenConnection($apiKey);
                case 'mercadobitcoin':
                    return $this->testMercadoBitcoinConnection($apiKey);
                default:
                    return ['success' => false, 'error' => 'Exchange não suportada'];
            }
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    protected function testBinanceConnection(UserApiKey $apiKey)
    {
        $timestamp = now()->timestamp * 1000;
        $queryString = "timestamp={$timestamp}";
        $signature = hash_hmac('sha256', $queryString, $apiKey->secret_key);

        $response = Http::withHeaders([
            'X-MBX-APIKEY' => $apiKey->api_key
        ])->get($this->exchangeConfigs['binance']['base_url'] . '/api/v3/account', [
            'timestamp' => $timestamp,
            'signature' => $signature
        ]);

        return [
            'success' => $response->successful(),
            'error' => $response->successful() ? null : $response->body()
        ];
    }

    protected function testCoinbaseConnection(UserApiKey $apiKey)
    {
        $timestamp = now()->timestamp;
        $method = 'GET';
        $path = '/accounts';
        $body = '';

        $signature = $this->createCoinbaseSignature($timestamp, $method, $path, $body, $apiKey->secret_key);

        $response = Http::withHeaders([
            'CB-ACCESS-KEY' => $apiKey->api_key,
            'CB-ACCESS-SIGN' => $signature,
            'CB-ACCESS-TIMESTAMP' => $timestamp,
            'CB-ACCESS-PASSPHRASE' => $apiKey->passphrase ?? ''
        ])->get($this->exchangeConfigs['coinbase']['base_url'] . $path);

        return [
            'success' => $response->successful(),
            'error' => $response->successful() ? null : $response->body()
        ];
    }

    protected function testKrakenConnection(UserApiKey $apiKey)
    {
        $nonce = now()->timestamp * 1000;
        $postData = ['nonce' => $nonce];
        $path = '/0/private/Balance';
        
        $signature = $this->createKrakenSignature($path, $postData, $apiKey->secret_key);

        $response = Http::withHeaders([
            'API-Key' => $apiKey->api_key,
            'API-Sign' => $signature
        ])->post($this->exchangeConfigs['kraken']['base_url'] . $path, $postData);

        return [
            'success' => $response->successful(),
            'error' => $response->successful() ? null : $response->body()
        ];
    }

    protected function testMercadoBitcoinConnection(UserApiKey $apiKey)
    {
        // Implementar teste de conexão do Mercado Bitcoin
        return ['success' => true, 'error' => null];
    }
}
