<?php

namespace App\Console\Commands;
use App\Services\CryptoPriceService;

use Illuminate\Console\Command;

class UpdateCryptoPrices extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:update-crypto-prices';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
   public function handle(CryptoPriceService $priceService)
{
    $symbols = CryptoAsset::pluck('symbol');
    $date = now()->subDay();

    foreach ($symbols as $symbol) {
        $price = $priceService->getOrCreatePrice($symbol, $date);
        $this->info("Preço de {$symbol} em {$date->toDateString()}: " . ($price ?? 'não encontrado'));
        sleep(1); // evitar rate limit
    }

    $this->info('Cotações atualizadas com sucesso.');
}
}
