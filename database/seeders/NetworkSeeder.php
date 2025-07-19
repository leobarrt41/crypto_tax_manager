<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Network;

class NetworkSeeder extends Seeder
{
    public function run()
    {
        $networks = [
            ['name' => 'Binance Smart Chain', 'slug' => 'bsc', 'explorer_url' => 'https://bscscan.com'],
            ['name' => 'Ethereum', 'slug' => 'eth', 'explorer_url' => 'https://etherscan.io'],
            ['name' => 'Polygon', 'slug' => 'polygon', 'explorer_url' => 'https://polygonscan.com'],
            ['name' => 'Bitcoin', 'slug' => 'bitcoin', 'explorer_url' => 'https://www.blockchain.com/explorer'],
            ['name' => 'Solana', 'slug' => 'solana', 'explorer_url' => 'https://solscan.io'],
        ];

        foreach ($networks as $network) {
            Network::updateOrCreate(['slug' => $network['slug']], $network);
        }
    }
}
