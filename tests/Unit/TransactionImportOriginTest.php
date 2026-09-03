<?php

namespace Tests\Unit;

use App\Support\TransactionImportOrigin;
use PHPUnit\Framework\TestCase;

class TransactionImportOriginTest extends TestCase
{
    public function test_hardening_migration_does_not_use_mysql_after_modifier(): void
    {
        $migration = file_get_contents(dirname(__DIR__, 2).'/database/migrations/2026_09_02_000003_harden_transaction_reconciliation.php');

        $this->assertIsString($migration);
        $this->assertStringNotContainsString('->after(', $migration);
    }

    public function test_backfill_only_classifies_origins_with_explicit_evidence(): void
    {
        $this->assertSame('binance_annual_csv', TransactionImportOrigin::inferLegacy(
            'App\\Models\\UserApiKey',
            ['format' => 'binance_annual_csv'],
        ));
        $this->assertSame('legacy_unknown', TransactionImportOrigin::inferLegacy(
            'App\\Models\\UserApiKey',
            ['endpoint' => '/sapi/v1/convert/tradeFlow'],
        ));
        $this->assertSame('legacy_unknown', TransactionImportOrigin::inferLegacy('App\\Models\\UserApiKey', null));
        $this->assertSame('legacy_unknown', TransactionImportOrigin::inferLegacy('App\\Models\\Wallet', null));
    }
}
