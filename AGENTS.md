# Repository Guidelines

## Estrutura e organização

Este projeto conecta mensagens WhatsApp do proprietário à Evolution API e ao Codex CLI. O backend Laravel fica em `app/`: controllers recebem webhooks, services coordenam execuções e entregas, jobs processam filas e models persistem o estado. `runner/` contém a API e o supervisor PHP do Codex, separados do Laravel.

Rotas ficam em `routes/`, configurações em `config/` e migrations em `database/`. Views, JavaScript e CSS ficam em `resources/`; arquivos públicos, em `public/`. `tests/Unit`, `tests/Feature` e `tests/Fixtures` concentram testes e serviços simulados. `docker/` e os arquivos Compose definem a infraestrutura. Consulte `docs/` para operação e `openspec/` para especificações. `agent/AGENTS.md` contém instruções do runner.

## Build, testes e desenvolvimento

O fluxo documentado exige Docker Engine e Compose; PHP e Composer são instalados nas imagens.

```sh
docker build --target test -f docker/app/Dockerfile -t whatsapp-codex-app:test .
docker run --rm whatsapp-codex-app:test
```

Esses comandos constroem a imagem de testes e executam a suíte Laravel. Para subir a stack, siga a configuração, o carregamento do AppArmor e a sequência do `README.md`.

Com dependências locais disponíveis, `composer test` limpa o cache de configuração e executa os testes; `vendor/bin/pint --test` verifica o estilo PHP. `npm run dev` inicia o Vite e `npm run build` gera os assets. Use `composer install` para preservar as versões do lockfile.

## Estilo e nomenclatura

Siga `.editorconfig`: UTF-8, LF, quatro espaços e newline final; YAML usa dois espaços, exceto arquivos Compose, com quatro. Mantenha o estilo Laravel/Pint, classes PascalCase e métodos camelCase. Adapte alterações aos padrões do arquivo existente.

## Diretrizes de testes

A suíte usa PHPUnit 12, com SQLite em memória no ambiente padrão. Nomeie classes como `RunnerApiTest` e métodos descritivos `test_...`. Cubra comportamentos alterados, autenticação, duplicação e recuperação quando pertinentes. Não há limite percentual de cobertura configurado.

Siga `docs/docker-acceptance.md` para integração. Declare separadamente testes simulados e verificações com ChatGPT/WhatsApp reais.

## Commits e pull requests

O histórico usa Conventional Commits, como `feat(webhook): ...` e `test(docker): ...`. Use título curto e corpo com bullets para mudanças relevantes; não inclua Codex como coautor.

Diretriz para PRs: descreva o diff completo contra a base correta, comportamento, áreas alteradas, testes executados e verificações pendentes. Vincule issues aplicáveis.

## Segurança e configuração

Nunca versione `.env` ou credenciais. Gere segredos independentes e valide com `php artisan bot:validate-config`. Não exponha a saída interpolada de Compose nem remova volumes com `down -v`.
