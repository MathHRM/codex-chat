# Proposal

## Why

Permitir que o proprietário envie instruções pelo WhatsApp e receba o resultado de um agente capaz de executar comandos e alterar arquivos. O projeto greenfield será uma aplicação Laravel com Evolution API e Codex CLI, integralmente em Docker, preparada para uma VPS Azure.

## What Changes

- Receber mensagens de texto por webhook da Evolution API e aceitar somente um número configurado, em conversa privada.
- Processar cada mensagem como prompt do Codex CLI em um workspace dedicado, retornando a resposta final ao mesmo número pelo WhatsApp.
- Manter uma conversa por número/instância e iniciar outra após pelo menos 10 minutos sem novas mensagens aceitas.
- Usar autenticação do Codex pela conta ChatGPT, com credenciais e sessões persistidas fora da imagem.
- Versionar instruções do agente e carregá-las no workspace como `AGENTS.md`, nome reconhecido pelo Codex, atendendo ao pedido de um arquivo de instruções no código.
- Prover filas, deduplicação, serialização de execuções, tratamento de erros e entrega de respostas com recuperação controlada.
- Prover Docker Compose, persistência, healthchecks e documentação de instalação, login, pareamento WhatsApp, backup e operação na VPS Azure.
- Escopo inicial: um proprietário, uma instância WhatsApp e texto. Áudio, anexos, grupos, painel administrativo, múltiplos usuários e provisionamento automático da Azure ficam fora deste MVP.

## Capabilities

### New Capabilities

- `whatsapp-message-bridge`: entrada autenticada, autorização do remetente, deduplicação e envio dos resultados pela Evolution API.
- `codex-conversations`: execução do CLI, instruções do agente, continuidade, reset por inatividade e recuperação de falhas.
- `docker-vps-runtime`: ambiente Docker, isolamento, autenticação persistente e operação em VPS Azure.

### Modified Capabilities

Nenhuma; não existem especificações ou implementação anteriores.

## Impact

Serão introduzidos Laravel, PostgreSQL, Redis, Evolution API e uma imagem de worker Laravel com Codex CLI e ferramentas de execução. Novos componentes: webhook, adaptador Evolution, jobs, estado de conversas/execuções/entregas, workspace e instruções, Dockerfiles, Compose e runbook Azure. A operação depende de uma conta ChatGPT com acesso ao Codex, pareamento da instância WhatsApp e conectividade de saída. Esta mudança cria somente os artefatos de planejamento; implementação e implantação ocorrerão em etapas posteriores.
