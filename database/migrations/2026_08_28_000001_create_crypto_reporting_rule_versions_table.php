<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
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

            $table->index(['effective_from', 'effective_until'], 'crypto_reporting_rules_effective_period_index');
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
                'configuration' => json_encode([
                    'regime_label' => 'IN 1888 (legado)',
                    'legal_reference' => 'IN RFB nº 1.888/2019',
                    'export_status' => 'available',
                ]),
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
                'configuration' => json_encode([
                    'regime_label' => 'DeCripto',
                    'legal_reference' => 'IN RFB nº 2.291/2025',
                    'export_status' => 'pending_layout_implementation',
                    'layout_target' => 'foreign_user_v1',
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('crypto_reporting_rule_versions');
    }
};
