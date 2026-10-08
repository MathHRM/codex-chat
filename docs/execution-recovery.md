# Execuções e recuperação

Uma mensagem aceita recebe associação permanente à conversa e ordem local.
A execução possui UUID próprio e outro UUID estável para o runner. Há apenas
uma execução do workspace por vez, inclusive entre gerações de conversa.

| Estado | Significado e ação |
| --- | --- |
| pending | Persistida e ainda não iniciada. Scheduler recupera filas perdidas. |
| running | Reivindicada; o worker consulta o mesmo ID do runner. |
| succeeded | Resposta final e sessão persistidas; entrega independente. |
| failed | Falha definida; aviso curto, sessão invalidada e nenhum replay. |
| uncertain | Runner reiniciou após iniciar efeitos ou houve conflito; pode haver arquivos alterados. Nenhum replay. |

O runner persiste antes do `202`, executa pedidos pendentes em sequência e marca
como incertos os iniciados antes de um crash. Reiniciar o worker consulta
`GET /runs/{id}`; uma resposta perdida ao POST não cria outro ID. Se o runner
estiver indisponível, o estado permanece `running` e bloqueia novos comandos até
que seja possível reconciliar. Recupere **o mesmo volume** de estado do runner.
Não apague registros nem restaure apenas um dos volumes para desbloquear filas.

```sh
docker compose exec app php artisan bot:status
docker compose logs --tail=100 worker codex-runner
```

A consulta externa deve partir de `worker`, que pertence à rede do runner:

```sh
docker compose exec worker php artisan bot:status --external
```

Correlacione `execution_id`, `runner_id` e `part_id` nos logs; eles não incluem
prompt, resposta, stderr ou tokens. O estado privado do runner contém prompts e
respostas e deve ser protegido como o banco. Não publique dumps desses volumes.
Uma falha terminal invalida a sessão da **conversa original**. Uma conclusão tardia
não troca a cabeça da conversa nova. Depois do aviso, uma nova mensagem do
proprietário inicia outro comando explicitamente, com sessão nova após falha.
Isso pode repetir efeitos: o proprietário deve inspecionar arquivos antes de
pedir novamente. Reconciliação consulta o ID existente; nova mensagem cria outro.

Timeout configurável: 1–3600 segundos. Lease do workspace: timeout + 120 segundos,
renovado a cada polling. Prompt: até 16384 bytes UTF-8. Configuração, argumentos,
workspace e sessão são escolhidos pelo serviço; mensagens nunca viram shell ou
flags. `AGENTS.md` ausente/vazio impede spawn. Timeout encerra o grupo inteiro de
processos e nunca transforma resposta parcial em sucesso.

Pausa/drenagem: `bot:status --pause` bloqueia reivindicação de pendentes, mantendo
polling e entrega do que já iniciou. `bot:status --resume` libera a fila. A pausa
não rejeita novos webhooks: eles permanecem persistidos para processamento após
retomada. Veja [operação Azure](azure-runbook.md) antes de parar serviços.
