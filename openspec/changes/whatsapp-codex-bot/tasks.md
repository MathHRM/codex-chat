# Tasks

## 1. Bootstrap Laravel e ambiente Docker

- [x] 1.1 Criar aplicação Laravel com versões estáveis compatíveis de PHP e dependências travadas; verificar instalação, comando Artisan e execução da suíte inicial dentro de Docker.
- [x] 1.2 Fixar versão/digest da Evolution e registrar fixtures e contratos de webhook, headers, JID/LID, autenticação e envio da versão escolhida; verificar contra documentação/código da release e testes de contrato do adaptador.
- [x] 1.3 Criar Dockerfiles e Compose com app, worker, scheduler, runner, Evolution, PostgreSQL e Redis, volumes persistentes e usuários/bancos separados; verificar `docker compose config`, build e healthchecks dos serviços básicos.
- [x] 1.4 Criar configuração tipada e `.env.example` sem segredos para número, instância, autenticação, timeout e limites; verificar validação de configuração obrigatória e documentar bootstrap local no README.

## 2. Estado durável e política de conversa

- [x] 2.1 Criar migrações para cabeças de conversa, conversas, mensagens, execuções e partes de resposta com índices únicos e relações; verificar migração e rollback no PostgreSQL Docker e rejeição de duplicatas concorrentes.
- [x] 2.2 Implementar aceitação transacional com bloqueio, ordem local, timestamp UTC e geração de conversa por intervalo de 600 segundos; verificar primeira entrada, 599 segundos, exatamente 600 segundos, duplicatas e nova geração com conversa antiga ainda ativa.
- [ ] 2.3 Implementar despacho após commit e recuperação periódica de mensagens pendentes pelo scheduler; verificar crash simulado entre persistência/enqueue e que múltiplos despachos não criam execuções adicionais.
- [ ] 2.4 Documentar regras de inatividade, ordenação e preservação do workspace; verificar que exemplos de atraso na fila e resposta tardia correspondem aos testes de conversa.

## 3. Entrada WhatsApp autorizada

- [ ] 3.1 Implementar webhook autenticado, validação de instância/payload e limite de entrada; verificar segredo ausente/inválido, payload malformado, texto excessivo e ausência de atualização de atividade nesses casos.
- [ ] 3.2 Implementar autorização por número internacional e identidade confiável, extração de texto e filtros para mensagens próprias, grupos, broadcasts e anexos; verificar fixtures permitidas/rejeitadas, LID sem mapeamento e ausência de loops.
- [ ] 3.3 Integrar webhook com persistência e confirmação rápida após commit; verificar um recebimento aceito, duplicatas simultâneas e confirmação independente da duração do runner.
- [ ] 3.4 Documentar criação/pareamento da instância, configuração e verificação do webhook interno; verificar chamadas documentadas contra a Evolution fixada sem publicar sua API administrativa.

## 4. Executor isolado e integração Codex

- [ ] 4.1 Criar serviço mínimo do runner com token interno, `POST /runs`, `GET /runs/{id}` e armazenamento atômico persistente; verificar autenticação, conflito de conteúdo e POST repetido com o mesmo ID sem execução adicional.
- [ ] 4.2 Implementar supervisor único e estados duráveis do runner; verificar execução sequencial, retomada de solicitações ainda pendentes e classificação incerta sem replay de solicitações iniciadas antes de crash.
- [ ] 4.3 Instalar CLI com versão fixada e implementar spawn por argumentos/stdin, parsing JSONL e captura da resposta final; verificar CLI fake com caracteres shell, eventos de ferramenta, resposta final, exit code de erro e ID de sessão.
- [ ] 4.4 Implementar criação e retomada por ID explícito com diretório fixo e configuração `workspace-write`/aprovação `never`; verificar que nova conversa usa exec, continuação usa exec resume e nenhum caminho usa `--last` ou sessão efêmera.
- [ ] 4.5 Versionar `agent/AGENTS.md`, montar instruções e configuração somente para leitura e inicializar workspace Git; verificar bloqueio se instruções ausentes/vazias e registrar smoke de carregamento em execuções novas e retomadas.
- [ ] 4.6 Implementar timeout e término de grupo de processos, limites de entrada e classificação de erro; verificar processo fake que cria descendente, cleanup após timeout e nenhum resultado parcial tratado como sucesso.
- [ ] 4.7 Separar redes e variáveis do runner, configurar usuário não root, volumes dedicados e limites de CPU/memória/PIDs; verificar identidade, mounts, portas e indisponibilidade de socket Docker/credenciais Evolution/banco no ambiente do agente.
- [ ] 4.8 Documentar login ChatGPT no container, método de dispositivo, fallback seguro e diagnóstico; verificar ajuda e status da versão fixada, sem exigir chave de API nem exibir o cache de credenciais.

## 5. Orquestração de execuções e recuperação

- [ ] 5.1 Implementar cliente Laravel do runner com ID estável e reconciliação por polling; verificar perda da resposta ao POST e novo contato com mesmo ID sem duplicar comandos.
- [ ] 5.2 Implementar jobs com reivindicação atômica, lock global renovável e ordem de mensagens; verificar rajada, jobs duplicados e ausência de concorrência mesmo entre gerações diferentes.
- [ ] 5.3 Persistir IDs de sessão e resultados por conversa original; verificar atraso de fila, reinício de worker e conclusão tardia que não modifica a conversa atual mais recente.
- [ ] 5.4 Implementar falhas classificadas, execução incerta e invalidação de sessão sem replay; verificar sessão inválida, runner interrompido, timeout e próxima entrada iniciando sessão nova após aviso.
- [ ] 5.5 Documentar estados, limites e recuperação de execuções; verificar que o procedimento distingue reconciliação do runner de uma nova execução solicitada explicitamente pelo proprietário.

## 6. Entrega de resultados pela Evolution

- [ ] 6.1 Implementar adaptador de envio para instância/número originais usando apenas URL configurada; verificar requests de contrato, autorização e que dados recebidos não alteram URL ou destino.
- [ ] 6.2 Persistir resposta e dividi-la por caracteres Unicode em partes ordenadas; verificar texto curto, texto longo, emoji e reconstrução integral sem publicação de stderr/eventos de ferramentas.
- [ ] 6.3 Implementar entrega serial, retries limitados/backoff para falha definida e bloqueio em resultado incerto; verificar que nenhuma falha de envio reexecuta o Codex e que partes/mensagens posteriores não ultrapassam uma entrega pendente.
- [ ] 6.4 Implementar comandos Artisan para listar/reconciliar entregas incertas e avisos curtos em português para erros do agente e limite de entrada; verificar reenvio explícito, marcação como enviada e remoção de segredos/stack traces dos avisos.
- [ ] 6.5 Documentar estados de entrega, aceitação pela Evolution e procedimentos de reconciliação; verificar comandos em cenário controlado de timeout após aceitação externa.

## 7. Operação Docker e runbook Azure

- [ ] 7.1 Completar healthchecks e diagnóstico de app, filas, runner, login e conexão WhatsApp, com logs correlacionados; verificar falhas induzidas e ausência de credenciais/prompt completo nos logs padrão.
- [ ] 7.2 Documentar instalação na VPS Linux Azure, NSG/firewall, SSH restrito, administração via túnel e HTTPS para eventual exposição pública; verificar Compose sem portas internas publicadas e roteiro reproduzível numa máquina Docker limpa.
- [ ] 7.3 Documentar e verificar backup/restauração consistente de banco, workspace, sessões Codex/Evolution e estado do runner; executar restauração em ambiente isolado e confirmar dados e permissões.
- [ ] 7.4 Documentar atualização, pausa/drenagem, rollback e preservação de volumes; verificar recriação normal de containers e recuperação de estados sem executar `down -v`.

## 8. Validação integrada de aceite

- [ ] 8.1 Executar fluxo integrado Docker com Codex/Evolution simulados: ingresso, arquivo alterado, resposta, follow-up, reset aos 600 segundos e número rejeitado; verificar todos os cenários centrais das três specs em conjunto.
- [ ] 8.2 Exercitar reinícios de app/worker/runner e falhas de envio no ambiente integrado; verificar persistência, serialização, nenhuma repetição de efeitos e reconciliação de estados incertos.
- [ ] 8.3 Executar smoke com conta ChatGPT e instância WhatsApp reais quando credenciais operacionais forem disponibilizadas: login, instruções carregadas, alteração de arquivo, continuidade, reset e entrega; registrar resultados e qualquer dependência externa ainda não validada.
- [ ] 8.4 Executar suíte relevante, build e checks Compose finais; entregar relatório de aceite e confirmar que runbook permite operar a mesma imagem em VPS Azure sem dependências de runtime no host.
