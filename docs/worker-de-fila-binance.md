# Worker automático da sincronização Binance

## Objetivo

A sincronização Binance é executada em segundo plano. O usuário solicita a importação pela tela e acompanha o status no próprio sistema; ele não precisa executar `queue:work` manualmente.

O servidor mantém um único worker Laravel em execução. Quando não há jobs, o processo fica ocioso. Quando uma sincronização é solicitada, o worker a consome automaticamente, registra o resultado em `import_sessions` e a interface consulta esse status periodicamente.

## Pré-requisitos

| Item | Valor esperado |
|---|---|
| Sistema operacional | Linux com `systemd` |
| PHP CLI | Disponível no mesmo usuário que executa a aplicação |
| Driver de fila | `database` |
| Migrations | Aplicadas, incluindo `jobs`, `failed_jobs`, `import_sessions` e `transaction_import_coverages` |
| Chave Binance | Somente leitura; sem permissões de saque, transferência ou trading |

No arquivo `.env`, configure:

```env
QUEUE_CONNECTION=database
```

Em seguida, aplique as migrations:

```bash
php artisan migrate --force
php artisan optimize:clear
```

## Instalação única do serviço

No diretório do projeto, execute uma única vez como o usuário que possui os arquivos da aplicação:

```bash
chmod +x scripts/install-queue-worker-service.sh
./scripts/install-queue-worker-service.sh
```

O script solicita `sudo` apenas para registrar e iniciar o serviço do sistema. Ele cria o serviço `crypto-tax-manager-queue`, configurado para:

- iniciar automaticamente junto com o servidor;
- retomar automaticamente após falha;
- receber o sinal de `queue:restart` após novos deploys;
- executar jobs com limite de uma hora, apropriado para a sincronização anual Binance.

## Operação após a instalação

Depois de instalado, o usuário usa apenas a interface:

1. Seleciona a chave Binance e o ano.
2. Clica em **Importar da Exchange**.
3. A tela apresenta **Na fila**, **Em andamento**, **Concluída** ou **Falhou**.
4. Ao concluir, a cobertura anual é atualizada automaticamente e informa apenas os CSVs ainda recomendados.

Não execute `php artisan queue:work` manualmente durante a operação normal.

## Verificação administrativa

Use estes comandos apenas para manutenção do servidor:

```bash
sudo systemctl status crypto-tax-manager-queue
sudo systemctl restart crypto-tax-manager-queue
sudo journalctl -u crypto-tax-manager-queue -f
php artisan queue:failed
```

Após atualizar o código do projeto, execute:

```bash
php artisan queue:restart
```

O serviço inicia um novo worker automaticamente.

## Preços históricos

O worker de sincronização salva primeiro as quantidades e taxas das operações. Em seguida, um job de enriquecimento consulta o preço histórico do ativo na Binance, reutiliza as cotações persistidas em `crypto_asset_prices` e converte o valor para BRL com a PTAX do Banco Central na competência da transação. Enquanto essa etapa estiver em andamento, a interface mostra **Pendente de cotação** em vez de exibir R$ 0,00.
