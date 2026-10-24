# Ledger e controle de execução — SEC-04.04

`SecurityExecutionLedger` coordena chamadas síncronas a adapters a partir de um [plano SEC-04.03](SECURITY-SCAN-PLAN.md). Persiste o plano antes do motor, registra tentativas e decide execução, reutilização, bloqueio ou replanejamento. Esta entrega fornece a API de coordenação; sua integração ao CLI público e aos adapters DAST continua nas próximas etapas.

## Armazenamento e trava

O construtor recebe diretório privado do ledger, diretório de envelopes `EvidenceStore` e prazo máximo de reutilização (padrão: uma hora; intervalo aceito: 1–86400 segundos). Ambos os diretórios devem ficar fora do consumidor. Não há credenciais, comandos, saída nativa bruta ou mensagens arbitrárias de exceção no journal.

| Arquivo | Conteúdo e publicação |
| --- | --- |
| `ledger.lock` | Trava `flock` exclusiva e não bloqueante. O inode permanece estável; outro processo recebe `busy`. |
| `ledger.json` | Journal versionado com tentativas, decisões e cobertura por identidade. Publicação atômica por arquivo temporário e rename, com modo 0600. |
| `plans/<digest>.json` | Snapshot do plano antes de reservar o grupo e iniciar o adapter. Divergência para o mesmo ID é recusada. |

Os diretórios têm modo 0700 e os arquivos modo 0600. Links simbólicos nos caminhos controlados são recusados. O armazenamento pressupõe filesystem local com `flock`/rename corretos e diretório confiável do usuário do NINFA. Não é um lock distribuído nem uma defesa contra alteração por esse mesmo usuário ou root.

A trava global permanece durante toda a chamada ao adapter. Ela serializa também grupos diferentes no mesmo ledger, uma escolha conservadora desta versão. Não há prazo de lease que permita iniciar outro processo enquanto o primeiro ainda executa. Se o motor ficar preso, o ledger permanece ocupado até o processo coordenador liberar a trava ou terminar; os adapters deverão impor seus limites reais de execução.

## Contrato da operação

```php
$ledger->run($plan, $executionId, $adapter, $contextValidated, $retryReason, $previousStopped);
```

`executionId` precisa identificar um grupo selecionado no plano. Unidades bloqueadas/excluídas não podem ser executadas por esta API. `contextValidated` é obrigatório: o chamador deve revalidar escopo, autorização, snapshot, papel e estado da sessão antes de **cada chamada**, inclusive para reutilizar cobertura. `false` retorna `context-invalid` e não chama o adapter.

O adapter recebe o grupo reservado e só deve retornar após comprovar que o motor terminou. Seu retorno contém `status: completed|failed` e `units` indexado por exatamente todas as chaves de identidade do grupo. Para cada unidade:

| Campo | Contrato |
| --- | --- |
| `coverage` | `complete`, `partial` ou `unknown`; findings vazios não substituem prova de cobertura. |
| `session_valid` | Booleano; para análise sem sessão aplicável, usar `true` após validar o contexto. |
| `valid_until` | Epoch UTC; o ledger limita o prazo ao máximo configurado a partir do registro. |
| `evidence` | Referência retornada por `EvidenceStore::put()`: nome, SHA-256 do envelope, expiração e `raw: false`. |

O ledger confere nome restrito ao namespace `safe-`, arquivo regular sem symlink, hash dos bytes do envelope, expiração, marca de sanitização e hash do payload armazenado. Conteúdo semanticamente correto, conclusão do motor e extensão real da cobertura ainda precisam ser comprovados pelo adapter; a validação dos hashes não comprova essas afirmações.

Operação, cobertura e validade da sessão são independentes. Um adapter pode retornar `completed` e cobertura `partial`; a operação terminou, mas essa unidade não pode ser reutilizada. Evidência inválida ou retorno fora do contrato gera tentativa `interrupted`, sem publicar cobertura nova.

As decisões de execução, reuso, replanejamento e bloqueio obtidas sob trava são persistidas com plano, grupo e horário, permitindo explicar a supressão posterior no relatório. `busy` é devolvido sem gravar, porque outro processo detém a trava. O journal não converte referências de evidência em prova de vulnerabilidade confirmada.

## Decisões e retomada

| Decisão | Significado |
| --- | --- |
| `busy` | Outra transação/motor detém a trava; nada é iniciado. |
| `completed` / `failed` | Adapter retornou conforme o contrato; cobertura de cada unidade permanece separada do estado da operação. |
| `interrupted` | Adapter lançou exceção ou devolveu resultado inválido. Apenas a classe da exceção é registrada. Não se presume parada do motor. |
| `reused` | Todas as unidades do grupo têm identidade verificável, operação e cobertura completas, sessão válida, prazo vigente e evidência íntegra. O adapter não é chamado. |
| `replan-required` | Só parte do grupo tem cobertura válida. O ledger não repete automaticamente as unidades já cobertas. |
| `retry-required` | Existe tentativa anterior sem cobertura reutilizável; novo trabalho exige motivo explícito. |
| `recovery-required` | Há tentativa `running`/`interrupted` anterior e falta comprovação externa de que o motor parou. |
| `unverifiable-context` | A unidade incompleta já foi tentada; retomada do mesmo plano é recusada. Reconstruir fatos e gerar novo plano. |
| `context-invalid` | Chamador não confirmou contexto/sessão atualizados. |

Uma tentativa é publicada como `running` **antes** do adapter. Se o coordenador terminar durante a execução, a próxima instância recupera a trava do sistema operacional, mas encontra essa reserva. Ela não inicia outro motor automaticamente. `previousStopped: true` só deve ser informado após comprovar encerramento do processo/job anterior e de seus descendentes; a simples ausência da trava não comprova isso.

Retry exige `retryReason` como código sem texto sensível (`expired-coverage`, `engine-error`, por exemplo). Recuperação de tentativa incerta exige **também** `previousStopped: true`. As reservas incertas recuperadas ficam marcadas `recovered`, com horário da confirmação, antes da nova tentativa. Falha/expiração não dispara loop automático de retry. O histórico é consultado por unidades envolvidas, além do ID de grupo, para impedir contornar essa exigência mudando o agrupamento.

Uma reserva incerta é uma barreira global: mudar contexto, grupo ou nonce não a contorna. Para liberar uma tentativa sem retomar suas unidades, `confirmStopped($attemptToken, $reason)` registra a confirmação externa de parada **daquela tentativa**, sem produzir cobertura. Isso permite reconstruir fatos e criar novo plano após contexto desconhecido. O método não verifica PIDs e não mata processos; a comprovação do encerramento permanece responsabilidade do adapter/operador.

Cobertura é indexada pela identidade SEC-04.02, permitindo reutilização entre runs com fatos iguais e comprovados. Mudança de snapshot, papel, vetor, configuração ou hashes produz nova identidade. Identidade incompleta nunca alimenta reutilização; repetir o mesmo plano incompleto é recusado. Journal inválido interrompe a operação e exige revisão; não é substituído silenciosamente por um ledger vazio.

Um registro criado no futuro em relação ao relógio atual é recusado, impedindo reuso após recuo do relógio. O prazo efetivo exige tanto `valid_until` quanto a idade máxima configurada na instância atual e a expiração da evidência. Reduzir o prazo do ledger invalida registros antigos que excedam a política atual. A sessão precisa ser revalidada externamente antes do reuso, mesmo que o registro anterior contenha `session_valid: true`.

Em grupos parcialmente cobertos, o planejador/adapter deve produzir novo plano que cubra somente a parte faltante, com hashes da configuração efetiva. Se o motor não permitir essa seleção (como tipos de issue no Psalm), não se deve inventar uma exclusão interna. A repetição complementar inevitável exige política explícita e registro de sobreposição; esta versão retorna `replan-required` e não a autoriza automaticamente.

## Fallback

`fallbackDecision($catalog, $plan, $unitKey, $alternative, $contextValidated)` avalia a fronteira, sem iniciar motores. Exige contexto válido e identidade verificável; cobertura válida retorna `fallback-not-needed`. Sem tentativa primária terminada, retorna `primary-not-settled`.

O catálogo v1 não aprova alternativas equivalentes: mesmo após falha/perda de cobertura válida, retorna `fallback-not-approved`. Falta de cobertura é condição necessária, mas não basta para substituir o teste. Habilitar fallback executável no futuro exige catálogo com prova versionada de equivalência, mapeamento das unidades, controles do adapter e seleção somente do que perdeu cobertura. Esta etapa não cria equivalência entre Composer/OSV, Psalm/Semgrep ou ZAP/Nuclei.

## Verificação e continuação

Responsável técnico: Codex. Início: `2026-10-24T14:41:21-03:00`. Fim e hashes na [EAP](EAP-SECURITY-ORCHESTRATION.md) e no [registro de validação](evidence/sec04-04-validation-2026-10-24.json).

`tests/security-execution-ledger.php`, integrado ao `profile-test`, usa adapters e evidências sintéticos. Exercita segunda instância e segundo processo recusados durante o motor; saída de processo no callback com reserva persistida; reabertura; retry/recuperação explícitos; reuso entre runs; contexto alterado/desconhecido; expiração; evidência corrompida, ausente e raw; sessão inválida; cobertura parcial/desconhecida; agrupamento alterado; journal inválido; permissões e fronteira do fallback. Nenhum consumidor é varrido.

A [SEC-04.05](SECURITY-NON-REPETITION.md) implementa a prova integrada de não repetição e a explicação no relatório. Integração aos adapters e ao fluxo público deverá usar este ledger, persistir plano antes dos motores e registrar resultados verificáveis. Nesta etapa, o CLI público continua com o comportamento anterior.

Na fronteira SEC-01/03, `completed|failed` são estados internos da tentativa no journal v1, não substitutos dos enums públicos de operação nem dos retornos nativos. O adapter deverá guardar operação detalhada, código nativo, parser, motivo e medidas de cobertura no manifesto/evidência sanitizada, e então fornecer a síntese ao ledger. O ledger não interpreta exit code nem promove `unknown` do parser a `complete`; o teste `security-stage-references.php` confronta capturas reais e saídas vazias com essa regra. O schema 3 público não foi alterado.
