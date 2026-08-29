<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use LogicException;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertIsolatedTestDatabase();
    }

    /**
     * Impede que uma suíte seja executada acidentalmente contra a base da aplicação.
     *
     * O padrão versionado é SQLite em memória. Para uma base persistente de testes,
     * o caminho/nome precisa conter "test" e nunca pode reutilizar a conexão da app.
     */
    protected function assertIsolatedTestDatabase(): void
    {
        $connection = (string) config('database.default');
        $database = (string) config("database.connections.{$connection}.database");
        $normalizedDatabase = strtolower($database);

        $isDiscardableSqlite = $connection === 'sqlite' && $database === ':memory:';
        $isNamedTestDatabase = str_contains($normalizedDatabase, 'test');

        if (!$isDiscardableSqlite && !$isNamedTestDatabase) {
            throw new LogicException(
                "Testes bloqueados: o banco configurado ({$connection}: {$database}) não é um banco de testes isolado. " .
                'Use SQLite :memory: ou uma base cujo nome contenha "test".'
            );
        }

        if ($connection !== 'sqlite' && !$isNamedTestDatabase) {
            throw new LogicException('Testes bloqueados: conexões não SQLite exigem uma base explicitamente identificada como teste.');
        }
    }

    /**
     * Limpa uma tabela apenas em banco de teste já validado.
     * O uso de SQL explícito evita a remoção direta de schema nos testes.
     */
    protected function dropTestTable(string $table): void
    {
        $this->assertIsolatedTestDatabase();

        if (!preg_match('/^[a-z0-9_]+$/', $table)) {
            throw new LogicException("Nome de tabela de teste inválido: {$table}");
        }

        $this->app['db']->statement("DROP TABLE IF EXISTS {$table}");
    }
}
