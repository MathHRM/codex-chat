# Estado da implementação

Change `whatsapp-codex-bot`: 6 de 38 tarefas concluídas (1.1–1.4 e 2.1–2.2).
O bootstrap, contratos Evolution, Compose, configuração e persistência da política
de conversas estão implementados. Webhook, processamento, runner de execuções e
entrega ainda não estão completos; o bot não está pronto para uso.

## Bloqueio observado em 2026-10-07

No container não root do runner, com as restrições previstas no design:

```sh
docker compose exec -T codex-runner codex sandbox linux -- /bin/sh -c 'printf sandbox-ok'
```

O comando termina com código 1: `bwrap: No permissions to create a new namespace`.
O host também rejeita `unshare --user --map-root-user true` com
`unshare: write failed /proc/self/uid_map: Operation not permitted`.
`kernel.unprivileged_userns_clone=1` e `user.max_user_namespaces=60204`, mas
`kernel.apparmor_restrict_unprivileged_userns=1`; o container informa
`docker-default (enforce)`. Esses dados não identificam isoladamente qual camada
bloqueia o namespace. O runner usa o helper bubblewrap empacotado pelo Codex,
sem um executável `bwrap` da distribuição no PATH.

A [documentação oficial](https://learn.chatgpt.com/docs/sandboxing) descreve a
dependência de namespaces e os perfis AppArmor para bubblewrap. É necessário
validar uma política de host/container compatível com o sandbox antes de concluir
o runner e o smoke test. Não foram alteradas políticas do host, adicionadas
capabilities ou introduzido bypass. A escolha deve preservar as restrições do
design; mudar essas restrições exige revisar os artefatos OpenSpec.

Esse bloqueio impede validar a execução real do runner, mas não impede por si só
implementar componentes independentes ou testar a orquestração com um executor
simulado. A pausa registrada segue o fluxo da skill OpenSpec; não significa que
todo o restante do projeto dependa de corrigir namespaces primeiro. Ainda não
foi testada uma imagem com bubblewrap da distribuição nem um perfil restrito
específico; portanto não está demonstrado que seja necessário enfraquecer o
isolamento ou trocar de host. O diagnóstico deve distinguir AppArmor, seccomp e
eventuais restrições externas antes de escolher uma correção.

## Verificação

Imagens Docker construídas e os sete serviços iniciados com configuração sintética.
Testes de contrato, configuração, esquema PostgreSQL e política de conversas,
incluindo concorrência real, duplicatas e reset aos 600 segundos, executados.
Resultado: 47 testes passaram, com 87 assertions. Pint passou.
Credenciais reais e pareamento WhatsApp não foram utilizados.
Os serviços de teste foram parados preservando seus volumes.
