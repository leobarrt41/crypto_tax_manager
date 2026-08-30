<?php

namespace Tests\Unit;

use App\Support\DecimalMath;
use Tests\TestCase;

class DecimalMathTest extends TestCase
{
    public function test_it_performs_deterministic_fixed_precision_decimal_operations(): void
    {
        $math = app(DecimalMath::class);

        $this->assertSame('101.0000000000000000', $math->add('100.25', '0.75'));
        $this->assertSame('99.5000000000000000', $math->subtract('100.25', '0.75'));
        $this->assertSame('7.5000000000000000', $math->multiply('2.5', '3'));
        $this->assertSame('3.3333333333333333', $math->divide('10', '3'));
        $this->assertSame('12.5000000000000000', $math->percent('1', '8'));
        $this->assertSame(-1, $math->compare('0.0000000000000001', '0.0000000000000002'));
    }
}
