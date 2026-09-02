# Diagnóstico de custos FIFO pendentes

## Objetivo e execução

O diagnóstico explica, sem alterar dados, por que uma quantidade localizada ainda não possui custo documental conhecido em BRL. Ele é calculado sob demanda pela versão `fifo-cost-pending-v1`; não existe cron, backfill, chamada externa ou recálculo FIFO automático.

O usuário autenticado acessa **Relatórios → Relatório IR → Diagnóstico de custos pendentes** em `/reports/relatorio-ir/cost-pending-diagnosis`. A página consulta o endpoint read-only `/reports/relatorio-ir/cost-pending-diagnosis/data?year=2026`, que aceita também `asset`, `category` e `status=open|resolved`.

O resultado contém contagem por categoria, quantidade decimal pendente, data UTC, transação e lote de origem quando localizáveis, confiança, evidências, campos ausentes e próxima ação. Executar novamente produz a mesma classificação para o mesmo estado do banco e não cria snapshots ou duplicidades.

## Fontes auditadas

O FIFO cria lotes a partir de entradas (`buy`, `deposit`, `receive`, `earn`, `reward`, `airdrop`, `asset_dividend`, `distribution`), da perna recebida de `trade|convert|swap` e de saldos de abertura. O custo é `known` somente com valor aceito pelo domínio; `estimated` identifica cotação histórica; `pending`/`unavailable` não participam como custo conhecido. Nenhum `null` é convertido em zero pelo diagnóstico.

O classificador usa apenas:

- `fifo_inventory_gaps`, seus `consumed_lots`, razão, estados e data;
- tipo, data, origem, referência e conciliação da transação;
- campos derivados independentes `from_*` e `to_*` do Convert;
- `import_metadata.format`, `original_type`, `market_model_type` e `brl_values`;
- `pricing_status`, `total_brl` e tipo explícito do evento.

O CSV anual preserva `sent_value_brl`, `received_value_brl` e `selected_source`. Depósitos e retiradas unilaterais do CSV ficam com `pending_transfer_reconciliation`. A API de depósitos registra um crédito sem provar custo anterior; Convert da API entra inicialmente na fila de precificação. Asset dividend não é buscado pela API atual e depende do CSV. `dribblet` não possui representação dedicada confirmada no domínio atual e cai em evidência insuficiente, salvo quando o tipo original importado fornecer outra regra explícita.

O vínculo com o lote é reconstruído pela data UTC, ativo e `lot_source=transaction`. O schema atual não grava `source_transaction_id` dentro de cada lote consumido; por isso, vínculos ambíguos permanecem com confiança menor. Evidência de histórico anterior existe somente quando a data registrada do lote precede a primeira transação importada do usuário.

O relatório fiscal continua bloqueado exclusivamente por `FifoInventoryGap` aberto. A classificação não desbloqueia nem cria bloqueio.

## Categorias

- `convert_documented_value_not_recognized`: CSV anual possui `received_value_brl` positivo, mas `to_cost_status` não é `known`. Confiança alta; candidato a correção de lógica, sem autocorreção.
- `historical_quote_only_estimated`: Convert sem valor recebido documental, mas com `total_brl` de cotação histórica. A cotação existe, porém não é documento fiscal.
- `convert_missing_documented_received_value`: Convert sem `received_value_brl` documental aproveitável. Exige CSV/extrato; uma busca Binance poderá ser considerada em fase futura.
- `reward_or_distribution_missing_cost`: tipo registrado indica recompensa, dividendo, distribuição, earn, staking ou mining sem custo. O sistema nunca infere Binance Alpha pelo símbolo.
- `external_deposit_missing_cost`: depósito/recebimento externo sem vínculo de transferência e sem custo transportado.
- `acquisition_missing_brl_value`: compra registrada sem valor documental válido em BRL.
- `pre_import_history_unknown`: lote datado antes do primeiro registro importado, sem transação de origem localizável.
- `possible_internal_transfer_unlinked`: movimento unilateral explicitamente marcado para conciliação.
- `unsupported_or_insufficient_evidence`: há uma transação de origem, mas seu tipo/campos não sustentam regra segura.
- `unclassified`: nem a transação de origem pôde ser localizada com os campos atuais.

## Interpretação e próximas fases

Resultados de valor documental não reconhecido são candidatos a correção de algoritmo. Convert sem valor recebido, recompensas, depósitos e histórico anterior normalmente exigem CSV, extrato ou comprovante. Cotação histórica estimada poderá alimentar um fluxo futuro de revisão explícita, mas não é promovida automaticamente a custo conhecido. Transferências precisam ser conciliadas entre as duas contas. Evidência insuficiente exige revisão manual.

A análise não substitui contador ou tributarista. Não há integração CoinGecko, Binance adicional, inferência por nome de token, criação de aquisição ou alteração de transações nesta entrega.

## Validação e rollback

Execute `php artisan test --filter=FifoCostPendingDiagnosisTest` e depois a suíte completa. Confira que a soma das categorias corresponde ao total filtrado, que outro usuário não aparece e que o relatório permanece bloqueado antes e depois da consulta.

Não há migration nem dados persistidos nesta funcionalidade. O rollback consiste em reverter os arquivos do serviço, controller, rotas, página, testes e esta documentação; nenhuma restauração de dados é necessária.
