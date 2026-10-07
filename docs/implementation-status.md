# Estado da implementação

Change `whatsapp-codex-bot`: 7 de 38 tarefas concluídas (1.1–1.4, 2.1–2.2 e 2.4).
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
bloqueia o namespace. Nesse primeiro teste o runner usava o helper bubblewrap
empacotado pelo Codex, sem um executável `bwrap` da distribuição no PATH.

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
foi demonstrado que seja necessário trocar de host ou desativar o sandbox.
O diagnóstico inicial não havia testado uma imagem com bubblewrap da distribuição
nem um perfil restrito específico. Portanto, não estava demonstrado que seria
necessário enfraquecer o
isolamento ou trocar de host. O diagnóstico deve distinguir AppArmor, seccomp e
eventuais restrições externas antes de escolher uma correção.

## Diagnóstico atualizado

Bubblewrap 0.8.0 foi instalado na imagem e o build passou. O perfil seccomp padrão
foi identificado como barreira à criação de namespaces: permitir explicitamente
`unshare(CLONE_NEWUSER)` fez esse teste passar no container sem capabilities.
Com as operações de bubblewrap permitidas em seccomp, o CLI avança até uma
recusa de mount. O template AppArmor Docker contém `deny mount`.

Foram preparados um perfil seccomp e um perfil AppArmor específicos, com override
Compose opt-in. Sintaxe AppArmor e Compose foram validados. O smoke do conjunto
depende de carregar o perfil AppArmor no kernel; `sudo -n` exige autenticação
interativa neste host. Nenhuma política do host foi alterada. Ver
`runner-sandbox.md` para comandos, escopo das permissões e resultados exatos.

## Verificação

Imagens Docker construídas e os sete serviços iniciados com configuração sintética.
Testes de contrato, configuração, esquema PostgreSQL e política de conversas,
incluindo concorrência real, duplicatas e reset aos 600 segundos, executados.
Resultado: 47 testes passaram, com 87 assertions. Pint passou.
Credenciais reais e pareamento WhatsApp não foram utilizados.
Os serviços de teste foram parados preservando seus volumes.
