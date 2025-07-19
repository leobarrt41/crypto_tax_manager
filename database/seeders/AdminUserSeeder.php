<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    /**
     * Run the database seeder.
     */
    public function run(): void
    {
        // Verificar se já existe um usuário admin
        $adminExists = User::where('is_admin', true)->exists();

        if (!$adminExists) {
            // Criar usuário administrador padrão
            User::create([
                'name' => 'Administrador',
                'email' => 'admin@cryptotax.com',
                'password' => Hash::make('admin123'),
                'email_verified_at' => now(),
                'is_admin' => true,
                'cpf' => '000.000.000-00', // CPF fictício para admin
                'timezone' => 'America/Sao_Paulo',
                'language' => 'pt-BR',
                'currency' => 'BRL',
                'subscription_plan' => 'enterprise',
                'subscription_expires_at' => now()->addYears(10),
            ]);

            $this->command->info('✅ Usuário administrador criado com sucesso!');
            $this->command->info('📧 Email: admin@cryptotax.com');
            $this->command->info('🔑 Senha: admin123');
            $this->command->warn('⚠️  IMPORTANTE: Altere a senha após o primeiro login!');
        } else {
            $this->command->info('ℹ️  Usuário administrador já existe.');
        }

        // Verificar se o usuário atual (leobarrt@gmail.com) deve ser admin
        $currentUser = User::where('email', 'leobarrt@gmail.com')->first();
        if ($currentUser && !$currentUser->is_admin) {
            $currentUser->update(['is_admin' => true]);
            $this->command->info('✅ Usuário leobarrt@gmail.com promovido a administrador!');
        }
    }
}
