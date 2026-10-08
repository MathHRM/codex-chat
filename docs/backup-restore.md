# Backup consistente e restauração

Use um destino privado fora do repositório, com criptografia e cópia externa.
Banco, workspace, cache de autenticação, sessões Evolution, Redis e estado do
runner pertencem ao **mesmo ponto de recuperação**. Não misture backups de datas
diferentes: isso pode repetir efeitos ou perder continuidade. O backup conserva
UID/GID, modos e arquivos Git. Os dumps incluem o banco bot e o banco Evolution.

Salve separadamente `.env`, revisão do código, perfis carregados e imagens da
versão em uso num local protegido. `APP_KEY` e segredos de integração devem ser
os mesmos durante recuperação; senhas PostgreSQL serão criadas pelo novo `.env`.
Não publique o `auth.json` nem o conteúdo dos dumps. Scripts exigem destino
absoluto e novo; são executados na raiz do projeto.

```sh
sh docker/backup.sh /srv/backups/codex-bot-20261007
```

O procedimento pausa novas execuções, espera as ativas terminarem, para app,
scheduler, worker, runner, Evolution e Redis, faz dumps lógicos com PostgreSQL
ativo e arquiva todos os demais volumes. O stack permanece parado/pausado até
retomada explícita. Se drenar exceder 3720 segundos (`BOT_DRAIN_SECONDS`), aborta;
não remova locks nem force replay para produzir o backup. Webhooks durante a
parada dependem da política de reentrega da Evolution. Falhas incertas já
persistidas podem ser copiadas e reconciliadas depois.

```sh
COMPOSE_PROJECT_NAME=codex-bot-restore sh docker/restore.sh /srv/backups/codex-bot-20261007
```

A restauração recusa projetos com qualquer volume existente, valida SHA256,
cria volumes vazios, extrai preservando permissões, inicia apenas PostgreSQL e
restaura os dois bancos. Use código/imagens correspondentes ao backup e o mesmo
arquivo de ambiente. Antes de iniciar runner e consumidores, confira estado,
permissões e sandbox. Uma restauração de teste com sessões reais não deve
reconectar Evolution nem executar pending: mantenha esses consumidores parados
ou use serviços simulados em redes separadas.

```sh
docker compose -p codex-bot-restore -f compose.yaml -f compose.sandbox.yaml up -d redis app
docker compose -p codex-bot-restore exec app php artisan bot:status
docker compose -p codex-bot-restore -f compose.yaml -f compose.sandbox.yaml run --rm --no-deps --entrypoint sh codex-runner -c 'test "$(id -u)" = 1000; test -r /workspace/AGENTS.md; test -w /workspace; test -w /state; test -w /codex'
```

Para uma recuperação de produção, autorize somente um stack a usar a conta e a
instância. Com o original parado, inicie o restante, confirme login/pareamento,
reconcilie uncertain e retome com `bot:status --resume`. Não use `down -v`.
Os scripts aceitam `BOT_ENV_FILE`, `COMPOSE_PROJECT_NAME`, `BOT_COMPOSE_EXTRA`
(override de aceite) e `BOT_HELPER_IMAGE` para testes isolados. O override
`compose.acceptance.yaml` nunca deve ser usado na produção.
