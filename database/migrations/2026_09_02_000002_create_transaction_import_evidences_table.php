<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transaction_import_evidences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('transaction_id')->constrained()->cascadeOnDelete();
            $table->string('format', 50);
            $table->string('source_reference', 255);
            $table->string('content_hash', 64);
            $table->json('evidence');
            $table->timestamp('captured_at');
            $table->timestamps();

            $table->unique(['transaction_id', 'format', 'source_reference'], 'transaction_import_evidence_unique');
            $table->index(['user_id', 'format']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_import_evidences');
    }
};
