# Contrato Evolution API 2.3.7

Imagem multiarch: `evoapicloud/evolution-api:v2.3.7@sha256:1bd8afc4a6cf48822e6cf02469aeae7bd35a12a6b616eacd1291926307f4d339`.
Manifest amd64: `sha256:456b4104b0ddffbb092d6b3c0560a4ae86fc3e014e885b882aca4b1b371dfc81`.
Código inspecionado: tag `2.3.7`, commit `cd800f2976e1e5b682fbf86a01ee4d85ae61f370`.
A imagem publicada informa `2.3.7` no package.json. O bundle publicado foi
inspecionado também para confirmar headers customizados e tratamento de LID.

Fontes da release:

- [Webhook e headers](https://github.com/evolution-foundation/evolution-api/blob/cd800f2976e1e5b682fbf86a01ee4d85ae61f370/src/api/integrations/event/webhook/webhook.controller.ts).
- [Recepção WhatsApp e LID](https://github.com/evolution-foundation/evolution-api/blob/cd800f2976e1e5b682fbf86a01ee4d85ae61f370/src/api/integrations/channel/whatsapp/whatsapp.baileys.service.ts).
- [Autenticação apikey](https://github.com/evolution-foundation/evolution-api/blob/cd800f2976e1e5b682fbf86a01ee4d85ae61f370/src/api/guards/auth.guard.ts).
- [Schema de envio](https://github.com/evolution-foundation/evolution-api/blob/cd800f2976e1e5b682fbf86a01ee4d85ae61f370/src/validate/message.schema.ts).
- [Rota de envio](https://github.com/evolution-foundation/evolution-api/blob/cd800f2976e1e5b682fbf86a01ee4d85ae61f370/src/api/routes/sendMessage.router.ts).

Configure `webhook.headers` com `X-Webhook-Secret`, `events: ["MESSAGES_UPSERT"]`
e `byEvents: false`. O body usa `event: "messages.upsert"`, `instance`,
`data.key.id`, `data.key.remoteJid`, `data.key.fromMe`, `data.messageType` e
`data.message`. Campos externos `sender`, `destination`, `server_url`, `apikey`
e timestamps não autorizam remetentes nem configuram o adaptador.

Identidade privada requer `<numero>@s.whatsapp.net` com número internacional
somente dígitos. A release converte LID usando `key.remoteJidAlt`, fornecido
pela conexão Baileys. O adaptador aceita esse mapeamento somente dentro do
webhook autenticado da instância configurada; LID sem alternativa privada
correspondente ao proprietário é ignorado. Mensagens próprias, grupos, status,
broadcasts e anexos/legendas são ignorados. Somente tipos `conversation` e
`extendedTextMessage` são prompts.

Envio: `POST /message/sendText/{instance}`, header `apikey`, JSON
`{"number":"<numero>","text":"<resposta>","linkPreview":false}`. Rota retorna
201 e objeto da mensagem com `key.id`. Aceitação não confirma entrega no telefone.
Não há retry automático no adaptador: a orquestração precisa distinguir falha
confirmada de resultado incerto antes de autorizar novo envio.

As fixtures são sintéticas, sem conta real. Os testes verificam os formatos
extraídos da release, filtros de identidade e URL/header/body do envio. Pareamento
e entrega real dependem do smoke operacional; não foram validados por esses testes.

## Logs da dependência

O código da release e seu bundle publicado contêm impressão incondicional da
mensagem recebida (`console.log(messageRaw)`), fora do filtro `LOG_LEVEL`.
`LOG_LEVEL=ERROR,WARN` sozinho não impede exposição de prompts. O Compose deve
desabilitar armazenamento dos logs desse serviço (`logging.driver: none`) e
usar diagnóstico autenticado de saúde/conexão. Logs correlacionados seguros são
produzidos pelo Laravel/runner. Não habilite logs brutos da Evolution em produção.
