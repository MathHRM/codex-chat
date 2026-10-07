# Spec Delta

## Purpose

Conectar mensagens privadas de um proprietário autorizado às execuções do agente e devolver seus resultados pelo WhatsApp via Evolution API.

## ADDED Requirements

### Requirement: Authenticated authorized message ingress
O sistema SHALL aceitar prompts somente de mensagens privadas de texto, de uma instância configurada e de um número autorizado, recebidas por webhook autenticado. Mensagens próprias, de grupos, broadcasts, outros números ou identidades não resolvidas SHALL ser ignoradas sem executar o agente.

#### Scenario: Authorized private text
- **WHEN** um webhook autenticado contém texto de uma conversa privada do número configurado na instância esperada
- **THEN** o sistema persiste a mensagem e confirma o recebimento sem esperar a execução do Codex

#### Scenario: Unauthorized or looping event
- **WHEN** o evento vem de outro número, grupo, broadcast, instância inesperada, identidade não resolvida ou tem indicação de mensagem própria
- **THEN** nenhum prompt, conversa ou resposta é criado

#### Scenario: Invalid webhook authentication
- **WHEN** a credencial do webhook está ausente ou inválida
- **THEN** a requisição recebe erro de autenticação e não altera o estado das mensagens

#### Scenario: Unsupported content
- **WHEN** o remetente autorizado envia áudio, imagem, documento ou texto vazio
- **THEN** o sistema ignora o evento e não renova o prazo de inatividade

### Requirement: Durable duplicate suppression
O sistema MUST identificar mensagens pela instância e identificador externo, garantindo que reentregas não iniciem outra execução nem renovem a atividade da conversa. Uma mensagem confirmada SHALL permanecer disponível para processamento após reinício.

#### Scenario: Concurrent duplicate deliveries
- **WHEN** o mesmo identificador é recebido duas vezes, inclusive simultaneamente
- **THEN** existe uma única mensagem aceita e no máximo uma execução é iniciada

#### Scenario: Restart after acknowledgement
- **WHEN** o serviço reinicia depois de confirmar uma mensagem antes de despachar seu processamento
- **THEN** a mensagem persistida é recuperada e processada

### Requirement: Input validation and size limit
O sistema MUST validar estrutura, identificador e tamanho do texto antes de aceitar um prompt. Payload inválido SHALL receber erro de validação sem criar estado. Texto autorizado acima do limite configurado SHALL gerar aviso ao proprietário sem iniciar execução nem renovar a atividade.

#### Scenario: Invalid payload
- **WHEN** um webhook autenticado não contém a estrutura ou identificador exigido
- **THEN** ele recebe erro de validação e nenhuma mensagem é aceita

#### Scenario: Oversized authorized text
- **WHEN** uma mensagem privada autorizada excede o limite de texto
- **THEN** o proprietário recebe aviso de tamanho e nenhum prompt ou atualização de atividade é criado

### Requirement: Reply to original authorized recipient
O sistema SHALL devolver a resposta final de cada execução ao remetente autorizado na instância original, preservando a ordem de mensagens aceitas. Respostas longas SHALL ser divididas em partes ordenadas, respeitando o limite configurado, sem truncar o resultado silenciosamente.

#### Scenario: Successful answer
- **WHEN** o Codex conclui uma execução com resposta final
- **THEN** a Evolution recebe o texto final dirigido ao número original, sem logs internos ou eventos de ferramentas

#### Scenario: Long answer
- **WHEN** a resposta ultrapassa o tamanho configurado por envio
- **THEN** todas as partes são enviadas em ordem e permitem reconstruir o conteúdo completo

### Requirement: Delivery recovery without agent replay
O sistema MUST persistir respostas e estado de entrega. Uma falha de envio SHALL permitir nova tentativa de entrega sem repetir o prompt no Codex. Resultados de envio incertos SHALL ser sinalizados para reconciliação, sem prometer entrega exatamente uma vez.

#### Scenario: Definite send failure
- **WHEN** a Evolution rejeita um envio de forma recuperável antes de aceitá-lo
- **THEN** somente a entrega é repetida com tentativas limitadas e atraso progressivo

#### Scenario: Ambiguous send timeout
- **WHEN** ocorre timeout e não é possível saber se a Evolution aceitou a parte
- **THEN** a entrega é marcada como incerta e não é reenviada automaticamente

### Requirement: User visible execution failure
O sistema SHALL responder ao proprietário com uma mensagem curta em português quando a execução falhar por autenticação, limite de uso, timeout ou erro do agente, sem incluir credenciais, comandos internos ou stack traces.

#### Scenario: Codex unavailable
- **WHEN** uma mensagem aceita não pode ser concluída pelo agente
- **THEN** o proprietário recebe uma indicação de falha pela mesma rota de entrega e o estado operacional registra a causa para diagnóstico
