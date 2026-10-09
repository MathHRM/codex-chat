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

O ingresso Laravel implementado é `POST /api/v1/webhooks/evolution`.
Configure `webhook.headers` com `X-Webhook-Secret`, `events: ["MESSAGES_UPSERT"]`
e `byEvents: false`. O body usa `event: "messages.upsert"`, `instance`,
`data.key.id`, `data.key.remoteJid`, `data.key.fromMe`, `data.messageType` e
`data.message`. Campos externos `sender`, `destination`, `server_url`, `apikey`
e timestamps não autorizam remetentes nem configuram o adaptador.

Identidade privada requer `<numero>@s.whatsapp.net` com número internacional
somente dígitos. A release converte LID usando `key.remoteJidAlt`, fornecido
pela conexão Baileys. O adaptador aceita esse mapeamento somente dentro do
webhook autenticado da instância configurada; LID sem alternativa privada
correspondente ao proprietário é ignorado. Mensagens do proprietário são aceitas
com `fromMe: true` ou `false`, permitindo usar a conversa consigo mesmo. Grupos,
status, broadcasts e anexos/legendas são ignorados. Somente tipos `conversation` e
`extendedTextMessage` são prompts.

Todas as mensagens enviadas pelo projeto recebem o prefixo reservado
`🤖 Codex:` seguido de uma quebra de linha, inclusive avisos e cada parte de
respostas longas. O ingresso ignora textos que começam com esse marcador
(desconsiderando espaços iniciais), independentemente de `fromMe`. Isso evita
loops mesmo se o webhook chegar antes da confirmação HTTP do envio. O marcador
é uma convenção de identificação, não autenticação; o proprietário não deve
usá-lo no início de prompts.

Envio: `POST /message/sendText/{instance}`, header `apikey`, JSON
`{"number":"<numero>","text":"🤖 Codex:\n<resposta>","linkPreview":false}`. Rota retorna
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

## Criar instância e configurar webhook

Execute no diretório do projeto após configurar `.env`, subir as dependências
com Compose e migrar o banco. Para usar a conversa consigo mesmo, pareie a conta
do proprietário e configure `BOT_OWNER_NUMBER` com esse mesmo número internacional,
somente dígitos. Também é possível manter uma conta separada para o bot. Os comandos
usam o PHP da imagem app e a rede Docker, sem publicar a API administrativa.

Crie a instância apenas uma vez. Se já existir, pule a chamada `/instance/create`
no bloco abaixo e mantenha as chamadas de configuração/verificação. Não exclua
uma instância operacional para corrigir configuração.

```sh
docker compose run --rm --no-deps -T app php <<'PHP'
<?php
require '/app/vendor/autoload.php';
$app = require '/app/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$client = Illuminate\Support\Facades\Http::baseUrl(config('bot.evolution_url'))
    ->withHeaders(['apikey' => config('bot.evolution_key')])
    ->connectTimeout(3)->timeout(20);
$instance = rawurlencode(config('bot.instance'));
$created = $client->post('/instance/create', [
    'instanceName' => config('bot.instance'),
    'integration' => 'WHATSAPP-BAILEYS', 'qrcode' => false,
]);
if ($created->status() !== 201) {
    throw new RuntimeException('Criação recusada: HTTP '.$created->status());
}
$webhook = [
    'enabled' => true, 'url' => 'http://app:8000/api/v1/webhooks/evolution',
    'headers' => ['X-Webhook-Secret' => config('bot.webhook_secret')],
    'byEvents' => false, 'base64' => false, 'events' => ['MESSAGES_UPSERT'],
];
$configured = $client->post('/webhook/set/'.$instance, ['webhook' => $webhook]);
if ($configured->status() !== 201) {
    throw new RuntimeException('Configuração recusada: HTTP '.$configured->status());
}
$found = $client->get('/webhook/find/'.$instance);
if ($found->status() !== 200) {
    throw new RuntimeException('Consulta recusada: HTTP '.$found->status());
}
$expectedFields = $webhook;
unset($expectedFields['byEvents'], $expectedFields['base64']);
$expectedFields += ['webhookByEvents' => false, 'webhookBase64' => false];
foreach ($expectedFields as $field => $expected) {
    if ($found->json($field) !== $expected) {
        throw new RuntimeException('Webhook divergente no campo '.$field);
    }
}
echo "Instância criada e webhook verificado.\n";
PHP
```

O bloco imprime somente confirmação ou status HTTP. Não imprima respostas
integrais de criação/consulta: podem conter token e segredo. `byEvents: false`
mantém a URL exata, sem sufixo de evento. Só `MESSAGES_UPSERT` é habilitado.

## Parear WhatsApp e verificar conexão

Salve o QR em PNG protegido; não imprima QR ou credenciais no terminal. Na VPS,
transfira por SCP sobre SSH restrito e abra localmente. Nenhuma porta da Evolution
precisa ser publicada. Execute novamente se o QR expirar.

```sh
umask 077
docker compose run --rm --no-deps -T app php > whatsapp-qr.png <<'PHP'
<?php
require '/app/vendor/autoload.php';
$app = require '/app/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$response = Illuminate\Support\Facades\Http::baseUrl(config('bot.evolution_url'))
    ->withHeaders(['apikey' => config('bot.evolution_key')])
    ->connectTimeout(3)->timeout(20)
    ->get('/instance/connect/'.rawurlencode(config('bot.instance')));
$base64 = $response->json('base64');
if ($response->status() !== 200 || !is_string($base64)
    || !str_starts_with($base64, 'data:image/png;base64,')) {
    fwrite(STDERR, "QR indisponível; consulte estado e conectividade da Evolution.\n");
    exit(1);
}
$png = base64_decode(substr($base64, strlen('data:image/png;base64,')), true);
if ($png === false) {
    exit(1);
}
echo $png;
PHP
```

No telefone da conta do bot, abra **Aparelhos conectados → Conectar um aparelho**
e escaneie o QR. Remova `whatsapp-qr.png` após o pareamento; não o versione. Se
já conectado, `/connect` pode não retornar QR. Consulte o estado separadamente:

```sh
docker compose run --rm --no-deps -T app php <<'PHP'
<?php
require '/app/vendor/autoload.php';
$app = require '/app/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$response = Illuminate\Support\Facades\Http::baseUrl(config('bot.evolution_url'))
    ->withHeaders(['apikey' => config('bot.evolution_key')])
    ->connectTimeout(3)->timeout(10)
    ->get('/instance/connectionState/'.rawurlencode(config('bot.instance')));
if ($response->status() !== 200) {
    throw new RuntimeException('Consulta recusada: HTTP '.$response->status());
}
echo json_encode(['state' => $response->json('instance.state')], JSON_THROW_ON_ERROR).PHP_EOL;
PHP
```

`open` confirma conexão; `close`/`connecting` não confirmam pareamento. Quando
runner e entrega estiverem completos, envie texto privado do número autorizado
e confirme persistência e resposta. Outro número deve produzir zero mensagens,
conversas e respostas. Os testes HTTP verificam esses filtros sem conta real.

Verificação local em 2026-10-07: imagem 2.3.7, instância temporária criada (201),
webhook configurado (201) e consultado (200), incluindo header, URL e eventos;
`connect` (200) retornou QR e estado `connecting`; a instância temporária foi
removida. Pareamento de conta e entrega reais ficam para o smoke da tarefa 8.3.
