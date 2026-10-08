# Aceite Docker com serviços simulados

Execute na raiz do repositório em Linux com Docker Compose e o perfil AppArmor
carregado conforme [runner-sandbox.md](runner-sandbox.md). Use um nome de projeto
novo e exclusivamente o ambiente sintético abaixo. Nenhum login ou pareamento real
é necessário. PostgreSQL, Redis, Laravel, filas e supervisor são reais; apenas o
executável Codex e a API Evolution são substituídos por fixtures.

```sh
acceptance() {
    docker compose --env-file tests/Fixtures/acceptance.env \
        -p whatsapp-codex-acceptance-fresh -f compose.yaml \
        -f compose.sandbox.yaml -f compose.acceptance.yaml "$@"
}
acceptance build app worker scheduler codex-runner acceptance
acceptance up -d --wait postgres redis app codex-runner evolution
acceptance exec -T app php artisan migrate --force --no-interaction
acceptance up -d --wait worker scheduler
acceptance run --rm --no-deps -T acceptance
```

O teste padrão verifica ingresso HTTP rápido, duplicação, arquivo alterado,
resposta, continuidade com sessão explícita, reset aos 600 segundos e rejeição
de outro número. Para verificar recuperação, execute as etapas seguintes na
mesma ordem e no mesmo projeto; os testes representam fases de um único cenário.

```sh
acceptance run --rm --no-deps -T acceptance php artisan test --compact tests/Feature/DockerAcceptanceTest.php --filter=test_prepare_interrupted_execution_after_effect
acceptance kill -s SIGKILL codex-runner
acceptance up -d --wait codex-runner
acceptance run --rm --no-deps -T acceptance php artisan test --compact tests/Feature/DockerAcceptanceTest.php --filter=test_reconcile_interrupted_runner_without_repeating_effect_and_start_new_session
acceptance run --rm --no-deps -T acceptance php artisan test --compact tests/Feature/DockerAcceptanceTest.php --filter=test_timeout_after_external_acceptance_requires_explicit_reconciliation
acceptance up -d --wait --force-recreate app worker codex-runner
acceptance run --rm --no-deps -T acceptance php artisan test --compact tests/Feature/DockerAcceptanceTest.php --filter=test_normal_recreation_and_pause_preserve_data_and_resume_pending_work
acceptance stop codex-runner evolution
acceptance run --rm --no-deps -T acceptance php artisan test --compact tests/Feature/DockerAcceptanceTest.php --filter=test_diagnostics_report_stopped_external_services_and_stale_heartbeats
```

O aceite de restauração usa o estado final dessas fases e marcadores sintéticos:

```sh
acceptance exec -T postgres psql -U administrator -d evolution -v ON_ERROR_STOP=1 -c "CREATE TABLE backup_fixture (value text NOT NULL); ALTER TABLE backup_fixture OWNER TO evolution; INSERT INTO backup_fixture VALUES ('evolution-backup-marker');"
acceptance exec -T app sh -c 'printf %s backup-storage-marker > storage/app/backup-marker'
COMPOSE_PROJECT_NAME=whatsapp-codex-acceptance-fresh BOT_ENV_FILE=tests/Fixtures/acceptance.env BOT_COMPOSE_EXTRA=compose.acceptance.yaml sh docker/backup.sh /absolute/new-backup
COMPOSE_PROJECT_NAME=whatsapp-codex-restore-fresh BOT_ENV_FILE=tests/Fixtures/acceptance.env BOT_COMPOSE_EXTRA=compose.acceptance.yaml sh docker/restore.sh /absolute/new-backup
restore() {
    docker compose --env-file tests/Fixtures/acceptance.env \
        -p whatsapp-codex-restore-fresh -f compose.yaml \
        -f compose.sandbox.yaml -f compose.acceptance.yaml "$@"
}
restore up -d --wait redis
restore run --rm --no-deps -T -e DOCKER_ACCEPTANCE=0 -e DOCKER_RESTORE=1 acceptance php artisan test --compact tests/Feature/DockerRestoreTest.php
```

Escolha um diretório absoluto novo para cada backup. Veja
[backup-restore.md](backup-restore.md) para operação e proteção dos arquivos.
Os consumidores ficam parados durante a inspeção. Preserve os volumes ao
terminar: `acceptance stop` e `restore stop`, sem `down -v`.

O resultado simulado não valida autenticação ChatGPT, pareamento WhatsApp,
entrega real ou provisionamento de uma VPS Azure.
