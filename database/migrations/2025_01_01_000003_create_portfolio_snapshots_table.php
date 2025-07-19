<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('portfolio_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('portfolio_id')->constrained()->onDelete('cascade');
            
            $table->decimal('total_value_brl', 15, 2);
            $table->decimal('total_value_usd', 15, 2)->nullable();
            $table->decimal('total_pnl', 15, 2)->nullable();
            $table->datetime('snapshot_date');
            $table->json('data'); // Dados detalhados do snapshot
            
            $table->timestamps();
            
            // Índices
            $table->index(['portfolio_id', 'snapshot_date']);
            $table->index(['snapshot_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('portfolio_snapshots');
    }
};

