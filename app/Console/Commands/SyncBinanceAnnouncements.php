<?php

namespace App\Console\Commands;

use App\Models\BinanceAnnouncement;
use App\Models\CryptoAsset;
use App\Models\TradingPair;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SyncBinanceAnnouncements extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'binance:sync-announcements {--force : Reprocessar anúncios já marcados como processados}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sincroniza anúncios oficiais de listagem e deslistagem da Binance, atualizando vigência de ativos e pares.';

    /**
     * Catálogos que serão analisados.
     *
     * @var array<string,int>
     */
    protected array $catalogs = [
        'listings' => 48,
        'launch' => 161,
        'delistings' => 50,
    ];

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('🔄 Iniciando sincronização de anúncios da Binance...');
        $force = $this->option('force');

        foreach ($this->catalogs as $type => $catalogId) {
            $this->line("→ Processando catálogo {$catalogId} ({$type})...");
            $articles = $this->fetchCatalog($catalogId);

            foreach ($articles as $article) {
                $releaseAt = $this->parseReleaseDate($article['releaseDate'] ?? null) ?? now();
                $announcement = BinanceAnnouncement::updateOrCreate(
                    ['code' => $article['code']],
                    [
                        'title' => $article['title'] ?? 'Untitled',
                        'catalog_id' => $catalogId,
                        'release_at' => $releaseAt,
                        'url' => $this->buildArticleUrl($article['code']),
                        'payload' => $article,
                    ]
                );

                if ($announcement->processed_at && !$force) {
                    continue;
                }

                $content = $this->fetchArticleContent($article['code']);
                $pairs = $this->extractPairs($content);
                $announcement->pairs = $pairs;
                $announcement->processed_at = now();
                $announcement->save();

                if (empty($pairs)) {
                    Log::warning('[BinanceAnnouncements] Nenhum par encontrado no anúncio.', [
                        'code' => $article['code'],
                        'title' => $article['title'] ?? '',
                    ]);
                    continue;
                }

                $this->applyPairs($pairs, $catalogId, $releaseAt);
            }
        }

        $this->info('✅ Sincronização concluída.');
        return Command::SUCCESS;
    }

    /**
     * Busca lista de anúncios para o catálogo.
     */
    protected function fetchCatalog(int $catalogId, int $pageSize = 100): array
    {
        $endpoints = [
            [
                'url' => 'https://www.binance.com/bapi/composite/v1/public/cms/article/list',
                'params' => [
                    'type' => 1,
                    'catalogId' => $catalogId,
                    'pageNo' => 1,
                    'pageSize' => $pageSize,
                    'lang' => 'en',
                ],
                'path' => ['data', 'articles'],
            ],
            [
                'url' => 'https://www.binance.com/bapi/composite/v1/public/announcement/get-list',
                'params' => [
                    'type' => 1,
                    'catalogId' => $catalogId,
                    'pageNo' => 1,
                    'pageSize' => $pageSize,
                ],
                'path' => ['data', 'articles'],
            ],
        ];

        foreach ($endpoints as $endpoint) {
            try {
                $response = Http::withHeaders($this->defaultHeaders())
                    ->timeout(20)
                    ->get($endpoint['url'], $endpoint['params']);

                if ($response->successful()) {
                    $data = $response->json();
                    $articles = data_get($data, implode('.', $endpoint['path']), []);
                    if (!empty($articles)) {
                        return $articles;
                    }
                } else {
                    Log::warning('[BinanceAnnouncements] Falha ao obter lista de anúncios.', [
                        'catalogId' => $catalogId,
                        'endpoint' => $endpoint['url'],
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);
                }
            } catch (\Throwable $e) {
                Log::warning('[BinanceAnnouncements] Exceção ao buscar catálogo.', [
                    'catalogId' => $catalogId,
                    'endpoint' => $endpoint['url'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Última tentativa: baixar a página HTML e extrair o JSON embutido
        $html = $this->fetchCatalogHtml($catalogId);
        if (!empty($html)) {
            $articles = $this->parseArticlesFromHtml($html);
            if (!empty($articles)) {
                return $articles;
            }
        }

        Log::error('[BinanceAnnouncements] Nenhum endpoint retornou dados para o catálogo.', [
            'catalogId' => $catalogId,
        ]);

        return [];
    }

    /**
     * Obtém o conteúdo completo do anúncio.
     */
    protected function fetchArticleContent(string $code): string
    {
        $endpoints = [
            [
                'url' => 'https://www.binance.com/bapi/composite/v1/public/cms/article/detail',
                'params' => ['articleCode' => $code, 'lang' => 'en'],
                'path' => ['data', 'content'],
            ],
            [
                'url' => 'https://www.binance.com/bapi/composite/v1/public/announcement/get-article',
                'params' => ['articleCode' => $code, 'lang' => 'en'],
                'path' => ['data', 'content'],
            ],
        ];

        foreach ($endpoints as $endpoint) {
            try {
                $response = Http::withHeaders($this->defaultHeaders())
                    ->timeout(20)
                    ->get($endpoint['url'], $endpoint['params']);

                if ($response->successful()) {
                    $data = $response->json();
                    $content = data_get($data, implode('.', $endpoint['path']));
                    if (!empty($content)) {
                        return $content;
                    }
                } else {
                    Log::warning('[BinanceAnnouncements] Falha ao obter conteúdo do anúncio.', [
                        'code' => $code,
                        'endpoint' => $endpoint['url'],
                        'status' => $response->status(),
                    ]);
                }
            } catch (\Throwable $e) {
                Log::warning('[BinanceAnnouncements] Exceção ao buscar conteúdo.', [
                    'code' => $code,
                    'endpoint' => $endpoint['url'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Fallback: baixar HTML e tentar extrair conteúdo diretamente
        return $this->fetchArticleHtml($code);
    }

    /**
     * Extrai pares e símbolos do conteúdo HTML.
     *
     * @return array<int,array{base:string,quote:string}>
     */
    protected function extractPairs(string $content): array
    {
        $pairs = [];

        if (empty($content)) {
            return $pairs;
        }

        // Remover tags HTML para facilitar regex
        $plain = strip_tags($content);

        // Capturar pares no formato XXX/YYY
        if (preg_match_all('/\b([A-Z0-9]{2,})\/([A-Z0-9]{2,})\b/', $plain, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $pairs[] = [
                    'base' => $match[1],
                    'quote' => $match[2],
                ];
            }
        }

        // Alguns anúncios listam símbolos entre parênteses, ex: "Binance Will List Sample (SMP)"
        if (preg_match_all('/\(([A-Z0-9]{2,})\)/', $plain, $tokenMatches)) {
            foreach ($tokenMatches[1] as $token) {
                $pairs[] = [
                    'base' => $token,
                    'quote' => 'USDT',
                ];
            }
        }

        // Deduplicar pares
        $unique = [];
        foreach ($pairs as $pair) {
            $key = "{$pair['base']}_{$pair['quote']}";
            $unique[$key] = $pair;
        }

        return array_values($unique);
    }

    protected function defaultHeaders(): array
    {
        return [
            'User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Accept' => 'application/json, text/plain, */*',
            'Accept-Language' => 'en-US,en;q=0.9',
            'X-Requested-With' => 'XMLHttpRequest',
            'Cache-Control' => 'no-cache',
        ];
    }

    protected function fetchCatalogHtml(int $catalogId): ?string
    {
        try {
            $response = Http::withHeaders(array_merge($this->defaultHeaders(), [
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            ]))->timeout(20)->get("https://www.binance.com/en/support/announcement/list/{$catalogId}");

            if ($response->successful()) {
                return $response->body();
            }

            Log::warning('[BinanceAnnouncements] Falha ao baixar HTML da lista.', [
                'catalogId' => $catalogId,
                'status' => $response->status(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('[BinanceAnnouncements] Exceção ao baixar HTML da lista.', [
                'catalogId' => $catalogId,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    protected function parseArticlesFromHtml(string $html): array
    {
        if (preg_match('/window\\.__APP_DATA__\\s*=\\s*(\\{.*?\\})\\s*;<\\/script>/s', $html, $matches)) {
            $json = $matches[1] ?? '';
            if ($json !== '') {
                try {
                    $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
                    $articles = data_get($data, 'routeData.store.pageData.articleListData.articles', []);
                    if (!empty($articles)) {
                        return $articles;
                    }
                } catch (\Throwable $e) {
                    Log::warning('[BinanceAnnouncements] Falha ao decodificar JSON do HTML.', [
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        return [];
    }

    protected function fetchArticleHtml(string $code): string
    {
        try {
            $response = Http::withHeaders(array_merge($this->defaultHeaders(), [
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            ]))->timeout(20)->get($this->buildArticleUrl($code));

            if ($response->successful()) {
                return $response->body();
            }
        } catch (\Throwable $e) {
            Log::error('[BinanceAnnouncements] Fallback HTML falhou.', [
                'code' => $code,
                'error' => $e->getMessage(),
            ]);
        }

        return '';
    }

    /**
     * Aplica as atualizações de vigência.
     *
     * @param array<int,array{base:string,quote:string}> $pairs
     */
    protected function applyPairs(array $pairs, int $catalogId, ?Carbon $releaseAt): void
    {
        foreach ($pairs as $pair) {
            $base = strtoupper($pair['base']);
            $quote = strtoupper($pair['quote']);
            $symbol = $base . $quote;

            // Atualizar ativos
            $this->touchAsset($base, $catalogId, $releaseAt);
            $this->touchAsset($quote, $catalogId, $releaseAt);

            // Atualizar par
            $model = TradingPair::updateOrCreate(
                ['symbol' => $symbol],
                [
                    'base_asset' => $base,
                    'quote_asset' => $quote,
                ]
            );

            if ($releaseAt) {
                if (in_array($catalogId, [48, 161], true)) {
                    if (!$model->listed_at || $releaseAt->lt($model->listed_at)) {
                        $model->listed_at = $releaseAt;
                    }
                    $model->delisted_at = null;
                } elseif ($catalogId === 50) {
                    if (!$model->delisted_at || $releaseAt->lt($model->delisted_at)) {
                        $model->delisted_at = $releaseAt;
                    }
                }
            }

            $model->save();
        }
    }

    /**
     * Atualiza vigência do ativo individual.
     */
    protected function touchAsset(string $symbol, int $catalogId, ?Carbon $date): void
    {
        $asset = CryptoAsset::firstOrCreate(['symbol' => $symbol], ['name' => $symbol]);

        if (in_array($catalogId, [48, 161], true)) {
            if (!$asset->listed_at || $date->lt($asset->listed_at)) {
                $asset->listed_at = $date;
            }
            $asset->delisted_at = null;
        } elseif ($catalogId === 50) {
            if (!$asset->delisted_at || $date->lt($asset->delisted_at)) {
                $asset->delisted_at = $date;
            }
        }

        $asset->save();
    }

    /**
     * Converte timestamp fornecido pela Binance.
     */
    protected function parseReleaseDate($value): ?Carbon
    {
        if (empty($value)) {
            return null;
        }

        if (is_numeric($value)) {
            return Carbon::createFromTimestampMs((int)$value);
        }

        return Carbon::parse($value);
    }

    /**
     * Gera URL pública do anúncio.
     */
    protected function buildArticleUrl(string $code): string
    {
        return "https://www.binance.com/en/support/announcement/${code}";
    }
}
