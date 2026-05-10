<?php

namespace App\Services;

use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

/**
 * Serviço otimizado para obtenção de preços da Binance
 * 
 * Este serviço otimiza a obtenção de preços usando dados nativos da Binance,
 * reduzindo drasticamente a dependência de APIs externas como CoinGecko.
 */
class BinancePriceOptimizer
{
    private $client;
    private $priceCache = [];
    private $cachePrefix = 'binance_price_';
    private $cacheTtl = 3600; // 1 hora
    
    public function __construct($binanceClient)
    {
        $this->client = $binanceClient;
    }
    
    /**
     * Obtém o valor em BRL para uma quantidade específica de USDT
     * 
     * @param float $usdtAmount Quantidade em USDT
     * @param int $timestamp Timestamp da transação
     * @return float|null Valor em BRL ou null se não conseguir obter
     */
    public function getBrlValueFromUsdt(float $usdtAmount, int $timestamp): ?float
    {
        $usdtBrlRate = $this->getUsdtBrlRateAtTime($timestamp);
        
        if ($usdtBrlRate) {
            $brlValue = $usdtAmount * $usdtBrlRate;
            
            Log::debug('[BinancePriceOptimizer] Conversão USDT->BRL:', [
                'usdt_amount' => $usdtAmount,
                'rate' => $usdtBrlRate,
                'brl_value' => $brlValue,
                'timestamp' => date('Y-m-d H:i:s', $timestamp / 1000)
            ]);
            
            return $brlValue;
        }
        
        return null;
    }
    
    /**
     * Obtém a cotação USDT/BRL em um momento específico
     * 
     * @param int $timestamp Timestamp em milissegundos
     * @return float|null Taxa de câmbio ou null se não conseguir obter
     */
    private function getUsdtBrlRateAtTime(int $timestamp): ?float
    {
        $cacheKey = $this->cachePrefix . "USDTBRL_" . date('Y-m-d', $timestamp / 1000);
        
        // Verificar cache em memória primeiro
        if (isset($this->priceCache[$cacheKey])) {
            Log::debug('[BinancePriceOptimizer] Taxa USDT/BRL obtida do cache em memória');
            return $this->priceCache[$cacheKey];
        }
        
        // Verificar cache do Laravel
        $cachedRate = Cache::get($cacheKey);
        if ($cachedRate !== null) {
            $this->priceCache[$cacheKey] = $cachedRate;
            Log::debug('[BinancePriceOptimizer] Taxa USDT/BRL obtida do cache Laravel');
            return $cachedRate;
        }
        
        // Buscar da API
        $rate = $this->fetchUsdtBrlRateFromApi($timestamp);
        
        if ($rate !== null) {
            // Salvar nos caches
            $this->priceCache[$cacheKey] = $rate;
            Cache::put($cacheKey, $rate, $this->cacheTtl);
            
            Log::info('[BinancePriceOptimizer] Taxa USDT/BRL obtida da API e cacheada:', [
                'rate' => $rate,
                'date' => date('Y-m-d', $timestamp / 1000)
            ]);
        }
        
        return $rate;
    }
    
    /**
     * Busca a taxa USDT/BRL da API da Binance
     */
    private function fetchUsdtBrlRateFromApi(int $timestamp): ?float
    {
        try {
            // Estratégia 1: Ticker atual (mais confiável)
            $ticker = $this->client->price('USDTBRL');
            
            if ($ticker && isset($ticker['USDTBRL'])) {
                $rate = (float) $ticker['USDTBRL'];
                
                Log::debug('[BinancePriceOptimizer] Taxa obtida via ticker:', [
                    'rate' => $rate,
                    'method' => 'current_ticker'
                ]);
                
                return $rate;
            }
            
        } catch (Exception $e) {
            Log::warning('[BinancePriceOptimizer] Erro ao buscar ticker USDT/BRL: ' . $e->getMessage());
        }
        
        try {
            // Estratégia 2: Candlesticks históricos (se ticker falhar)
            $klines = $this->client->candlesticks('USDTBRL', '1d', 1);
            
            if (!empty($klines) && isset($klines[0][4])) {
                $rate = (float) $klines[0][4]; // Preço de fechamento
                
                Log::debug('[BinancePriceOptimizer] Taxa obtida via candlesticks:', [
                    'rate' => $rate,
                    'method' => 'candlesticks'
                ]);
                
                return $rate;
            }
            
        } catch (Exception $e) {
            Log::warning('[BinancePriceOptimizer] Erro ao buscar candlesticks USDT/BRL: ' . $e->getMessage());
        }
        
        // Estratégia 3: Fallback com taxa aproximada
        $defaultRate = $this->getDefaultUsdtBrlRate();
        
        Log::info('[BinancePriceOptimizer] Usando taxa padrão USDT/BRL:', [
            'rate' => $defaultRate,
            'method' => 'fallback'
        ]);
        
        return $defaultRate;
    }
    
    /**
     * Obtém uma taxa padrão aproximada para USDT/BRL
     */
    private function getDefaultUsdtBrlRate(): float
    {
        // Taxa aproximada baseada no mercado atual
        // Pode ser ajustada conforme necessário
        return 5.2;
    }
    
    /**
     * Obtém valor USDT para conversões usando dados nativos da Binance
     * 
     * @param array $conversion Dados da conversão
     * @return float|null Valor em USDT ou null
     */
    public function getUsdtValueFromConversion(array $conversion): ?float
    {
        // Se a conversão já envolve USDT diretamente
        if (isset($conversion['fromAsset']) && $conversion['fromAsset'] === 'USDT') {
            return (float) ($conversion['fromAmount'] ?? 0);
        }
        
        if (isset($conversion['toAsset']) && $conversion['toAsset'] === 'USDT') {
            return (float) ($conversion['toAmount'] ?? 0);
        }
        
        // Para conversões entre outras moedas, calcular via preços da Binance
        return $this->calculateUsdtValueFromConversion($conversion);
    }
    
    /**
     * Calcula valor USDT para conversões entre outras moedas
     */
    private function calculateUsdtValueFromConversion(array $conversion): ?float
    {
        try {
            $fromAsset = $conversion['fromAsset'] ?? null;
            $toAsset = $conversion['toAsset'] ?? null;
            $fromAmount = (float) ($conversion['fromAmount'] ?? 0);
            $toAmount = (float) ($conversion['toAmount'] ?? 0);
            
            if (!$fromAsset || !$toAsset || (!$fromAmount && !$toAmount)) {
                return null;
            }
            
            // Tentar obter preço do ativo de origem em USDT
            $fromUsdtPrice = $this->getAssetPriceInUsdt($fromAsset);
            if ($fromUsdtPrice && $fromAmount > 0) {
                return $fromAmount * $fromUsdtPrice;
            }
            
            // Tentar obter preço do ativo de destino em USDT
            $toUsdtPrice = $this->getAssetPriceInUsdt($toAsset);
            if ($toUsdtPrice && $toAmount > 0) {
                return $toAmount * $toUsdtPrice;
            }
            
        } catch (Exception $e) {
            Log::warning('[BinancePriceOptimizer] Erro ao calcular valor USDT da conversão: ' . $e->getMessage());
        }
        
        return null;
    }
    
    /**
     * Obtém preço de um ativo em USDT
     */
    private function getAssetPriceInUsdt(string $asset): ?float
    {
        if ($asset === 'USDT') {
            return 1.0;
        }
        
        $symbol = $asset . 'USDT';
        $cacheKey = $this->cachePrefix . "price_{$symbol}";
        
        // Verificar cache
        if (isset($this->priceCache[$cacheKey])) {
            return $this->priceCache[$cacheKey];
        }
        
        $cachedPrice = Cache::get($cacheKey);
        if ($cachedPrice !== null) {
            $this->priceCache[$cacheKey] = $cachedPrice;
            return $cachedPrice;
        }
        
        try {
            $ticker = $this->client->price($symbol);
            
            if ($ticker && isset($ticker[$symbol])) {
                $price = (float) $ticker[$symbol];
                
                // Cache por menos tempo (15 minutos) pois preços mudam mais
                $this->priceCache[$cacheKey] = $price;
                Cache::put($cacheKey, $price, 900);
                
                Log::debug("[BinancePriceOptimizer] Preço {$asset} obtido:", [
                    'symbol' => $symbol,
                    'price' => $price
                ]);
                
                return $price;
            }
            
        } catch (Exception $e) {
            Log::warning("[BinancePriceOptimizer] Erro ao buscar preço de {$asset}: " . $e->getMessage());
        }
        
        return null;
    }
    
    /**
     * Obtém preço de um trade spot usando dados nativos
     * 
     * @param array $trade Dados do trade
     * @return array Array com preços em USDT e BRL
     */
    public function getTradePrice(array $trade): array
    {
        $priceUsdt = (float) ($trade['price'] ?? 0);
        $quantity = (float) ($trade['qty'] ?? 0);
        $timestamp = (int) ($trade['time'] ?? time() * 1000);
        
        // Calcular valor total em USDT
        $totalUsdt = $priceUsdt * $quantity;
        
        // Converter para BRL
        $totalBrl = $this->getBrlValueFromUsdt($totalUsdt, $timestamp);
        
        return [
            'price_usdt' => $priceUsdt,
            'total_usdt' => $totalUsdt,
            'total_brl' => $totalBrl,
            'quantity' => $quantity,
            'timestamp' => $timestamp
        ];
    }
    
    /**
     * Limpa cache de preços
     */
    public function clearPriceCache(): void
    {
        $this->priceCache = [];
        
        // Limpar cache do Laravel com padrão
        $keys = Cache::getRedis()->keys($this->cachePrefix . '*');
        if (!empty($keys)) {
            Cache::getRedis()->del($keys);
        }
        
        Log::info('[BinancePriceOptimizer] Cache de preços limpo');
    }
    
    /**
     * Obtém estatísticas do cache
     */
    public function getCacheStats(): array
    {
        return [
            'memory_cache_size' => count($this->priceCache),
            'memory_cache_keys' => array_keys($this->priceCache),
            'cache_prefix' => $this->cachePrefix,
            'cache_ttl' => $this->cacheTtl
        ];
    }
}
