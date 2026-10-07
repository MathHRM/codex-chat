# Conversas, ordem e workspace

Cada par instância/número tem uma cabeça de conversa. A aceitação bloqueia essa
linha no PostgreSQL, atribui hora UTC do servidor e incrementa a ordem local.
Timestamps recebidos da Evolution não determinam nem ordem nem inatividade.

A primeira entrada cria a geração 1 sem ID de sessão Codex. Uma entrada nova
aceita menos de 600 segundos após a anterior usa a mesma geração. Com intervalo
maior ou igual a 600 segundos, cria uma nova geração sem ID de sessão. O intervalo
é calculado durante a aceitação, e não quando o job começa.

Por exemplo, entradas às 12:00:00 e 12:09:59 pertencem à mesma geração. A segunda
renova a atividade; uma entrada às 12:19:59 inicia a geração seguinte. Se às
12:09:59 chegar apenas uma duplicata da entrada de 12:00:00, ela não renova
atividade: uma nova entrada às 12:10:00 já inicia outra geração.

A identidade única é instância/ID externo. Uma duplicata retorna a mensagem
original, com a mesma ordem e geração, mesmo se já existir uma geração posterior.
Recebimentos concorrentes do mesmo ID são aceitos uma única vez.

## Atraso e conclusão tardia

Uma mensagem aceita às 12:00:00 conserva sua geração mesmo se esperar na fila.
Uma entrada às 12:10:00 inicia a geração seguinte, inclusive quando a execução
antiga ainda estiver ativa. Quando a execução antiga terminar, seu resultado e
ID de sessão pertencem à geração antiga; não devem substituir a cabeça atual.
O processamento e a entrega devem respeitar a ordem local entre gerações.

`ConversationPolicyTest` verifica primeira entrada, 599 segundos, exatamente 600,
duplicatas, recebimentos concorrentes e conclusão tardia. O cenário de atraso
verifica as associações persistidas; a execução integrada dos jobs continua
pendente nas tarefas de orquestração.

## Preservação dos arquivos

Reset de conversa é uma troca lógica de contexto Codex. Não limpa o volume
`workspace`, não reverte alterações e não mata uma execução antiga. Reiniciar
containers também preserva os volumes. Remover volumes ou limpar arquivos é uma
ação distinta e não faz parte da política de inatividade. Sessões devem ser
retomadas por ID explícito; não selecionar implicitamente a última sessão.
