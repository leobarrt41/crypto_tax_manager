<?php

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_api_keys', function (Blueprint $table) {
            $table->boolean('read_enabled')->default(true)->after('secret_key');
            $table->boolean('trading_enabled')->default(false)->after('read_enabled');
            $table->timestamp('trading_enabled_at')->nullable()->after('trading_enabled');
        });

        DB::table('user_api_keys')
            ->select(['id', 'api_key', 'secret_key'])
            ->orderBy('id')
            ->eachById(function (object $apiKey): void {
                $updates = [];

                foreach (['api_key', 'secret_key'] as $column) {
                    $value = $apiKey->{$column};

                    if ($value === null || $value === '') {
                        continue;
                    }

                    try {
                        Crypt::decryptString($value);
                    } catch (DecryptException) {
                        $updates[$column] = Crypt::encryptString($value);
                    }
                }

                if ($updates !== []) {
                    DB::table('user_api_keys')->where('id', $apiKey->id)->update($updates);
                }
            });

        Schema::table('trading_logs', function (Blueprint $table) {
            $table->string('event_type', 80)->nullable()->after('trading_strategy_id');
            $table->string('severity', 20)->default('info')->after('event_type');
            $table->json('payload')->nullable()->after('message');
            $table->string('source', 80)->default('application')->after('payload');
            $table->timestamp('occurred_at')->nullable()->after('logged_at');
            $table->index(['user_id', 'occurred_at'], 'trading_logs_user_occurred_at_index');
            $table->index(['trading_strategy_id', 'event_type'], 'trading_logs_strategy_event_type_index');
        });

        DB::table('trading_logs')
            ->whereNull('occurred_at')
            ->update(['occurred_at' => DB::raw('logged_at')]);
    }

    public function down(): void
    {
        Schema::table('trading_logs', function (Blueprint $table) {
            $table->dropIndex('trading_logs_user_occurred_at_index');
            $table->dropIndex('trading_logs_strategy_event_type_index');
            $table->dropColumn(['event_type', 'severity', 'payload', 'source', 'occurred_at']);
        });

        Schema::table('user_api_keys', function (Blueprint $table) {
            $table->dropColumn(['read_enabled', 'trading_enabled', 'trading_enabled_at']);
        });
    }
};
