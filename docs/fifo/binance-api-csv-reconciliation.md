# Conciliação de Binance Convert entre API e CSV

## Problema

A API de Convert e o relatório anual CSV podem representar a mesma operação com identificadores diferentes (`quoteId` na API e `id` no CSV). A deduplicação por `reference` não detectava esses pares. O FIFO processava primeiro o registro automático sem evidência documental e depois criava outro lote com o CSV, produzindo quantidade duplicada e custo pendente artificial.

## Modelo e segurança

A tabela incremental `transaction_reconciliations` relaciona:

- `canonical_transaction_id`: linha do CSV anual, que contém a evidência documental;
- `matched_transaction_id`: linha automática equivalente, preservada como registro bruto;
- versão/tipo do pareamento, confiança, fingerprint, evidências e data UTC.

Nenhuma transação é apagada, fundida ou sobrescrita. O FIFO exclui somente o `matched_transaction_id` confirmado do processamento econômico e continua processando a linha canônica do CSV. Ao recalcular, gaps antigos da duplicata podem ser resolvidos normalmente.

## Regra determinística

O pareamento atual é exclusivo para `convert` e exige:

- mesmo usuário;
- uma linha com `import_metadata.format=binance_annual_csv`;
- uma linha automática vinculada a `UserApiKey`, sem metadata anual;
- mesmos ativos enviados e recebidos;
- mesmas quantidades decimais, sem `float` na comparação;
- datas com diferença máxima de cinco segundos.

Um candidato exato recebe confiança alta. Zero candidatos não gera relação. Mais de um candidato é `ambiguous` e também não é conciliado automaticamente.

## Histórico existente

Primeiro aplique as migrations. A simulação é o comportamento padrão:

Se o CSV foi importado antes de existir `import_metadata`, reimporte o mesmo arquivo com **Ignorar duplicadas** ativado. O importador continuará informando zero novas transações, mas registrará separadamente quantas linhas existentes receberam evidência documental. A tabela `transaction_import_evidences` preserva essa evidência sem reescrever a transação legada.

```bash
php artisan binance:reconcile-api-csv USER_ID 2025
```

Revise as contagens. Para persistir somente os pares exatos:

```bash
php artisan binance:reconcile-api-csv USER_ID 2025 --apply
```

Depois acione explicitamente **Recalcular FIFO** na tela fiscal e execute novamente o diagnóstico. O comando não recalcula nem modifica custos por conta própria.

## Próximas importações

Ao importar o CSV, o sistema tenta conciliá-lo com Converts automáticos já existentes. Se a sincronização automática ocorrer depois do CSV, o mesmo serviço tenta o pareamento na gravação da operação da API. Não há chamada externa adicional.

## Limitações e rollback

- Apenas Convert é conciliado nesta versão; Spot, depósitos, retiradas e recompensas exigem regras próprias.
- Diferenças maiores de cinco segundos ou quantidades divergentes permanecem sem pareamento.
- Pares ambíguos exigem revisão futura.
- O relatório de transações continua mostrando os dois registros brutos; a relação define apenas o efeito FIFO.

O rollback do código restaura o processamento anterior. A migration é reversível e remove somente as relações de conciliação, nunca as transações. Reverter a relação faz a duplicata voltar a participar do próximo recálculo FIFO.
