# Geração de arquivos fiscais de criptoativos

A geração de arquivos fiscais é um processo **somente leitura** em relação a transações, cotações, FIFO e carteira. Ela pode gravar o artefato de download, mas não cria, altera ou exclui dados financeiros do usuário.

## Seleção de regra e leiaute

A competência, composta por ano e mês, é a única entrada usada para escolher a regra. Para competências até junho de 2026, aplica-se o leiaute legado da IN RFB nº 1.888/2019, versão 1.2 para operações por intermédio de exchange estrangeira. Para competências a partir de julho de 2026, aplica-se a DeCripto, sob leiaute próprio de exchange estrangeira.

O gerador nunca substitui um leiaute pelo outro. Se o leiaute da regra aplicável não estiver pronto, a resposta deve informar explicitamente a indisponibilidade e não produzir um arquivo do regime errado.

## Dados aceitos

A geração usa apenas transações já persistidas no mês, seus valores fiscais já registrados, a origem da operação e o cadastro da exchange. Não dispara importação, cotação, cálculo FIFO ou atualização de saldo.

Os tipos de operação são mapeados para registros específicos. Operações cujo tipo ou origem não seja representável são listadas como pendência de revisão e não são silenciosamente convertidas para outro registro.

## Arquivo de validação

A competência sem obrigatoriedade pode gerar download técnico somente quando houver operações representáveis. O resultado é identificado como **arquivo de validação — não transmitir**. A geração desse arquivo não transforma uma competência não obrigatória em declaração exigível e não transmite qualquer informação ao e-CAC.

## Critérios de validação

Os testes usam SQLite em memória ou banco explicitamente nomeado com `test`. A suíte bloqueia bases não isoladas antes de qualquer schema de teste. Os testes validam registros, ordem, campos obrigatórios, largura, valores, casas decimais, ausência de mutações nas transações e o corte normativo entre junho e julho de 2026.
