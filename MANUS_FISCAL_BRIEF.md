# Manus Fiscal Brief (Brasil) - Crypto Tax Manager

## Objetivo
Implementar o **mínimo necessário** para apuração fiscal de cripto (Brasil), usando as transações já importadas (2022-2025), com foco em baixo custo de execução.

---

## Links oficiais da Receita Federal

### IRPF (PGD / Meu Imposto de Renda)
- https://www.gov.br/receitafederal/pt-br/centrais-de-conteudo/download/pgd/dirpf

### Ganhos de Capital (GCAP)
- https://www.gov.br/receitafederal/pt-br/centrais-de-conteudo/download/pgd/gcap

### Programas Geradores (hub)
- https://www.gov.br/receitafederal/pt-br/centrais-de-conteudo/download/pgd

### Novidades IRPF 2026 (referência)
- https://www.gov.br/receitafederal/pt-br/assuntos/meu-imposto-de-renda/novidades/2026

---

## Escopo mínimo (MANDATÓRIO)

1. Recalcular FIFO em lote para todo o histórico do usuário (ordem cronológica).
2. Normalizar eventos para entrada/saída fiscal:
   - `buy`, `deposit`, `receive` => entrada
   - `sell`, `withdrawal`, `send` => saída
   - `trade`/`convert` => tratar como evento com `from_asset -> to_asset` (saída da moeda enviada + entrada da recebida).
3. Preencher `profit_loss` nas saídas tributáveis.
4. Gerar resumo mensal por usuário:
   - `total_alienacoes_brl`
   - `lucro_realizado_brl`
   - `prejuizo_realizado_brl`
   - `resultado_liquido_brl`
5. Manter trilha auditável de consumo de lotes FIFO (por transação de saída).

---

## UI mínima (IMPLEMENTAR NO MENU RELATÓRIOS FISCAIS)

Implementar na seção já existente do menu lateral:
- `IN 1888` (manter como está)
- `Relatórios IR` (implementar agora)

### Página `Relatórios IR` (mínima)
1. Filtros: `Ano` e `Mês` (mês opcional).
2. Botão `Recalcular FIFO`.
3. Tabela simples com colunas:
   - `Mês`
   - `Alienações (R$)`
   - `Lucro (R$)`
   - `Prejuízo (R$)`
   - `Resultado Líquido (R$)`
4. Botão `Exportar CSV` do resumo exibido.
5. Sem design sofisticado; interface funcional e limpa.

---

## Fora de escopo (NÃO fazer agora)

- Refatoração grande de frontend.
- PDF/Excel avançado.
- Integração direta com software da Receita.
- Regras tributárias avançadas fora da apuração mensal base.

---

## Requisitos técnicos

1. Backend Laravel + frontend mínimo apenas para a página `Relatórios IR`.
2. Evitar mudanças de schema desnecessárias; se precisar, migração pequena e objetiva.
3. Garantir idempotência:
   - recálculo não pode duplicar lançamentos;
   - pode ser executado mais de uma vez com mesmo resultado.
4. Criar comando Artisan para recálculo:
   - `php artisan tax:recalculate-fifo {user_id?}`
5. Criar endpoint JSON para resumo mensal fiscal e endpoint para export CSV.

---

## Regras de dados (import atual)

- CSV anual Binance possui `id` único; deduplicação deve priorizar esse identificador.
- Datas devem respeitar o campo do arquivo (não usar `now()` como fallback silencioso).
- Quando faltar preço/fiat em `convert`, usar enriquecimento histórico para preencher `price`, `total_usdt`, `total_brl`.

---

## Critérios de aceite

1. Rodar recálculo FIFO para usuário com histórico 2022-2025 sem erro.
2. Página `Relatórios IR` acessível no menu lateral e funcional.
3. Resumo mensal com valores não zerados quando houver alienações.
4. Export CSV funcionando com os mesmos números da tela.
5. Reexecução do recálculo produz o mesmo resultado (idempotente).

---

## Entregáveis do PR

1. Código de cálculo FIFO em lote.
2. Comando Artisan de recálculo.
3. Endpoint/serviço de resumo mensal fiscal.
4. Página `Relatórios IR` mínima no menu fiscal.
5. Exportação CSV do resumo mensal.
6. Notas de validação com 2-3 exemplos reais (antes/depois).

