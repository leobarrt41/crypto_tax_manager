# Conciliação de Binance Convert entre API e CSV

## Problema

A API de Convert e o relatório anual CSV podem representar a mesma operação com identificadores diferentes (`quoteId` na API e `id` no CSV). A deduplicação por `reference` não detectava esses pares. O FIFO processava primeiro o registro automático sem evidência documental e depois criava outro lote com o CSV, produzindo quantidade duplicada e custo pendente artificial.

## Modelo e segurança

A tabela incremental `transaction_reconciliations` relaciona:

- `canonical_transaction_id`: linha do CSV anual, que contém a evidência documental;
- `matched_transaction_id`: linha automática equivalente, preservada como registro bruto;
- versão/tipo do pareamento, confiança, fingerprint, evidências e data UTC.

Cada transação possui `import_origin`: `binance_api`, `binance_annual_csv`, `manual` ou `legacy_unknown`. `import_metadata` continua sendo payload/evidência, nunca ausência de prova. No backfill, somente `import_metadata.format=binance_annual_csv` comprova o CSV anual; todos os demais registros antigos permanecem `legacy_unknown`. Nem `UserApiKey` isoladamente nem metadata genérica comprovam API. Novas importações API e novos cadastros manuais recebem o marcador explícito no momento da criação.

Nenhuma transação é apagada, fundida ou sobrescrita. O FIFO exclui somente o `matched_transaction_id` confirmado do processamento econômico e continua processando a linha canônica do CSV. Ao recalcular, gaps antigos da duplicata podem ser resolvidos normalmente.

## Regras de correspondência e revisão

O pareamento atual é exclusivo para `convert` e sempre exige:

- mesmo usuário;
- uma linha com `import_origin=binance_annual_csv`;
- uma linha com `import_origin=binance_api`;
- mesmos ativos enviados e recebidos;
- mesmas quantidades decimais, sem `float` na comparação;
- datas com diferença máxima de cinco segundos.

Se também houver um identificador estável comum (`reference`, `txid`, `order_id` ou `trade_id`), o match é determinístico e recebe confiança alta. Sem ID comum, ativos + quantidades + janela de cinco segundos produzem confiança média. Nos dois casos, a detecção automática cria somente `pending_review`: nenhum candidato altera o FIFO até uma confirmação humana explícita.

Zero candidatos não gera relação. Mais de um candidato é `ambiguous`, não persiste relação e não altera o FIFO. Restrições únicas impedem que a mesma linha API ou a mesma linha CSV participe de duas conciliações.

Estados auditáveis: `pending_review`, `confirmed`, `rejected` e `revoked`. Cada decisão exige o usuário proprietário e grava um evento append-only em `transaction_reconciliation_events`, com estado anterior/novo, motivo, evidência e instante UTC. As FKs usam `restrict`, portanto eventos não desaparecem por cascade. Somente `confirmed` exclui a duplicata API do FIFO.

Conciliações `confirmed` criadas antes desta trilha não possuem ator comprovável. A migration incremental as preserva, mas altera seu estado para `pending_review`; elas deixam de afetar o FIFO até nova confirmação humana auditada. Nenhum evento ou usuário é inventado no backfill.

## Histórico existente

Primeiro aplique as migrations. A simulação é o comportamento padrão:

Se o CSV foi importado antes de existir `import_metadata`, reimporte o mesmo arquivo com **Ignorar duplicadas** ativado. O importador continuará informando zero novas transações, mas registrará separadamente quantas linhas existentes receberam evidência documental. A tabela `transaction_import_evidences` preserva essa evidência sem reescrever a transação legada.

```bash
php artisan binance:reconcile-api-csv USER_ID 2025
```

Revise as contagens. Para persistir matches determinísticos e candidatos heurísticos:

```bash
php artisan binance:reconcile-api-csv USER_ID 2025 --apply
```

Depois acione explicitamente **Recalcular FIFO** na tela fiscal e execute novamente o diagnóstico. O comando não recalcula nem modifica custos por conta própria.

Para decidir um candidato pendente ou revogar uma confirmação:

```bash
php artisan binance:review-reconciliation RECONCILIATION_ID confirm ACTOR_USER_ID --reason="Identificador conferido"
php artisan binance:review-reconciliation RECONCILIATION_ID reject ACTOR_USER_ID --reason="Operações distintas"
php artisan binance:review-reconciliation RECONCILIATION_ID revoke ACTOR_USER_ID --reason="Revisão posterior"
```

## Próximas importações

Ao importar o CSV, o sistema tenta conciliá-lo com Converts automáticos já existentes. Se a sincronização automática ocorrer depois do CSV, o mesmo serviço tenta o pareamento na gravação da operação da API. Não há chamada externa adicional.

## Limitações e rollback

- Apenas Convert é conciliado nesta versão; Spot, depósitos, retiradas e recompensas exigem regras próprias.
- Diferenças maiores de cinco segundos ou quantidades divergentes permanecem sem pareamento.
- Pares ambíguos exigem revisão futura.
- O relatório de transações continua mostrando os dois registros brutos; a relação confirmada define apenas o efeito FIFO.
- As FKs de transações conciliadas usam exclusão restritiva. Uma transação relacionada não pode desaparecer por cascade; a trilha deve permanecer auditável.
- Se já houver mais de uma relação para a mesma linha canônica antes da migration incremental, a migration falha antes de alterar o schema e pede revisão manual. Nada é apagado automaticamente.
- O rollback da migration incremental `000003` é bloqueado enquanto existir qualquer evento de auditoria. Não apague eventos para forçar o rollback: a trilha append-only deve ser preservada.
- O `down()` restaura `cascadeOnDelete` apenas para retornar tecnicamente ao schema anterior. Depois que a auditoria tiver sido usada, isso não é uma ação apropriada para produção, pois voltaria a permitir que relações desaparecessem junto com transações.
- A migration e os testes da conciliação precisam ser validados em PostgreSQL antes do merge. A aprovação apenas em SQLite não libera integração.

O rollback do código restaura o processamento anterior. Em ambiente sem eventos, a migration é tecnicamente reversível e remove somente as relações de conciliação, nunca as transações. Reverter a relação faz a duplicata voltar a participar do próximo recálculo FIFO.
