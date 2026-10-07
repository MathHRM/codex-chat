# Spec Delta

## Purpose

Oferecer um ambiente Docker reproduzível e persistente para operar a ponte WhatsApp e o Codex em uma VPS Azure com configuração documentada.

## ADDED Requirements

### Requirement: Complete Docker runtime
O projeto MUST fornecer um ambiente Docker Compose para todos os serviços da aplicação, Evolution API e suas dependências, incluindo Codex CLI. O host SHALL exigir apenas Docker, Compose e os pré-requisitos operacionais documentados, sem PHP, Node ou Codex instalados diretamente na VPS.

#### Scenario: Fresh VPS installation
- **WHEN** o operador segue o runbook numa VPS Linux Azure com Docker e Compose
- **THEN** ele consegue construir e iniciar todos os serviços, configurar a instância WhatsApp e executar o fluxo completo

### Requirement: ChatGPT account authentication
O sistema MUST usar login da conta ChatGPT no Codex CLI, com procedimento documentado para máquina sem navegador. Credenciais SHALL persistir entre recriações de containers, sem inclusão em imagens, código versionado, respostas ou logs da aplicação. A falta de login SHALL ser identificável pelo operador.

#### Scenario: First authentication
- **WHEN** o operador realiza o login documentado no container com uma conta que possui acesso ao Codex
- **THEN** o serviço consegue executar o CLI sem exigir uma chave da API OpenAI

#### Scenario: Container recreated
- **WHEN** o container do Codex é recriado preservando seus volumes
- **THEN** o login e as sessões persistidas continuam disponíveis

### Requirement: Execution containment
O processo do agente MUST operar sem privilégios de root e sem socket Docker, acesso amplo ao filesystem do host ou mounts das credenciais Evolution e banco da aplicação. O ambiente SHALL permitir execução no workspace e aplicar limites de CPU, memória e processos.

#### Scenario: Agent filesystem boundaries
- **WHEN** o agente executa comandos
- **THEN** ele pode alterar o workspace mas só recebe volumes dedicados à execução e instruções, sem socket Docker ou credenciais dos demais serviços

### Requirement: Persistent operational state
O ambiente MUST persistir estado de mensagens, conversas, entregas, autenticação e sessões WhatsApp/Codex e arquivos do workspace. O runbook SHALL documentar backup e restauração protegidos desses dados e separar recriação normal de containers de remoção deliberada de volumes.

#### Scenario: Normal container restart
- **WHEN** o operador reinicia ou recria serviços sem remover volumes
- **THEN** o estado necessário para continuar o atendimento permanece disponível

### Requirement: Restricted network exposure
O ambiente MUST manter banco, filas, executor e APIs administrativas fora da exposição pública direta. O runbook Azure SHALL definir regras de acesso, SSH restrito e uso de HTTPS quando um endpoint HTTP for exposto publicamente.

#### Scenario: Default network configuration
- **WHEN** o operador inicia a configuração padrão
- **THEN** serviços internos não possuem portas publicadas para a internet e o webhook Evolution comunica-se com Laravel pela rede Docker

### Requirement: Operational diagnostics
O ambiente MUST fornecer healthchecks, identificação de mensagens/execuções nos logs e procedimentos para detectar indisponibilidade da Evolution, login ausente, fila parada e entregas incertas, sem registrar segredos ou conteúdo completo dos prompts por padrão.

#### Scenario: Dependency failure
- **WHEN** a Evolution perde conexão ou o Codex não possui autenticação válida
- **THEN** o operador consegue identificar a falha por diagnóstico documentado sem consultar credenciais em logs
