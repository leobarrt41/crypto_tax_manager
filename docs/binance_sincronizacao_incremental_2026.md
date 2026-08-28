# Sincronização incremental Binance — cobertura confirmada

## Objetivo

Orientar a sincronização fiscal incremental, com a API como fonte para os eventos que ela retorna e CSV como comprovação complementar dos tipos sem cobertura automática.

## Fontes oficiais consultadas

1. [How to Get Account Trade History via API — Binance Academy](https://www.binance.com/en/academy/articles/how-to-get-account-trade-history-via-api), atualizado em 17 de junho de 2026.
2. [Capital — Wallet REST API — Binance Developer Docs](https://developers.binance.com/en/docs/catalog/core-trading-wallet/api/rest-api/capital).
3. [Asset — Wallet REST API — Binance Developer Docs](https://developers.binance.com/en/docs/catalog/core-trading-wallet/api/rest-api/asset).
4. [How to Manage Your Account Wallet With the Binance API — Binance Academy](https://www.binance.com/en/academy/articles/how-to-manage-your-account-wallet-with-the-binance-api), atualizado em 9 de janeiro de 2026.

## Cobertura confirmada

| Evento | Endpoint/documentação | Limite relevante | Decisão de produto |
|---|---|---|---|
| Trades Spot | `GET /api/v3/myTrades` | exige `symbol`; até 1.000 por chamada; consultas por intervalo devem ter no máximo 24 horas | O sistema consulta pares ativos derivados de saldos e histórico já cadastrado, importa os trades encontrados e registra **cobertura parcial**; o CSV de Spot continua necessário para a conferência integral |
| Convert | `GET /sapi/v1/convert/tradeFlow` | o serviço atual divide consultas em janelas de até 89 dias | Importado automaticamente por competência e registrado como coberto quando a consulta conclui |
| Depósitos | `GET /sapi/v1/capital/deposit/hisrec` | janela máxima de 90 dias e paginação até 1.000 itens | Importado automaticamente por competência e registrado como coberto quando a consulta conclui |
| Saques | `GET /sapi/v1/capital/withdraw/history` | janela máxima de 90 dias e paginação até 1.000 itens | Importado automaticamente por competência e registrado como coberto quando a consulta conclui |
| Dividendos/recompensas distribuídas | `GET /sapi/v1/asset/assetDividend` | janela máxima de 180 dias e limite de 500 registros | Mantido como CSV/manual nesta fase, para separar corretamente dividendos, Earn, staking, rewards e airdrops |
| Dust conversion | `GET /sapi/v1/asset/dribblet` | últimos 100 registros, após 01/12/2020 | Mantido como CSV/manual até existir classificação tributária específica no produto |

## Segurança

A documentação oficial indica que uma chave de somente leitura basta para consultar históricos de depósitos e saques. A integração fiscal não deve habilitar permissão de saque, transferência ou trading. A lista de IPs permitidos deve ser usada no ambiente de produção.

## Semântica da cobertura

O painel não deve afirmar que um evento inexistiu quando o usuário não trouxe CSV. Deve apresentar:

- **Coberto pela API**: o endpoint aplicável foi consultado com sucesso naquela competência;
- **Cobertura parcial**: o endpoint não permite assegurar todos os eventos da categoria; o sistema pede o CSV correspondente;
- **Confirmado por CSV**: o usuário importou arquivo classificado para aquele tipo e competência;
- **CSV para confirmar**: categoria não coberta pela automação atual e sem arquivo/declaração de inexistência;
- **Falha na API**: a consulta não concluiu e precisa de CSV para fechar a competência.

A ausência de registros em uma consulta bem-sucedida é diferente da ausência de cobertura.
