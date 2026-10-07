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
`www-data`. A configuração completa do Compose será adicionada nas próximas tarefas.
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
docker compose config --quiet
docker compose build
docker compose up -d postgres redis codex-runner evolution app
docker compose exec app php artisan bot:validate-config
docker compose exec app php artisan migrate --force
docker compose up -d worker scheduler
docker compose ps
```

O stack não publica portas. Webhook e APIs comunicam-se pela rede Docker;
administração via túnel será detalhada no runbook Azure. Volumes preservam banco,
Redis, workspace, estado do runner e sessões Codex/Evolution. Não use `down -v`.
Alterar as senhas de `.env` não altera usuários já criados no volume PostgreSQL;
faça rotação coordenada no banco e na configuração.

Timeout aceita 1 a 3600 segundos; prompt até 16.384 bytes UTF-8 e partes de resposta
até 3.000 caracteres Unicode. Inatividade permanece 600 segundos. O comando
`bot:validate-config` rejeita valores ausentes/fora dos limites sem mostrar seus
conteúdos. O fluxo completo do bot está em implementação e ainda não deve ser
usado com mensagens reais.

Contrato e limitações da versão Evolution: [docs/evolution-contract.md](docs/evolution-contract.md).
