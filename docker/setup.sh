#!/bin/sh
set -eu

bot_root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
cd "$bot_root"
. ./docker/operations.sh
bot_owner=
bot_skip_login=0
bot_skip_pairing=0
bot_qr_only=0
while [ "$#" -gt 0 ]; do
    case "$1" in
        --owner) [ "$#" -ge 2 ] || { echo 'Falta o número após --owner.' >&2; exit 1; }; bot_owner=$2; shift 2 ;;
        --skip-login) bot_skip_login=1; shift ;;
        --skip-pairing) bot_skip_pairing=1; shift ;;
        --qr-only) bot_qr_only=1; shift ;;
        --help|-h)
            echo 'Uso: ./docker/setup.sh [--owner NUMERO] [--skip-login] [--skip-pairing] [--qr-only]'
            echo 'Sem --owner, preserva o número existente ou solicita um número no terminal.'
            echo '--qr-only: gera o QR usando a stack já iniciada, sem build nem login.'
            exit 0 ;;
        *) echo "Opção desconhecida: $1" >&2; exit 1 ;;
    esac
done
command -v docker >/dev/null || { echo 'Instale Docker Engine e Compose antes de continuar.' >&2; exit 1; }
docker info >/dev/null
docker compose version >/dev/null
bot_evolution() {
    bot_compose run --rm --no-deps -T --volume "$bot_root/docker/setup-evolution.php:/setup-evolution.php:ro" app php /setup-evolution.php "$@"
}
if [ "$bot_qr_only" -eq 0 ]; then
    command -v apparmor_parser >/dev/null || { echo 'Instale AppArmor (apparmor_parser); consulte docs/runner-sandbox.md.' >&2; exit 1; }
    echo 'Carregando o perfil AppArmor do runner (pode solicitar senha sudo).'
    if [ "$(id -u)" -eq 0 ]; then
        apparmor_parser -r docker/runner/apparmor.profile
    else
        sudo apparmor_parser -r docker/runner/apparmor.profile
    fi
    # Não executa o .env como código shell.
    if [ -z "$bot_owner" ]; then
        bot_existing_owner=$(sed -n 's/^BOT_OWNER_NUMBER=//p' "$bot_env" 2>/dev/null || true)
        case "$bot_existing_owner" in ''|'""'|"''")
            if [ -t 0 ]; then
                printf 'Número autorizado, com país e DDD, somente dígitos: '
                read -r bot_owner
            fi ;;
        esac
    fi
    bot_has_volumes=0
    if [ -n "$(docker volume ls -q --filter "label=com.docker.compose.project=$bot_project")" ]; then
        bot_has_volumes=1
    fi
    bot_env_directory=$(dirname -- "$bot_env")
    [ -d "$bot_env_directory" ] || { echo 'A pasta do arquivo de ambiente não existe.' >&2; exit 1; }
    bot_env_directory=$(CDPATH= cd -- "$bot_env_directory" && pwd)
    bot_php_image=php:8.4.26-cli-bookworm@sha256:836ac6c672d1372a47c8fd61b625015bd07760f493fb2eaab2087636498a2b4b
    if docker image inspect "$bot_helper" >/dev/null 2>&1; then
        bot_php_image=$bot_helper
        echo 'Preparando o ambiente com o PHP da imagem local da aplicação.'
    elif ! docker image inspect "$bot_php_image" >/dev/null 2>&1; then
        echo 'Baixando a imagem PHP necessária para preparar o ambiente.'
        if ! docker pull "$bot_php_image"; then
            echo 'Não foi possível baixar o PHP. Verifique a conexão do Docker com registry-1.docker.io e auth.docker.io e execute novamente.' >&2
            exit 1
        fi
    fi
    docker run --rm --pull never --entrypoint php --user "$(id -u):$(id -g)" \
        --volume "$bot_root:/source:ro" --volume "$bot_env_directory:/environment" \
        "$bot_php_image" /source/docker/setup-environment.php "/environment/$(basename -- "$bot_env")" "$bot_owner" "$bot_has_volumes"
    bot_compose config --quiet
    echo 'Construindo imagens; a primeira execução pode demorar por downloads e compilação.'
    # Worker e scheduler reutilizam a imagem de app; não exportar a mesma tag em paralelo.
    bot_compose build app codex-runner
    bot_compose up -d --wait postgres redis codex-runner evolution app
    bot_compose exec -T app php artisan bot:validate-config --no-interaction
    bot_compose exec -T app php artisan migrate --force --no-interaction
    bot_evolution configure
    bot_compose up -d --wait worker scheduler
    if [ "$bot_skip_login" -eq 0 ]; then
        if bot_compose exec -T codex-runner codex login status; then
            echo 'Login Codex existente preservado.'
        elif [ -t 0 ]; then
            echo 'Autentique o ChatGPT no navegador usando o código exibido a seguir.'
            bot_compose exec codex-runner codex login --device-auth
            bot_compose exec -T codex-runner codex login status
        else
            echo 'Sem terminal interativo. Execute novamente sem --skip-login em um terminal.' >&2
            exit 1
        fi
    else
        echo 'Login ChatGPT adiado (--skip-login).'
    fi
fi
if [ "$bot_skip_pairing" -eq 0 ]; then
    if [ "$(bot_evolution state)" = open ]; then
        echo 'WhatsApp já conectado.'
        rm -f .codex/setup/whatsapp-qr.png
    else
        umask 077
        mkdir -p .codex/setup
        bot_qr_temporary=$(mktemp .codex/setup/whatsapp-qr.XXXXXX)
        bot_qr_payload=$(mktemp .codex/setup/whatsapp-qr-payload.XXXXXX)
        trap 'rm -f "$bot_qr_temporary" "$bot_qr_payload"' EXIT HUP INT TERM
        bot_evolution qr-json > "$bot_qr_payload"
        bot_compose exec -T evolution node -e '
            let input = "";
            process.stdin.setEncoding("utf8");
            process.stdin.on("data", chunk => input += chunk);
            process.stdin.on("end", () => {
                const payload = JSON.parse(input);
                process.stdout.write(Buffer.from(payload.base64.split(",")[1], "base64"));
            });
        ' < "$bot_qr_payload" > "$bot_qr_temporary"
        mv "$bot_qr_temporary" .codex/setup/whatsapp-qr.png
        echo 'Escaneie o QR em WhatsApp > Aparelhos conectados.'
        echo 'Use a conta do bot, diferente do número autorizado a enviar prompts.'
        if [ -t 1 ]; then
            if ! bot_compose exec -T evolution node -e '
                let input = "";
                process.stdin.setEncoding("utf8");
                process.stdin.on("data", chunk => input += chunk);
                process.stdin.on("end", () => {
                    const payload = JSON.parse(input);
                    if (typeof payload.code !== "string" || !payload.code) process.exit(1);
                    require("qrcode-terminal").generate(payload.code, {small: true});
                });
            ' < "$bot_qr_payload"; then
                echo 'Não foi possível desenhar o QR no terminal; use a imagem abaixo.' >&2
            fi
        fi
        rm -f "$bot_qr_payload"
        echo "QR também disponível em $bot_root/.codex/setup/whatsapp-qr.png"
        if [ -t 0 ] && [ "$bot_qr_only" -eq 0 ]; then
            printf 'Após escanear, pressione Enter para verificar: '
            read -r bot_pairing_confirmation
            if [ "$(bot_evolution state)" = open ]; then
                rm -f .codex/setup/whatsapp-qr.png
                echo 'WhatsApp conectado; QR removido.'
            else
                echo 'Pareamento ainda pendente. Para renovar o QR: ./docker/setup.sh --qr-only'
            fi
        fi
    fi
else
    echo 'Pareamento WhatsApp adiado (--skip-pairing).'
fi
bot_compose exec -T app php artisan bot:status --external
