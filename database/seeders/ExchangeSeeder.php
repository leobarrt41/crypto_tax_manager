<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Exchange;

class ExchangeSeeder extends Seeder
{
    public function run()
    {
        $exchanges = [
            [
                'name' => 'binance',
                'country_code' => 'MT',
                'description' => 'Binance - Exchange Global',
            ],
            [
                'name' => 'coinbase',
                'country_code' => 'US',
                'description' => 'Coinbase - Exchange dos EUA',
            ],
            [
                'name' => 'kraken',
                'country_code' => 'US',
                'description' => 'Kraken - Exchange fundada em São Francisco',
            ],
            [
                'name' => 'kucoin',
                'country_code' => 'SC',
                'description' => 'KuCoin - Exchange registrada nas Seychelles',
            ],
            [
                'name' => 'bitfinex',
                'country_code' => 'VG',
                'description' => 'Bitfinex - Exchange com sede nas Ilhas Virgens Britânicas',
            ],
        ];

        foreach ($exchanges as $exchange) {
            Exchange::updateOrCreate(
                ['name' => $exchange['name']],
                $exchange
            );
        }
    }
}
