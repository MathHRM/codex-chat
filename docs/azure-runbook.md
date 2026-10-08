# Operação em VPS Linux Azure

Use uma VM Linux x86_64 com kernel/AppArmor compatíveis com os perfis do runner,
disco persistente e espaço para imagens/volumes/backups. Reserve recursos para
PostgreSQL, Evolution e Laravel além dos 2 CPUs/2 GiB limitados do runner; ajuste
capacidade após medir a carga. Runtime do host: Docker Engine, plugin Compose,
Git/SSH e ferramentas de administração Linux. Não instalar PHP, Composer ou
Node no host.

## Preparação

1. Crie VM/rede no Azure com SSH por chave. No NSG, permita TCP 22 somente do
   IP/CIDR do operador, VPN ou Bastion. No firewall do host aplique a mesma
   restrição antes de fechar a sessão de administração. Não permita 5432, 6379,
   8000, 8080 ou 8081 de fora da VM.
2. Instale Docker Engine e `docker-compose-plugin` pelo repositório oficial da
   distribuição; confira `docker version` e `docker compose version`. Acesso ao
   daemon é administração da VM. Não monte seu socket em containers.
3. Clone a revisão desejada em `/srv/codex-bot`, configure `.env` conforme README
   (modo 600), carregue AppArmor e execute o smoke de sandbox. Perfis podem
   precisar adaptação explícita numa distribuição diferente; não recorrer a
   `privileged`, `unconfined` ou sandbox desabilitado.
4. Valide, construa e inicie banco/cache antes das migrações; inicialize o restante
   com os mesmos dois arquivos Compose:

```sh
sudo apparmor_parser -r docker/runner/apparmor.profile
docker compose -f compose.yaml -f compose.sandbox.yaml config --quiet
docker compose -f compose.yaml -f compose.sandbox.yaml build
docker compose -f compose.yaml -f compose.sandbox.yaml up -d --wait postgres redis
docker compose -f compose.yaml -f compose.sandbox.yaml run --rm --no-deps app php artisan bot:validate-config
docker compose -f compose.yaml -f compose.sandbox.yaml run --rm --no-deps app php artisan migrate --force
docker compose -f compose.yaml -f compose.sandbox.yaml up -d --wait app codex-runner evolution worker scheduler
```

Efetue [login ChatGPT](runner-login.md), configure/pareie a instância segundo
[evolution-contract.md](evolution-contract.md) e confira status. O webhook usa
URL Docker interna e segredo dedicado. Permita saída HTTPS necessária a
ChatGPT/WhatsApp e DNS no host; as ferramentas do agente continuam sem rede.

Fontes: [Docker no Ubuntu](https://docs.docker.com/engine/install/ubuntu/),
[Compose plugin](https://docs.docker.com/compose/install/linux/) e
[NSG/VM Linux Azure](https://learn.microsoft.com/en-us/azure/virtual-machines/linux/tutorial-virtual-network).

## Diagnóstico

```sh
docker compose -f compose.yaml -f compose.sandbox.yaml ps
docker compose exec worker php artisan bot:status --external
docker compose exec worker php artisan bot:health worker
docker compose exec scheduler php artisan bot:health scheduler
docker compose -f compose.yaml -f compose.sandbox.yaml exec codex-runner codex login status
docker compose logs --tail=100 app worker scheduler codex-runner
```

App verifica `/up`; PostgreSQL/Redis têm probes próprios. Worker e scheduler
exigem heartbeat recente (120s), mantido também durante pausa. Runner verifica
HTTP e seu entrypoint termina o serviço se o supervisor morrer. Saúde de HTTP
não comprova login ou pareamento: `login status` e `bot:status --external` são
checks separados. `whatsapp=open` indica conexão da instância. Não cole `.env`,
`docker inspect`, Compose interpolado ou logs privados do Codex em tickets.
Evolution tem logging do Docker desabilitado para não capturar QR/segredos.
Logs de domínio incluem somente IDs, estado e código de erro.

## Administração e eventual ingresso público

O Compose não publica portas. Prefira Artisan/CLI por SSH. Para navegador
administrativo Evolution, obtenha o IP atual apenas na rede interna e faça túnel
local por SSH. No terminal local (substitua IP interno/host):

```sh
ssh -N -L 127.0.0.1:18080:IP_INTERNO_EVOLUTION:8080 operador@vps
```

Acesse `http://127.0.0.1:18080` localmente; o túnel deixa de funcionar se o IP
mudar após recriação. Nunca exponha Evolution, runner, PostgreSQL ou Redis por
`ports: 0.0.0.0`. Se um webhook precisar acesso público, coloque um reverse proxy
com certificado TLS e publique **somente** HTTPS 443, restrito ao remetente quando
possível. Encaminhe apenas o caminho `/api/v1/webhooks/evolution`, limite corpo e
taxa e preserve `X-Webhook-Secret`. Não exponha a API administrativa da Evolution.
O fluxo atual é interno e não exige proxy nem domínio.

## Atualização, drenagem e rollback

Registre revisão e IDs das imagens atuais; retenha tags imutáveis antes de build.
Pause via `bot:status --pause`; aguarde `execucoes_ativas=0` com scheduler/worker
ativos. Resolva entregas uncertain para completar drenagem de respostas, ou
preserve-as explicitamente para reconciliação depois. Faça o backup consistente,
que deixa os serviços parados. Não elimine locks/estado para acelerar a parada.

```sh
docker compose exec app php artisan bot:status --pause
docker compose exec app php artisan bot:status
sh docker/backup.sh /srv/backups/codex-bot-ANTES-UPDATE
# Com as imagens antigas preservadas, faça checkout da revisão e build:
docker compose -f compose.yaml -f compose.sandbox.yaml build
docker compose -f compose.yaml -f compose.sandbox.yaml up -d --wait postgres redis
docker compose -f compose.yaml -f compose.sandbox.yaml run --rm --no-deps app php artisan migrate --force
docker compose -f compose.yaml -f compose.sandbox.yaml up -d --wait app codex-runner evolution worker scheduler
docker compose exec worker php artisan bot:status --external
docker compose exec app php artisan bot:status --resume
```

Recriações mantêm os volumes nomeados. O grace period de 3720s cobre o timeout
máximo e cleanup; uma parada forçada durante efeitos vira uncertain no próximo
boot, sem replay. Para rollback sem mudança de esquema, pare consumidores,
retorne à revisão/imagens conservadas e recrie. Havendo migração incompatível,
restaure **todo** o snapshot num projeto novo conforme backup-restore.md; não
execute rollback isolado do banco nem sobreponha volumes em uso. Mantenha somente
um stack conectado à conta/instância real. Não usar `down -v`.

## Reprodução sem serviços reais

[aceite Docker](docker-acceptance.md) reproduz instalação limpa em volumes novos,
crash, timeout após aceitação e backup/restauração. Usa as mesmas imagens e
isolamento do runner, com CLI/Evolution simulados. Não provisiona VM Azure nem
comprova regras do NSG no ambiente do operador.
