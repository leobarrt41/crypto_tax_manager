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
        Schema::create('portfolios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            
            $table->string('name')->default('Portfolio Principal');
            $table->text('description')->nullable();
            
            // Valores calculados
            $table->decimal('total_value_brl', 15, 2)->default(0);
            $table->decimal('total_value_usd', 15, 2)->default(0);
            $table->decimal('total_invested', 15, 2)->default(0);
            $table->decimal('total_pnl', 15, 2)->default(0);
            $table->decimal('pnl_percentage', 8, 4)->default(0);
            
            // Controle
            $table->timestamp('last_updated_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('settings')->nullable();
            
            $table->timestamps();
            $table->softDeletes();
            
            // Índices
            $table->index(['user_id', 'is_active']);
            $table->index(['last_updated_at']);
            $table->index(['deleted_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('portfolios');
    }
};

