# Spec Delta

## Purpose

Executar instruções do proprietário com Codex CLI em um workspace persistente, mantendo continuidade de conversa e reiniciando o contexto após inatividade.

## ADDED Requirements

### Requirement: Execute prompt in dedicated workspace
O sistema MUST passar o texto aceito como prompt ao Codex CLI, permitindo executar comandos e alterar arquivos no workspace dedicado. O prompt SHALL ser tratado como entrada textual, sem interpolação em comandos shell da aplicação. Somente a resposta final SHALL ser publicada no WhatsApp.

#### Scenario: File operation requested
- **WHEN** o proprietário pede a criação ou alteração de um arquivo no workspace
- **THEN** o agente pode realizar a operação e devolver seu resultado textual

#### Scenario: Shell characters in prompt
- **WHEN** o texto contém aspas, quebras de linha, substituições shell ou opções aparentes de CLI
- **THEN** o conteúdo chega ao agente como texto e não altera os argumentos do processo iniciados pela aplicação

### Requirement: Versioned agent instructions
O sistema MUST disponibilizar instruções versionadas no código como `AGENTS.md` no diretório efetivo do agente, garantindo seu carregamento em execuções novas e retomadas. O arquivo SHALL orientar idioma, escopo do workspace e formato de resposta. Ausência ou conteúdo vazio SHALL impedir a execução.

#### Scenario: Instructions loaded
- **WHEN** uma execução nova ou retomada começa
- **THEN** o Codex carrega o arquivo de instruções configurado antes de processar o prompt

#### Scenario: Missing instructions
- **WHEN** o arquivo obrigatório não existe ou está vazio
- **THEN** o agente não é executado e uma falha operacional é registrada

### Requirement: Conversation continuity
O sistema SHALL associar mensagens aceitas à conversa da instância e número, retomando seu ID específico do Codex enquanto não expirada. A seleção de sessão MUST ser explícita e persistir entre reinícios.

#### Scenario: Follow up within activity window
- **WHEN** uma nova mensagem chega menos de 600 segundos após a anterior aceita e existe uma sessão válida
- **THEN** sua execução continua a mesma sessão e mantém o contexto anterior

#### Scenario: Restart within activity window
- **WHEN** os containers reiniciam e uma nova mensagem chega dentro da janela
- **THEN** o sistema usa o ID persistido para retomar a conversa correta

### Requirement: Reset after ten minutes without accepted input
O sistema MUST considerar expirada a conversa após 600 segundos sem nova mensagem aceita. A próxima mensagem SHALL iniciar uma nova sessão sem o histórico conversacional anterior. O prazo usa o instante de aceitação no servidor; respostas, duplicatas e eventos ignorados não renovam a atividade. O reset SHALL preservar os arquivos do workspace.

#### Scenario: Exact expiration boundary
- **WHEN** a próxima mensagem é aceita 600 segundos ou mais após a anterior
- **THEN** ela pertence a uma nova conversa e não retoma a sessão anterior

#### Scenario: Queue delay
- **WHEN** duas mensagens são aceitas com intervalo inferior a 600 segundos mas aguardam mais de 600 segundos na fila
- **THEN** ambas pertencem à mesma conversa definida na entrada

#### Scenario: Long running execution crosses expiration
- **WHEN** uma execução permanece ativa e uma mensagem chega após 600 segundos sem nova entrada aceita
- **THEN** a execução ativa termina normalmente e a nova mensagem aguarda sua vez para iniciar outra conversa

### Requirement: Serialized execution
O sistema MUST executar uma mensagem por vez no workspace e respeitar a ordem de aceitação, inclusive entre conversas anteriores e novas. Novas entradas SHALL aguardar sem cancelar ou modificar uma execução ativa.

#### Scenario: Burst of messages
- **WHEN** várias mensagens são aceitas durante uma execução
- **THEN** elas são processadas sequencialmente, cada qual produzindo seu resultado

### Requirement: Bounded execution and uncertain state recovery
O sistema MUST limitar duração e recursos de execução, encerrar a árvore de processos ao atingir timeout e registrar falhas. Execuções interrompidas após início SHALL ser marcadas incertas sem repetição automática de comandos com efeitos. Sessões perdidas ou inválidas SHALL gerar aviso e permitir uma nova sessão na próxima mensagem, sem repetição silenciosa do prompt atual.

#### Scenario: Execution timeout
- **WHEN** a execução excede o limite configurado
- **THEN** seus processos são encerrados, uma falha é registrada e o proprietário é informado

#### Scenario: Worker interruption after execution starts
- **WHEN** o worker reinicia e não consegue confirmar a conclusão de uma execução iniciada
- **THEN** a execução é marcada incerta, a sessão é invalidada e o prompt não é executado novamente automaticamente

#### Scenario: Session no longer available
- **WHEN** o CLI não consegue retomar o ID persistido
- **THEN** o proprietário recebe aviso de perda de contexto e a próxima entrada pode iniciar uma nova sessão
