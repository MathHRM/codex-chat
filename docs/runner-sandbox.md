# Sandbox Linux do runner

A imagem instala bubblewrap da distribuição (`/usr/bin/bwrap`). O Codex mantém
`workspace-write`, aprovação `never` e rede de ferramentas desabilitada. Docker
mantém usuário não root, `cap_drop: ALL`, `no-new-privileges`, filesystem somente
leitura e volumes dedicados. Não usar modo privilegiado ou sandbox desabilitado.

## Perfis preparados

`docker/runner/seccomp.json` deriva do perfil padrão Moby, commit
`2ceae35d351c156cb5a8efc0fdc4a08cf94569d8`, com permissão adicional somente para
`clone`, `unshare`, `setns`, `mount`, `umount2`, `pivot_root` e `sethostname`.
O filtro continua com ação padrão de rejeição. Checks de capabilities do kernel
continuam ativos; o container não recebe capabilities no namespace do host.

`docker/runner/apparmor.profile` deriva do template do mesmo commit. Mantém as
restrições a `/proc`, `/sys`, ptrace e comunicação com outros perfis, permitindo
namespaces e as montagens necessárias ao bubblewrap. Usa ABI AppArmor 4.0 e
permite sockets Unix explicitamente. As regras de mount são uma ampliação do
perfil Docker padrão, restrita ao runner; não são uma garantia de isolamento
absoluto. Exigem kernel, Docker e AppArmor compatíveis.

Fontes: [seccomp Moby](https://github.com/moby/profiles/blob/2ceae35d351c156cb5a8efc0fdc4a08cf94569d8/seccomp/default.json),
[template AppArmor Moby](https://github.com/moby/profiles/blob/2ceae35d351c156cb5a8efc0fdc4a08cf94569d8/apparmor/template.go)
e [sandbox OpenAI](https://learn.chatgpt.com/docs/sandboxing).

## Carregar e testar

Na raiz do projeto, com `.env` configurado:

```sh
sudo apparmor_parser -r docker/runner/apparmor.profile
docker compose -f compose.yaml -f compose.sandbox.yaml build codex-runner
docker compose -f compose.yaml -f compose.sandbox.yaml run --rm --no-deps \
  --entrypoint codex codex-runner sandbox linux -- \
  /bin/sh -c 'printf "sandbox-ok\n"'
```

O override aplica ambos os perfis somente ao runner. Usar os dois arquivos também
nos comandos de criação/recriação do runner após validar o teste. Se o perfil não
estiver carregado, Docker deve recusar iniciar o serviço; não há fallback para
`unconfined`. O serviço original em `compose.yaml` continua disponível sem esse
override, mas não passou no teste de sandbox neste host.

## Resultado local em 2026-10-07

- Build com bubblewrap 0.8.0: passou.
- Smoke no Compose padrão: ainda falha na criação de namespace.
- Perfil seccomp de teste permitindo apenas `unshare(CLONE_NEWUSER)`:
  `unshare --user --map-root-user true` passou com usuário não root e sem capabilities.
- Perfil seccomp preparado com AppArmor Docker padrão: o Codex avançou até
  `bwrap: Failed to make / slave: Permission denied`. O template Docker contém
  uma regra explícita `deny mount`; não houve registro dessa recusa no journal.
- Perfil genérico `bwrap` já existente no host: não resolve; journal registra
  recusa de `sys_admin` em `unpriv_bwrap`. Não usar esse perfil como substituto.
- Sintaxe do novo perfil: validada com `apparmor_parser --skip-kernel-load
  --skip-read-cache`. Compose com override: configuração validada.
- Teste do conjunto preparado: pendente de carregar o perfil no kernel.

Esses testes locais não dependem de login ChatGPT nem pareamento WhatsApp.
