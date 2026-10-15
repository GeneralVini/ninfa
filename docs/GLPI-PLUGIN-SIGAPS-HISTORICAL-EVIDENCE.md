# Reconciliação de evidências históricas — SIGAPS GLPI / NINFA

**Consulta realizada em:** 2026-10-15  
**Fontes primárias:** discussões já presentes no Projeto NINFA (somente o contexto disponível nesta conversa), histórico de commits, PRs, reviews e documentação em [GeneralVini/ninfa](https://github.com/GeneralVini/ninfa).  
**Decisão:** **nenhum achado histórico do SIGAPS é descartado**, e nenhum finding novo do ÉGIDE é usado como substituto.

## Alcance e limites da recuperação

Este documento registra apenas evidências **individualmente localizadas**. As conversas completas de outros chats do Projeto NINFA não foram disponibilizadas por um mecanismo de busca integral nesta execução; o contexto histórico disponível foi considerado, mas **não pode ser declarado exaustivamente auditado**. Uma busca de arquivos do Projeto retornou zero resultados, o que não significa ausência de conteúdo nos chats.

O GitHub `GeneralVini/ninfa` tem histórico de PR, review, commits, scripts, regras e fixtures. Esses artefatos podem conter decisões de engenharia mesmo que não existam GitHub Issues formalmente abertas. **Não confundir PR comments e testes sintéticos com findings ocorridos no código SIGAPS**.

A inspeção do catálogo de issues da conta `GeneralVini/ninfa` não revelou issue exclusiva identificada como SIGAPS; isso **não** prova que o projeto não tenha problemas pendentes, porque eles também podem estar documentados em chats, reviews, logs, commits e discussões.

## Marcos de validação comprovados

| Referência verificável | Fato histórico | Estado para a nova baseline |
| --- | --- | --- |
| [NINFA PR #1](https://github.com/GeneralVini/ninfa/pull/1) | A descrição registra `composer check` completo executado no consumidor **SigaPS**, abrangendo Rector, PHPStan, Psalm, testes, Composer Audit, Psalm taint e Semgrep | `HISTORICAL_SUCCESS`. Não implica que o checkout atual está limpo, nem prova que se trate de toda a cobertura atual |
| [NINFA PR #1 — encerramento](https://github.com/GeneralVini/ninfa/pull/1#issuecomment-5649970417) | Decisão explícita de encerrar PR #1 **sem merge**, mantendo inicialmente o desenvolvimento na branch `feature/glpi-plugin-profile` | `HISTORICAL_BRANCH_DECISION`; verificar integração posterior na linha principal, sem presumir merge desse PR |
| [Semgrep GLPI overlay](https://github.com/GeneralVini/ninfa/blob/main/security/semgrep/profiles/glpi-plugin-11.yml) | Contratos estáticos de SQL, redirect, SSRF e acesso a arquivos existentes no NINFA | `RULE_CATALOG`, não finding SIGAPS. Reavaliar ocorrência caso a caso na baseline do consumidor |
| [Fixtures Semgrep GLPI](https://github.com/GeneralVini/ninfa/blob/main/security/semgrep-tests/profiles/glpi-plugin-11.php) | Fixtures sintéticas criadas para validar regras | `SYNTHETIC_REGRESSION`; não substitui issues nem relatório de campo |

## Apontamentos concretos recuperados da revisão do PR #1

Estas referências descrevem **riscos na infraestrutura NINFA para consumidores GLPI**, encontrados em review; não atribuir diretamente um bug ao código SIGAPS. Mantêm o identificador do comentário, a prioridade original e estado **`VERIFY_CURRENT_CODE`** até confirmação.

| Caso de reconciliação | Origem (ID original preservado) | Contrato afetado | Estado |
| --- | --- | --- | --- |
| `GLPI-PR1-REVIEW-01` | [review 3998260114](https://github.com/GeneralVini/ninfa/pull/1#discussion_r3998260114) — P1 | Emissão de `parameters.glpi` sem extensão `phpstan-glpi` carregada inviabilizava parse de configuração PHPStan | `VERIFY_CURRENT_CODE`; revisar versão externa de configuração e teste sem extensão |
| `GLPI-PR1-REVIEW-02` | [review 3998260120](https://github.com/GeneralVini/ninfa/pull/1#discussion_r3998260120) — P1 | Config PHPStan gerada com caminhos absolutos poderia ficar desatualizada entre hosts | `VERIFY_CURRENT_CODE`; testar mudança de `NINFA_GLPI_ROOT`, workspace e regeneração |
| `GLPI-PR1-REVIEW-03` | [review 3998260123](https://github.com/GeneralVini/ninfa/pull/1#discussion_r3998260123) — P1 | `--force` no workflow poderia sobrescrever configurações ECS, Rector, PHPStan, Psalm e PHPUnit já versionadas no consumidor | `VERIFY_CURRENT_CODE`; avaliar contrato de escrita externa atual, testes e CI |

Esses IDs `GLPI-PR1-REVIEW-0x` são **identificadores internos desta reconciliação**, não números de GitHub Issues originais. Os links de comentário são a proveniência verdadeira.

## Evidências de regressão, ainda não atribuídas a achados do SIGAPS

- [Commit `b6c4700`](https://github.com/GeneralVini/ninfa/commit/b6c4700af826233983f6d621092a4331e86ccfdc): evolução das regras Semgrep GLPI, inclusive SQL e redirecionamento.
- [Commit `39f2f3c`](https://github.com/GeneralVini/ninfa/commit/39f2f3c537a6f7695d3348bb3d3f7c227eed4502): introdução de fixtures do overlay GLPI.
- [Commit `34c3093`](https://github.com/GeneralVini/ninfa/commit/34c3093b249bf5fd554f67e0b373ebd414206a06): exigência de versão do host GLPI 11 reconhecível.
- [NINFA PR #15](https://github.com/GeneralVini/ninfa/pull/15): construção atual de `glpi-plugin` semântico, revisão e duas trilhas de homologação; seus testes sintéticos ainda não provam resultado do SIGAPS real.

Essas referências compõem o inventário de **regras/decisões**; para se tornarem ocorrências SIGAPS exigem referência explícita a relatório, linha, execução ou conversa com evidência do consumidor.

## Fila de reconciliação que não pode ser apagada

| Fila | Estado | Regra |
| --- | --- | --- |
| `SIGAPS-LEGACY-CHAT` | `AWAITING_SOURCE_DISCOVERY` | Recuperar todos os apontamentos nas conversas do Projeto NINFA; nenhum caso deve sumir por falta de busca integral |
| `SIGAPS-LEGACY-REPORTS` | `AWAITING_SOURCE_DISCOVERY` | Buscar referências/artefatos de execução anteriores por branch, PR, scripts e notas; não interpretar ausência na branch atual como resolução |
| `GLPI-PR1-REVIEW-01..03` | `VERIFY_CURRENT_CODE` | Cada review mantém a própria URL e decisão após teste, mesmo se a arquitetura foi substituída |
| `SIGAPS-NEW-BASELINE` | `NOT_EXECUTED` | Rodar somente sobre cópia isolada do plugin GLPI correto, preservar resultado inicial |
| `EGIDE-BASELINE` | `NOT_EXECUTED` | Repositório privado identificado, mas host local NINFA e execução real não confirmados |

Não criar tickets com texto inventado. **Contagens de issues/finding SIGAPS permanecem desconhecidas até que todos os registros sejam localizados.** O êxito de ÉGIDE não altera qualquer estado do SIGAPS.

## Próximas ações verificáveis

1. Correlacionar PR #1, commits, reviews e documentos em `GeneralVini/ninfa`; não equiparar `GeneralVini/sigaps` (aparenta aplicação Yii2) à instalação `/var/www/psglpi/plugins/sigaps`.
2. Continuar a recuperação das decisões de outros chats do Projeto NINFA quando essas conversas estiverem efetivamente acessíveis pelo contexto/fonte; preservar o ID original ou permalink sempre que houver.
3. Validar cada uma das três advertências P1 do PR #1 contra o código atual e fixtures de migração/configuração, marcando separadamente `VERIFIED_FIXED`, `OPEN` ou `INCONCLUSIVE`.
4. Produzir ledger privado de ocorrências reais do SIGAPS e do ÉGIDE com identidade de fonte, fingerprint e histórico de triagem; não publicar informações sensíveis em repositórios públicos.
5. Não fechar DUAL-0/DUAL-7 antes de conciliar histórico SIGAPS, inclusive resultados `NOT_REPRODUCED` e `UNKNOWN`.

**Resultado desta tranche:** evidências documentais de GitHub reconstituídas; inventário completo dos chats e relatório de campo SIGAPS continuam por comprovar. Nenhum original foi acessado ou modificado.
