<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTransactionsTable extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();

            // 🔐 Usuário dono da transação
            $table->foreignId('user_id')->constrained()->onDelete('cascade');

            // 🔗 Origem polimórfica: carteira ou exchange
            $table->string('source_type'); // App\Models\Wallet ou App\Models\UserApiKey
            $table->unsignedBigInteger('source_id');
            $table->index(['source_type', 'source_id']);

            // 💱 Moedas
            $table->string('from_asset')->nullable();
            $table->decimal('from_amount', 20, 10)->nullable();

            $table->string('to_asset')->nullable();
            $table->decimal('to_amount', 20, 10)->nullable();

            // 📊 Preço e valores
            $table->decimal('price', 20, 10)->nullable();
            $table->decimal('total_usdt', 20, 10)->nullable();
            $table->decimal('total_brl', 20, 10)->nullable();

            // 📌 Metadados
            $table->string('type'); // trade, deposit, withdrawal, etc.
            $table->string('operation')->nullable(); // entrada, saída, etc.
            $table->string('txid')->nullable();
            $table->string('reference')->nullable();

            $table->timestamp('date');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
}
