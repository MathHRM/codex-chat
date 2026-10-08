# Estado da implementação

Change `whatsapp-codex-bot`: **37 de 38 tarefas concluídas**. Implementação e aceite com serviços simulados
concluídos; apenas a tarefa 8.3 depende de login ChatGPT e pareamento WhatsApp
reais, ainda indisponíveis. A pedido do proprietário, essas integrações foram
validadas com fixtures, sem solicitar ou utilizar credenciais reais.

O webhook autentica, filtra identidades e conteúdo, persiste com deduplicação e
confirma sem aguardar o agente. Conversas usam inatividade de 600 segundos.
Execuções e entregas são serializadas e duráveis. O runner cria ou retoma sessões
por ID explícito, aplica instruções e sandbox, captura somente a resposta final
e encerra processos descendentes ao atingir timeout. Execuções interrompidas e
entregas ambíguas exigem reconciliação; efeitos iniciados não são repetidos
automaticamente. Há diagnóstico, pausa/drenagem e recuperação por Artisan.

## Evidências de aceite

- Suíte em PHP 8.4/PostgreSQL Docker: **98 testes passaram, 376 assertions**,
  sem warnings. Os sete testes dependentes das fases Docker foram executados
  separadamente no ambiente apropriado.
- Seis fases integradas: **74 assertions**, com HTTP, banco, Redis, worker,
  scheduler e supervisor reais; Codex e Evolution simulados. Verificam arquivo
  alterado, resposta, follow-up, reset, número rejeitado, queda forçada após efeito,
  reconciliação sem replay, timeout após aceitação externa, recriação de containers,
  pausa/retomada e diagnóstico de serviços parados/heartbeats vencidos.
- Backup consistente e restauração isolada: **48 assertions**. Preservados os
  dois bancos, oito mensagens/execuções/entregas, oito efeitos, Git, armazenamento
  Laravel, estado do runner, sessões, estado Redis e proprietários/modos dos
  arquivos. Consumidores permaneceram parados durante a inspeção.
- Build das imagens de produção, testes e runner concluído. O fluxo central
  foi repetido em outro projeto com volumes novos: 28 assertions passaram.
- Compose validado sem portas publicadas; sandbox do Codex previamente verificado
  com escrita permitida em `/workspace`, escrita negada em `/state` e socket
  negado. Runner não root, redes separadas e sem socket Docker.
- Pint e `git diff --check` passaram. Scripts operacionais passaram em `sh -n`.

## Operação e limites

[README](../README.md) cobre bootstrap. O
[aceite reproduzível](docker-acceptance.md) usa projetos e volumes novos;
[runbook Azure](azure-runbook.md) cobre instalação, NSG/firewall, SSH/túnel,
HTTPS opcional, diagnóstico, atualização e rollback. PHP, Composer e Node são
executados nas imagens; o host fornece Docker e ferramentas administrativas.
[Backup/restauração](backup-restore.md), [login](runner-login.md),
[recuperação de execução](execution-recovery.md) e
[recuperação de entrega](delivery-recovery.md) detalham operação.

Não foram provisionados recursos Azure nem verificados NSG ou TLS em uma VPS
real. O aceite simulado não comprova login ChatGPT, continuidade da sessão real,
pareamento WhatsApp ou entrega real. A tarefa 8.3 permanece aberta para registrar
esse smoke quando os serviços estiverem operacionais. Não houve implantação,
arquivamento da change ou remoção de volumes.
