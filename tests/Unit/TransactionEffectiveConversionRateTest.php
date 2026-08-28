<?php

namespace Tests\Unit;

use App\Models\Transaction;
use Tests\TestCase;

class TransactionEffectiveConversionRateTest extends TestCase
{
    public function test_exposes_convert_rate_in_the_source_asset_per_received_unit(): void
    {
        $transaction = new Transaction([
            'from_asset' => 'ALLO',
            'from_amount' => 385.10948455,
            'to_asset' => 'XRP',
            'to_amount' => 74.56941571,
            'type' => 'convert',
        ]);

        $rate = $transaction->effective_conversion_rate;

        $this->assertNotNull($rate);
        $this->assertSame('ALLO', $rate['base_asset']);
        $this->assertSame('XRP', $rate['quoted_asset']);
        $this->assertEqualsWithDelta(5.1644, $rate['value'], 0.0001);
    }

    public function test_does_not_expose_rate_without_both_amounts_and_assets(): void
    {
        $transaction = new Transaction([
            'from_asset' => 'USDT',
            'from_amount' => 10,
            'to_asset' => 'XRP',
            'to_amount' => 0,
        ]);

        $this->assertNull($transaction->effective_conversion_rate);
    }
}
