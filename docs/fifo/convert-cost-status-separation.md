# Separação de custo nas pernas do Binance Convert

## Bug corrigido

Uma conversão representa dois fatos independentes: a alienação do ativo enviado e a aquisição do ativo recebido. O motor usava o estado de custo genérico da transação durante os dois fatos. Assim, um lote enviado com custo pendente podia contaminar o lote recebido e todas as conversões posteriores.

O processamento agora resolve a evidência da aquisição antes de consumir a alienação e persiste os resultados separadamente. O estado da perna enviada nunca é usado como entrada para resolver a perna recebida.

## Campos por perna

- `from_quantity_status`, `from_cost_status` e `from_cost_evidence_type`: resultado do consumo FIFO do ativo enviado.
- `to_quantity_status`, `to_cost_status`, `to_cost_evidence_type` e `to_cost_basis_brl`: resultado independente da aquisição recebida.
- `cost_status`, `quantity_status` e `cost_evidence_type`: permanecem para operações de uma única perna. Em Convert ficam nulos, pois um único valor não descreve corretamente os dois lados.

Os campos originais importados (`from_*`, `to_*`, `total_brl`, data, referência e `import_metadata`) não são reescritos pelo recálculo. Os campos acima são resultados derivados.

## Resolução do custo recebido

A ordem atual de resolução é:

1. `import_metadata.brl_values.received_value_brl` positivo: custo `known`, evidência `binance_annual_csv_received_value_brl`.
2. BRL efetivamente enviado no Convert: custo `known`, evidência `binance_convert_brl_paid`.
3. `total_brl` positivo com `pricing_status=completed`: custo `estimated`, evidência `historical_market_quote`.
4. Nenhuma evidência aproveitável: custo `pending` e `to_cost_basis_brl=null`.

`total_brl` é ambíguo porque também pode representar valor de alienação ou uma cotação posterior. Por isso, isoladamente, ele não é promovido a custo documental conhecido. Uma estimativa continua bloqueando o uso fiscal do lote até ser comprovada.

Se a quantidade recebida estiver ausente ou não for positiva, `to_quantity_status` fica `incomplete`, nenhum lote é criado e uma lacuna `missing_received_quantity` é registrada. Uma entrada futura nunca cobre retroativamente uma saída anterior.

## Recálculo e auditoria

O recálculo continua sendo iniciado explicitamente pela rota já existente da tela fiscal. Não há recálculo em migration ou deploy. O motor processa por `date` UTC e usa `id` como desempate determinístico.

Cada execução cria um registro em `fifo_recalculation_runs` com usuário, ano solicitado, versão `convert-leg-cost-separation-v1`, início, término, estado e estatísticas. As lacunas continuam sendo atualizadas por usuário e transação, sem duplicação. Atualmente o motor recalcula integralmente o escopo do usuário/ano; atualização seletiva de apenas uma cadeia de lotes ainda não é suportada.

Depois do deploy e das migrations, o operador deve abrir o relatório do usuário e acionar **Recalcular FIFO**. Não há backfill silencioso. Para rollback de código, reverta a aplicação; a migration `000008` é reversível, mas removê-la também remove somente o histórico de execuções, nunca as transações Binance.

## Limitações mantidas

- Cotação histórica é estimativa, não documento de custo.
- Taxas em terceiro ativo mantêm o tratamento já existente; esta mudança não cria nova regra tributária para elas.
- Uma transação possui atualmente uma única linha em `fifo_inventory_gaps`. Se as duas pernas estiverem incompletas, a lacuna principal da alienação é preservada e a falha da aquisição é anexada em `context.received_leg`.
- Esta alteração não consulta CoinGecko, não adiciona endpoints Binance e não altera eventos brutos.

## Validação em staging

1. Execute `php artisan migrate:status` e confirme as migrations `000007` e `000008`.
2. Execute `php artisan migrate --pretend` e revise o SQL; depois aplique `php artisan migrate`.
3. Escolha um usuário com cadeia de Converts conhecida e acione o recálculo manual.
4. Confirme os campos `from_*` e `to_*`, a ausência de custo zero e o registro correspondente em `fifo_recalculation_runs`.
5. Verifique que o relatório bloqueia lacunas reais e deixa de bloquear pendências artificiais removidas.
6. Execute `php artisan test --filter=FifoInventoryGapTest` e, antes do merge, a suíte completa.
