<?php

namespace Tests\Feature;

use App\Http\Controllers\TransactionController;
use ReflectionMethod;
use Tests\TestCase;

class TransactionCsvRowNormalizationTest extends TestCase
{
    public function test_normalizes_rows_with_missing_or_extra_optional_columns_before_combining(): void
    {
        $controller = app(TransactionController::class);
        $method = new ReflectionMethod($controller, 'combineImportedRow');
        $method->setAccessible(true);

        $headers = ['Date', 'Operation', 'Asset', 'Optional field'];

        $this->assertSame([
            'Date' => '2026-01-15 10:30:00',
            'Operation' => 'Buy',
            'Asset' => 'XRP',
            'Optional field' => null,
        ], $method->invoke($controller, $headers, ['2026-01-15 10:30:00', 'Buy', 'XRP'], 2));

        $this->assertSame([
            'Date' => '2026-01-15 10:30:00',
            'Operation' => 'Buy',
            'Asset' => 'XRP',
            'Optional field' => 'valor opcional',
        ], $method->invoke($controller, $headers, [
            '2026-01-15 10:30:00',
            'Buy',
            'XRP',
            'valor opcional',
            'coluna adicional',
        ], 3));
    }

    public function test_extracts_quoted_multiline_csv_values_without_splitting_the_record(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'binance-csv-');
        file_put_contents($path, "Date,Operation,Notes\n2026-01-15,Buy,\"linha um\nlinha dois\"\n");

        try {
            $controller = app(TransactionController::class);
            $method = new ReflectionMethod($controller, 'extractRowsFromImportedFile');
            $method->setAccessible(true);

            [$headers, $rows] = $method->invoke($controller, $path, 'csv');

            $this->assertSame(['Date', 'Operation', 'Notes'], $headers);
            $this->assertCount(1, $rows);
            $this->assertSame(['2026-01-15', 'Buy', "linha um\nlinha dois"], $rows[0]);
        } finally {
            @unlink($path);
        }
    }
}
