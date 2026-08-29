<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use LogicException;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected function setUp(): void
    {
        $this->assertIsolatedPhpUnitEnvironment();
        parent::setUp();

        $this->assertIsolatedTestDatabase();
    }

    /**
     * Esta barreira roda antes do bootstrap do Laravel e, portanto, antes de
     * RefreshDatabase abrir conexão ou executar migrations.
     */
    private function assertIsolatedPhpUnitEnvironment(): void
    {
        $connection = (string) ($_ENV['DB_CONNECTION'] ?? $_SERVER['DB_CONNECTION'] ?? getenv('DB_CONNECTION'));
        $database = (string) ($_ENV['DB_DATABASE'] ?? $_SERVER['DB_DATABASE'] ?? getenv('DB_DATABASE'));

        if ($connection !== 'sqlite') {
            throw new LogicException('Testes bloqueados antes do bootstrap: DB_CONNECTION deve ser sqlite.');
        }

        if ($database !== ':memory:') {
            $directory = realpath(dirname($database));
            $temporaryRoot = realpath(sys_get_temp_dir());
            $isTemporaryTestFile = $directory !== false
                && $temporaryRoot !== false
                && str_starts_with($directory, $temporaryRoot)
                && str_contains(strtolower(basename($database)), 'test');

            if (!$isTemporaryTestFile) {
                throw new LogicException('Testes bloqueados antes do bootstrap: DB_DATABASE deve ser :memory: ou arquivo test em diretório temporário.');
            }
        }
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

        if ($connection !== 'sqlite') {
            throw new LogicException(
                "Testes bloqueados: a conexão configurada é {$connection}; esta suíte exige SQLite descartável."
            );
        }

        $isInMemory = $database === ':memory:';
        $isNamedTemporaryTestDatabase = str_contains($normalizedDatabase, 'test')
            && str_starts_with(realpath(dirname($database)) ?: dirname($database), realpath(sys_get_temp_dir()));

        if (!$isInMemory && !$isNamedTemporaryTestDatabase) {
            throw new LogicException(
                "Testes bloqueados: o SQLite configurado ({$database}) não é :memory: nem um arquivo temporário identificado como teste."
            );
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
