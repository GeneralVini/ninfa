# Prova de coordenação e não repetição — SEC-04.05

A suíte integrada exercita catálogo → identidade → plano → ledger → explicação de decisões. O contrato é: uma unidade verificável idêntica é agendada uma vez no plano; reuso entre execuções só ocorre após o ledger validar contexto, cobertura e evidência. Fontes complementares permanecem independentes. Identidades desconhecidas não sustentam deduplicação.

Esta prova valida a API de coordenação com adapters e evidências sintéticos. Não comprova que cada scanner real respeita seletores, limites, autenticação e cobertura: os adapters deverão demonstrar esses fatos na SEC-05 e usar o coordenador no fluxo público. A SEC-04 concluída não equivale a DAST público integrado ou garantia universal contra repetição dentro dos motores.

## Matriz de verificação

| Contrato | Verificação e resultado esperado | Teste |
| --- | --- | --- |
| Duplicação exata | A mesma capacidade/identidade aparece duas vezes; uma proposta é excluída com justificativa e referência. | `security-scan-plan.php`, `security-non-repetition.php` |
| Equivalência não comprovada | Nenhuma regra é excluída só por CWE, arquivo, URL ou objetivo semelhante; catálogo v1 não aprova equivalência cruzada. | `capability-catalog.php`, `security-test-identity.php`, `security-non-repetition.php` |
| Complementaridade SCA/SAST | Composer/OSV e Semgrep/Psalm são chamados separadamente, mesmo com possíveis achados correlacionáveis. | `security-non-repetition.php` |
| Bundle Nuclei | Os 12 matchers de headers permanecem juntos; sobreposição com ZAP produz aviso, sem suprimir o template. | `security-scan-plan.php`, `security-non-repetition.php` |
| Execução compartilhada | Dois tipos de issue Psalm compartilham uma passagem, com duas unidades de cobertura preservadas. | `security-scan-plan.php`, `security-non-repetition.php` |
| Contextos distintos | Projeto, snapshot, papel, sessão, método, vetor e hashes distinguem unidades; papel alterado chama novo adapter. | `security-test-identity.php`, `security-non-repetition.php` |
| Contexto desconhecido | Não há identidade reutilizável; repetir o mesmo plano incompleto é recusado. | `security-test-identity.php`, `security-execution-ledger.php`, `security-non-repetition.php` |
| Retomada verificável | Segundo run com os mesmos fatos reutiliza oito grupos sem chamar nenhum adapter. | `security-non-repetition.php` |
| Concorrência | Segunda instância e segundo processo recebem `busy` enquanto o adapter detém a trava; não iniciam outro motor. | `security-execution-ledger.php` |
| Interrupção real | Processo sai dentro do callback; reserva `running` sobrevive. Nova instância exige confirmação de parada e retry explícito. | `security-execution-ledger.php` |
| Barreira de recuperação | Mudar agrupamento, contexto ou nonce não contorna reserva incerta; confirmação de parada é auditada. | `security-execution-ledger.php` |
| Cobertura/evidência inválidas | Operação falha, cobertura parcial/desconhecida, sessão inválida, evidência expirada, ausente, corrompida ou raw não geram reuso. | `security-execution-ledger.php` |
| Grupo parcialmente coberto | Retorna `replan-required`; não repete automaticamente unidades cobertas. | `security-execution-ledger.php` |
| Retry | Expiração não dispara motor automaticamente; motivo explícito permite nova tentativa. Reagrupar unidades não burla o histórico. | `security-execution-ledger.php`, `security-non-repetition.php` |
| Fallback | Cobertura válida impede fallback; sem tentativa primária terminada ele não avança; sem equivalência aprovada continua bloqueado. | `security-execution-ledger.php`, `security-non-repetition.php` |
| Requisições internas | O callback simula duas requisições iguais por grupo web; ambas permanecem. Nenhum filtro HTTP é instalado pelo coordenador. | `security-non-repetition.php` |
| Proveniência histórica | Após expiração e retry, o evento antigo continua referindo sua tentativa, plano e evidência originais. | `security-non-repetition.php` |
| Relatório por plano | Tentativas de outro plano não são contadas como execução nova; `busy` não vira estado vazio; decisões legadas indicam proveniência ausente. | `security-non-repetition.php` |

Todos esses testes integram `make syntax profile-test`. A simulação de requisições internas comprova que o coordenador preserva a chamada ao adapter; não é instrumentação do transporte HTTP dos scanners. O teste de concorrência usa subprocessos PHP reais e filesystem local.

## Explicação das supressões

`SecurityCoordinationReport::fromPlan($plan, $journal)` produz JSON versionado e `markdown()` renderiza a seção de coordenação. A projeção fica separada do schema 3 de `SecurityReport` e dos findings. O relatório completo em Markdown/PDF e sua integração ao CLI continuam nas etapas de relatório da EAP.

São explicadas separadamente:

- **Proposta duplicada:** mesma unidade verificável selecionada uma vez, com `covered_by`. É decisão de agendamento; não presume execução anterior.
- **Cobertura reutilizada:** evento do ledger com unidade, horário, tentativa de origem, plano de origem e referência/hash de evidência congelados no momento da decisão. O adapter não foi chamado naquele evento.
- **Exclusão por escopo/profile:** motivo próprio, sem equivalência ou cobertura presumida.
- **Bloqueios e avisos:** seleção não controlada, sobreposição complementar/candidata, identidade incompleta, fontes ausentes e capacidades não solicitadas continuam visíveis.

As contagens são de **eventos**, não necessariamente unidades únicas, requisições poupadas ou vulnerabilidades. Um plano pode registrar vários eventos de reuso da mesma unidade. A geração do relatório não revalida sessão, expiração ou arquivos atuais: descreve o histórico, sem autorizar nova execução/reutilização.

## Correção de proveniência identificada nesta etapa

O ledger SEC-04.04 registrava as chaves das unidades reutilizadas. Consultar depois seu mapa atual de cobertura poderia apontar um evento antigo para a evidência de um retry posterior. Nesta etapa, `reused` passa a persistir `coverage_snapshot`; o registro de cobertura inclui também o plano de origem. O relatório usa exclusivamente o snapshot histórico e nunca completa sua proveniência consultando o registro mutável atual.

A alteração é aditiva no journal v1. Para decisões legadas sem snapshot, o relatório marca `legacy-decision-without-provenance`, sem fabricar referência. A evidência SEC-04.04 original permanece histórica; seu arquivo de fonte anterior foi preservado no diretório privado de validação e a revisão está documentada nos hashes desta entrega.

## Exemplo e resultados observados

Os exemplos [Markdown](evidence/sec04-05-coordination-example.md) e [JSON](evidence/sec04-05-coordination-example.json) mostram o segundo run sintético:

- Seis fontes, nove unidades únicas agrupadas em oito execuções; uma proposta adicional duplicada.
- Primeiro run: oito chamadas a adapters. Segundo run: zero chamadas adicionais e oito eventos de reuso cobrindo nove unidades.
- Dez eventos explicados no relatório do segundo plano: uma proposta duplicada e nove unidades reutilizadas.
- Papéis diferentes, contexto incompleto e retry autorizado são exercícios posteriores separados, sem atribuição indevida ao segundo plano.
- ZAP e Nuclei continuam selecionados; a sobreposição parcial de headers não é tratada como equivalência aprovada.

As referências dos exemplos são históricas e sintéticas. O diretório temporário dos testes é removido; não representam evidências acessíveis de um consumidor real.

Responsável técnico: Codex. Início: `2026-10-24T15:27:38-03:00`. Fim e hashes na [EAP](EAP-SECURITY-ORCHESTRATION.md) e no [registro de validação](evidence/sec04-05-validation-2026-10-24.json). Próxima etapa: SEC-05.01, descoberta e passivo ZAP gerenciados pelo NINFA.

Revalidação cruzada: [revisão SEC-04 com SEC-01/02/03](REVISAO-SEC-04-2026-10-24.md). O relatório verifica também a consistência do snapshot no horário histórico: operação/cobertura completas, sessão válida, prazos, grupo e unidades correspondentes. Não se trata de revalidação atual dos arquivos ou da sessão.
