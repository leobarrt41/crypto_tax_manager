<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Operações decimais determinísticas, sem depender de float ou extensões PHP.
 * Os resultados utilizam escala fixa de 16 casas decimais e truncamento explícito.
 */
class DecimalMath
{
    public const SCALE = 16;

    public function normalize(mixed $value): string
    {
        $value = trim((string) $value);

        if (preg_match('/^-?\d+(?:\.\d+)?$/', $value) !== 1) {
            throw new InvalidArgumentException('Valor decimal inválido.');
        }

        [$negative, $digits] = $this->parts($value);

        return $this->format($negative, $digits);
    }

    public function add(mixed $left, mixed $right): string
    {
        [$leftNegative, $leftDigits] = $this->parts($left);
        [$rightNegative, $rightDigits] = $this->parts($right);

        if ($leftNegative === $rightNegative) {
            return $this->format($leftNegative, $this->addUnsigned($leftDigits, $rightDigits));
        }

        $comparison = $this->compareUnsigned($leftDigits, $rightDigits);

        if ($comparison === 0) {
            return $this->format(false, '0');
        }

        if ($comparison > 0) {
            return $this->format($leftNegative, $this->subtractUnsigned($leftDigits, $rightDigits));
        }

        return $this->format($rightNegative, $this->subtractUnsigned($rightDigits, $leftDigits));
    }

    public function subtract(mixed $left, mixed $right): string
    {
        return $this->add($left, $this->negate($right));
    }

    public function multiply(mixed $left, mixed $right): string
    {
        [$leftNegative, $leftDigits] = $this->parts($left);
        [$rightNegative, $rightDigits] = $this->parts($right);

        if ($leftDigits === '0' || $rightDigits === '0') {
            return $this->format(false, '0');
        }

        $product = $this->multiplyUnsigned($leftDigits, $rightDigits);
        $scaled = strlen($product) <= self::SCALE ? '0' : substr($product, 0, -self::SCALE);

        return $this->format($leftNegative !== $rightNegative, $scaled);
    }

    public function divide(mixed $dividend, mixed $divisor): string
    {
        [$dividendNegative, $dividendDigits] = $this->parts($dividend);
        [$divisorNegative, $divisorDigits] = $this->parts($divisor);

        if ($divisorDigits === '0') {
            throw new InvalidArgumentException('Divisão decimal por zero.');
        }

        if ($dividendDigits === '0') {
            return $this->format(false, '0');
        }

        $quotient = $this->divideUnsigned($dividendDigits.str_repeat('0', self::SCALE), $divisorDigits);

        return $this->format($dividendNegative !== $divisorNegative, $quotient);
    }

    public function percent(mixed $value, mixed $total): string
    {
        return $this->multiply($this->divide($value, $total), '100');
    }

    public function compare(mixed $left, mixed $right): int
    {
        [$leftNegative, $leftDigits] = $this->parts($left);
        [$rightNegative, $rightDigits] = $this->parts($right);

        if ($leftNegative !== $rightNegative) {
            return $leftNegative ? -1 : 1;
        }

        $comparison = $this->compareUnsigned($leftDigits, $rightDigits);

        return $leftNegative ? -$comparison : $comparison;
    }

    /**
     * Compara leftNumerator/leftDenominator com rightNumerator/rightDenominator sem divisão.
     * Denominadores precisam ser positivos.
     */
    public function compareFractions(
        mixed $leftNumerator,
        mixed $leftDenominator,
        mixed $rightNumerator,
        mixed $rightDenominator,
    ): int {
        if ($this->compare($leftDenominator, '0') <= 0 || $this->compare($rightDenominator, '0') <= 0) {
            throw new InvalidArgumentException('Denominadores de fração devem ser positivos.');
        }

        return $this->compare(
            $this->multiply($leftNumerator, $rightDenominator),
            $this->multiply($rightNumerator, $leftDenominator),
        );
    }

    public function isZero(mixed $value): bool
    {
        [, $digits] = $this->parts($value);

        return $digits === '0';
    }

    public function negate(mixed $value): string
    {
        [$negative, $digits] = $this->parts($value);

        return $this->format(! $negative, $digits);
    }

    /** @return array{0:bool,1:string} */
    private function parts(mixed $value): array
    {
        $value = trim((string) $value);

        if (preg_match('/^-?\d+(?:\.\d+)?$/', $value) !== 1) {
            throw new InvalidArgumentException('Valor decimal inválido.');
        }

        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '-');
        [$integer, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $integer = ltrim($integer, '0') ?: '0';
        $fraction = substr($fraction, 0, self::SCALE);
        $digits = ltrim($integer.str_pad($fraction, self::SCALE, '0'), '0') ?: '0';

        return [$digits === '0' ? false : $negative, $digits];
    }

    private function format(bool $negative, string $digits): string
    {
        $digits = ltrim($digits, '0') ?: '0';
        $digits = str_pad($digits, self::SCALE + 1, '0', STR_PAD_LEFT);
        $integer = substr($digits, 0, -self::SCALE);
        $fraction = substr($digits, -self::SCALE);

        return ($negative && $digits !== str_repeat('0', self::SCALE + 1) ? '-' : '').$integer.'.'.$fraction;
    }

    private function compareUnsigned(string $left, string $right): int
    {
        $left = ltrim($left, '0') ?: '0';
        $right = ltrim($right, '0') ?: '0';

        if (strlen($left) !== strlen($right)) {
            return strlen($left) <=> strlen($right);
        }

        return strcmp($left, $right) <=> 0;
    }

    private function addUnsigned(string $left, string $right): string
    {
        $carry = 0;
        $result = '';
        $leftIndex = strlen($left) - 1;
        $rightIndex = strlen($right) - 1;

        while ($leftIndex >= 0 || $rightIndex >= 0 || $carry > 0) {
            $sum = $carry
                + ($leftIndex >= 0 ? (int) $left[$leftIndex--] : 0)
                + ($rightIndex >= 0 ? (int) $right[$rightIndex--] : 0);
            $result = ($sum % 10).$result;
            $carry = intdiv($sum, 10);
        }

        return ltrim($result, '0') ?: '0';
    }

    private function subtractUnsigned(string $larger, string $smaller): string
    {
        $borrow = 0;
        $result = '';
        $largerIndex = strlen($larger) - 1;
        $smallerIndex = strlen($smaller) - 1;

        while ($largerIndex >= 0) {
            $difference = (int) $larger[$largerIndex--] - $borrow - ($smallerIndex >= 0 ? (int) $smaller[$smallerIndex--] : 0);
            if ($difference < 0) {
                $difference += 10;
                $borrow = 1;
            } else {
                $borrow = 0;
            }
            $result = $difference.$result;
        }

        return ltrim($result, '0') ?: '0';
    }

    private function multiplyUnsigned(string $left, string $right): string
    {
        $result = '0';
        $rightLength = strlen($right);

        for ($index = $rightLength - 1, $zeros = ''; $index >= 0; $index--, $zeros .= '0') {
            $digit = (int) $right[$index];
            $carry = 0;
            $partial = '';

            for ($leftIndex = strlen($left) - 1; $leftIndex >= 0; $leftIndex--) {
                $product = ((int) $left[$leftIndex] * $digit) + $carry;
                $partial = ($product % 10).$partial;
                $carry = intdiv($product, 10);
            }

            $partial = ($carry > 0 ? (string) $carry : '').$partial.$zeros;
            $result = $this->addUnsigned($result, $partial);
        }

        return ltrim($result, '0') ?: '0';
    }

    private function divideUnsigned(string $dividend, string $divisor): string
    {
        $dividend = ltrim($dividend, '0') ?: '0';
        $divisor = ltrim($divisor, '0') ?: '0';
        $remainder = '0';
        $quotient = '';

        foreach (str_split($dividend) as $digit) {
            $remainder = ltrim($remainder.$digit, '0') ?: '0';
            $low = 0;
            $high = 9;
            $selected = 0;

            while ($low <= $high) {
                $middle = intdiv($low + $high, 2);
                $product = $this->multiplyUnsigned($divisor, (string) $middle);
                $comparison = $this->compareUnsigned($product, $remainder);

                if ($comparison <= 0) {
                    $selected = $middle;
                    $low = $middle + 1;
                } else {
                    $high = $middle - 1;
                }
            }

            $quotient .= (string) $selected;
            if ($selected > 0) {
                $remainder = $this->subtractUnsigned($remainder, $this->multiplyUnsigned($divisor, (string) $selected));
            }
        }

        return ltrim($quotient, '0') ?: '0';
    }
}
