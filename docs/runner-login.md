# Login ChatGPT do runner

A imagem fixa Codex CLI 0.160.1. O login usa a assinatura ChatGPT; não configure
`OPENAI_API_KEY` no runner. O volume `codex-session` conserva o cache entre
recriações. Os comandos abaixo usam os perfis de sandbox já carregados.

```sh
docker compose -f compose.yaml -f compose.sandbox.yaml exec codex-runner codex --version
docker compose -f compose.yaml -f compose.sandbox.yaml exec codex-runner codex login --help
docker compose -f compose.yaml -f compose.sandbox.yaml exec codex-runner codex login --device-auth
docker compose -f compose.yaml -f compose.sandbox.yaml exec codex-runner codex login status
```

Abra a URL e informe o código **no navegador do operador**, usando a conta
ChatGPT desejada. Habilite autenticação por código de dispositivo nas opções de
segurança da conta/workspace se necessário. Não copie URL/código para logs ou
conversas. O fluxo requer saída HTTPS do container e pode depender das permissões
do workspace ChatGPT.

Se o fluxo de dispositivo não estiver disponível, autentique o mesmo CLI numa
máquina confiável com navegador. Transfira seu `auth.json` por SSH diretamente
para o volume, sem imprimir o conteúdo. Com `cli_auth_credentials_store = "file"`
na máquina de origem, o arquivo fica em `$CODEX_HOME/auth.json` (padrão
`~/.codex/auth.json`). No terminal local, adapte host e caminho do projeto:

```sh
ssh operador@vps 'cd /srv/codex-bot && docker compose -f compose.yaml -f compose.sandbox.yaml exec -T codex-runner sh -c "umask 077; cat > /codex/auth.json; chmod 600 /codex/auth.json"' < ~/.codex/auth.json
```

O processo no container escreve como UID/GID 1000. Não faça commit, não monte
o diretório de autenticação do host e não publique o cache em backups abertos.
Não é necessário expor porta de callback. Se o login expirar, pause novas
execuções, repita o login e retome; prompts que já falharam não são reenviados.

Diagnóstico: `codex login status` comprova presença de login, mas não saldo,
permissões do modelo ou entrega WhatsApp. Erros `authentication` e `usage_limit`
invalidam o contexto da conversa e geram aviso curto; consulte também
[recuperação de execuções](execution-recovery.md).

Ajuda e status foram conferidos na imagem fixada: o fluxo `--device-auth` existe;
o ambiente local retornou `Not logged in`. Não houve autenticação real.
Referência: [autenticação oficial do Codex](https://learn.chatgpt.com/docs/auth).
