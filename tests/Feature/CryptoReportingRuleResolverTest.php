<?php

namespace Tests\Feature;

use App\Services\CryptoReportingRuleResolver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Tests\TestCase;

class CryptoReportingRuleResolverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('crypto_reporting_rule_versions');
        Schema::create('crypto_reporting_rule_versions', function (Blueprint $table) {
            $table->id();
            $table->string('code', 80)->unique();
            $table->string('obligation_name', 80);
            $table->string('reporting_format', 80);
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->decimal('monthly_threshold_brl', 15, 2)->nullable();
            $table->string('threshold_comparison', 10)->default('gt');
            $table->string('reporting_scope', 80);
            $table->string('deadline_rule', 120);
            $table->boolean('legacy_export_available')->default(false);
            $table->json('configuration')->nullable();
            $table->timestamps();
        });

        DB::table('crypto_reporting_rule_versions')->insert([
            [
                'code' => 'in1888_2019_v1',
                'obligation_name' => 'IN 1888',
                'reporting_format' => 'in1888_legacy_v1',
                'effective_from' => '2019-08-01',
                'effective_until' => '2026-06-30',
                'monthly_threshold_brl' => 30000.00,
                'threshold_comparison' => 'gt',
                'reporting_scope' => 'foreign_provider_dex_self_custody',
                'deadline_rule' => 'Último dia útil do mês subsequente',
                'legacy_export_available' => true,
                'configuration' => json_encode(['regime_label' => 'IN 1888 (legado)']),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'decripto_2026_v1',
                'obligation_name' => 'DeCripto',
                'reporting_format' => 'decripto_foreign_user_v1',
                'effective_from' => '2026-07-01',
                'effective_until' => null,
                'monthly_threshold_brl' => 35000.00,
                'threshold_comparison' => 'gt',
                'reporting_scope' => 'foreign_provider_dex_self_custody',
                'deadline_rule' => 'Último dia útil do mês subsequente',
                'legacy_export_available' => false,
                'configuration' => json_encode(['regime_label' => 'DeCripto']),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        Cache::flush();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('crypto_reporting_rule_versions');
        parent::tearDown();
    }

    public function test_resolves_legacy_in1888_rule_until_june_2026(): void
    {
        $rule = app(CryptoReportingRuleResolver::class)->context(2026, 6);

        $this->assertSame('in1888_2019_v1', $rule['code']);
        $this->assertSame('IN 1888', $rule['obligation_name']);
        $this->assertSame(30000.0, $rule['monthly_threshold_brl']);
        $this->assertTrue($rule['legacy_export_available']);
    }

    public function test_resolves_decripto_rule_from_july_2026(): void
    {
        $rule = app(CryptoReportingRuleResolver::class)->context(2026, 7);

        $this->assertSame('decripto_2026_v1', $rule['code']);
        $this->assertSame('DeCripto', $rule['obligation_name']);
        $this->assertSame(35000.0, $rule['monthly_threshold_brl']);
        $this->assertFalse($rule['legacy_export_available']);
    }
}
