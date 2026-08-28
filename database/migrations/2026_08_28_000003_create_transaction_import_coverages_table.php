<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transaction_import_coverages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exchange_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->string('event_type', 40);
            $table->string('api_status', 20)->default('not_checked');
            $table->unsignedInteger('api_records_count')->default(0);
            $table->timestamp('api_checked_at')->nullable();
            $table->text('api_error')->nullable();
            $table->string('csv_status', 20)->default('not_imported');
            $table->unsignedInteger('csv_records_count')->default(0);
            $table->string('csv_filename')->nullable();
            $table->timestamp('csv_imported_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['user_id', 'exchange_id', 'year', 'month', 'event_type'],
                'transaction_import_coverage_period_event_unique'
            );
            $table->index(['user_id', 'exchange_id', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_import_coverages');
    }
};
