# Estado da implementação

Change `whatsapp-codex-bot`: 7 de 38 tarefas concluídas (1.1–1.4, 2.1–2.2 e 2.4).
O bootstrap, contratos Evolution, Compose, configuração e persistência da política
de conversas estão implementados. Webhook, processamento, runner de execuções e
entrega ainda não estão completos; o bot não está pronto para uso.

## Sandbox validado em 2026-10-07

A recusa inicial de namespaces e montagens foi resolvida. A imagem instala
bubblewrap da distribuição; o override Compose aplica perfis seccomp e AppArmor
específicos ao runner. O operador carregou o perfil AppArmor no host. Permanecem
usuário não root, capabilities removidas e `no-new-privileges`, sem modo
privilegiado ou desativação do sandbox.

O comando correto nesta versão é `codex sandbox -- <comando>`, sem `linux`.
O teste inicial continha esse erro de sintaxe, que só ficou visível depois que
as recusas de namespaces e montagens foram corrigidas. O teste simples passou;
com perfil explícito, escrita em `/workspace` passou, escrita em `/state` foi
negada e abertura de socket foi negada com `EPERM`.

O impedimento observado era da configuração de segurança do container que
executará o Codex do bot. Não impede mais estes testes locais. A pausa inicial
foi prematura: era possível preparar e verificar uma configuração compatível.
Execução autenticada, supervisor, webhook e orquestração continuam pendentes.
Ver [runner-sandbox.md](runner-sandbox.md) para configuração, escopo e comandos.

## Verificação

Imagens Docker construídas e os sete serviços iniciados com configuração sintética.
Testes de contrato, configuração, esquema PostgreSQL e política de conversas,
incluindo concorrência real, duplicatas e reset aos 600 segundos, executados.
Resultado: 47 testes passaram, com 87 assertions. Pint passou.
Credenciais reais e pareamento WhatsApp não foram utilizados.
Os serviços de teste foram parados preservando seus volumes.
