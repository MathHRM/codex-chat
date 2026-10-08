# Entrega e reconciliação

As partes preservam integralmente o texto final em ordem Unicode. Instância e
número vêm da cabeça persistida e devem coincidir com a configuração autorizada;
a URL é sempre a configurada. Eventos de ferramentas e stderr não são enviados.

| Estado | Tratamento |
| --- | --- |
| pending | Aguarda sua vez ou backoff; não ultrapassa partes anteriores. |
| sending | Reivindicada antes da chamada HTTP. Crash nessa etapa torna-a incerta. |
| sent | Evolution aceitou e retornou `key.id`; não comprova leitura pelo destinatário. |
| failed | Rejeição definida após até 3 tentativas ou destino inválido; bloqueia seguintes. |
| uncertain | Timeout, resposta ambígua ou crash: pode já ter sido enviada. Bloqueia seguintes. |

Rejeições HTTP 400/401/403/404/422/429 têm no máximo três tentativas com espera
5 e 10 segundos. Timeout, 5xx e sucesso sem ID não causam retry automático.
Falha de envio jamais executa Codex novamente. A entrega mais antiga bloqueia
partes e respostas posteriores da mesma cabeça até reconciliação explícita.

```sh
docker compose exec app php artisan bot:delivery
# Após conferir no WhatsApp/Evolution e obter o ID externo:
docker compose exec app php artisan bot:delivery UUID_DA_PARTE --sent=ID_EXTERNO
# Se decidir reenviar, aceitando a possibilidade de duplicata:
docker compose exec app php artisan bot:delivery UUID_DA_PARTE --retry
```

A listagem mostra IDs, estado, tentativas e código seguro, sem conteúdo.
`--sent` marca a parte com o ID confirmado sem enviar; `--retry` retorna a parte
para pending, zera o orçamento e avisa do risco de duplicata. As opções são
mutuamente exclusivas; a alteração usa o mesmo lock da entrega. Corrija login,
pareamento ou configuração antes de autorizar retry.

O teste integrado configura a Evolution simulada para registrar a aceitação e
responder depois de 20 segundos, acima do timeout HTTP de 15 segundos. Verifica
uma única aceitação, estado uncertain, bloqueio da próxima resposta, confirmação
por `--sent` e liberação da sequência sem reexecutar o agente. Não se trata de
comprovação de entrega numa instância WhatsApp real.
