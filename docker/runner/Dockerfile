FROM node:24.14.0-bookworm-slim@sha256:d8e448a56fc63242f70026718378bd4b00f8c82e78d20eefb199224a4d8e33d8 AS node
FROM php:8.4.26-cli-bookworm@sha256:836ac6c672d1372a47c8fd61b625015bd07760f493fb2eaab2087636498a2b4b
RUN apt-get update \
 && apt-get install -y --no-install-recommends git ca-certificates util-linux bubblewrap \
 && docker-php-ext-install pcntl \
 && rm -rf /var/lib/apt/lists/*
COPY --from=node /usr/local/bin/node /usr/local/bin/node
COPY --from=node /usr/local/lib/node_modules/npm /usr/local/lib/node_modules/npm
RUN ln -s /usr/local/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
 && npm install --global --ignore-scripts @openai/codex@0.160.1 \
 && codex_binary="$(find /usr/local/lib/node_modules/@openai/codex/node_modules/@openai -path '*/vendor/*/bin/codex' -type f)" \
 && test -n "$codex_binary" \
 && ln -s "$codex_binary" /usr/local/bin/codex-linux-sandbox \
 && npm cache clean --force \
 && groupadd --gid 1000 agent \
 && useradd --uid 1000 --gid 1000 --create-home agent \
 && mkdir -p /workspace /state /codex \
 && chown agent:agent /workspace /state /codex
COPY runner /runner
COPY docker/runner/entrypoint.sh /usr/local/bin/runner-entrypoint
USER agent
ENV CODEX_HOME=/codex
WORKDIR /workspace
EXPOSE 8081
ENTRYPOINT ["runner-entrypoint"]
