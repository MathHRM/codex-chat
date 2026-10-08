# WhatsApp Codex Bot

Ponte Laravel entre mensagens privadas do proprietário, Evolution API e Codex CLI.
A implementação segue `openspec/changes/whatsapp-codex-bot`.

## Bootstrap e testes

Pré-requisitos do host: Docker Engine e Docker Compose. PHP, Composer e demais
dependências de runtime são instalados nas imagens.

Laravel 13, PHP 8.4.26 e Composer 2.10.3. As imagens base são fixadas por digest;
`composer.lock` fixa as dependências PHP. Use `composer install`, não `update`,
para reproduzir a instalação.

```sh
docker build --target test -f docker/app/Dockerfile -t whatsapp-codex-app:test .
docker run --rm whatsapp-codex-app:test php artisan --version
docker run --rm whatsapp-codex-app:test
```

O target `production` instala somente dependências de produção e executa como
`www-data`. Compose inclui app, worker, scheduler, runner, Evolution, PostgreSQL e Redis.
Nenhuma credencial operacional é incluída na imagem.

## Configuração e stack local

Copie `.env.example` para `.env`. Preencha o número internacional do proprietário
(somente dígitos) e a instância. Gere valores independentes para cada segredo;
senhas de banco/Redis devem usar hexadecimal para composição segura das URIs.
Use a imagem PHP fixada, sem instalar ferramentas de runtime no host:

```sh
cp .env.example .env
docker run --rm php:8.4.26-cli-bookworm@sha256:836ac6c672d1372a47c8fd61b625015bd07760f493fb2eaab2087636498a2b4b php -r 'echo "base64:".base64_encode(random_bytes(32)).PHP_EOL;'
docker run --rm php:8.4.26-cli-bookworm@sha256:836ac6c672d1372a47c8fd61b625015bd07760f493fb2eaab2087636498a2b4b php -r 'echo bin2hex(random_bytes(32)).PHP_EOL;'
chmod 600 .env
```

A primeira saída preenche `APP_KEY`; repita o segundo comando separadamente para
`POSTGRES_PASSWORD`, `APP_DB_PASSWORD`, `EVOLUTION_DB_PASSWORD`, `REDIS_PASSWORD`,
`EVOLUTION_API_KEY`, `WEBHOOK_SECRET` e `RUNNER_TOKEN`. Não reutilize valores.
Compose injeta `APP_DB_PASSWORD` como `DB_PASSWORD` na aplicação. Segredos não
entram no runner, exceto seu token dedicado. Não publique a saída de
`docker compose config` sem `--quiet`, pois contém variáveis interpoladas.

```sh
sudo apparmor_parser -r docker/runner/apparmor.profile
docker compose -f compose.yaml -f compose.sandbox.yaml config --quiet
docker compose -f compose.yaml -f compose.sandbox.yaml build
docker compose -f compose.yaml -f compose.sandbox.yaml up -d --wait postgres redis codex-runner evolution app
docker compose -f compose.yaml -f compose.sandbox.yaml exec app php artisan bot:validate-config --no-interaction
docker compose -f compose.yaml -f compose.sandbox.yaml exec app php artisan migrate --force --no-interaction
docker compose -f compose.yaml -f compose.sandbox.yaml up -d --wait worker scheduler
docker compose -f compose.yaml -f compose.sandbox.yaml ps
```

O stack não publica portas. Webhook e APIs comunicam-se pela rede Docker;
administração via túnel está descrita no runbook Azure. Volumes preservam banco,
Redis, workspace, estado do runner e sessões Codex/Evolution. Não use `down -v`.
Alterar as senhas de `.env` não altera usuários já criados no volume PostgreSQL;
faça rotação coordenada no banco e na configuração.

Timeout aceita 1 a 3600 segundos; prompt até 16.384 bytes UTF-8 e partes de resposta
até 3.000 caracteres Unicode. Inatividade permanece 600 segundos. O comando
`bot:validate-config` rejeita valores ausentes/fora dos limites sem mostrar seus
conteúdos. O runner exige as instruções versionadas em `agent/AGENTS.md`, montadas
somente para leitura, e mantém um workspace Git persistente. O host precisa ser
compatível com os perfis descritos em [docs/runner-sandbox.md](docs/runner-sandbox.md).

O fluxo completo é validado com serviços simulados. O smoke com conta ChatGPT e
WhatsApp reais depende de login e pareamento ainda não disponibilizados.

- [Login ChatGPT no runner](docs/runner-login.md)
- [Operação em VPS Azure](docs/azure-runbook.md)
- [Recuperação de execuções](docs/execution-recovery.md)
- [Reconciliação de entregas](docs/delivery-recovery.md)
- [Backup e restauração](docs/backup-restore.md)
- [Validação integrada Docker](docs/docker-acceptance.md)
- [Relatório de aceite](docs/implementation-status.md)

Contrato e limitações da versão Evolution: [docs/evolution-contract.md](docs/evolution-contract.md).
