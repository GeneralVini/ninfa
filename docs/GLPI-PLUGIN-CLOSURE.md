# Gate de fechamento — profile GLPI Plugin 11

## Decisão e escopo

Esta é a EAP operacional de encerramento do profile `glpi-plugin`, separada da EAP Yii2/upstreams. O Yii2 não será expandido neste ciclo. O objetivo não é alegar cobertura de 100% do GLPI 11; é fechar uma baseline **documentada, verificável e conservadora**, sem modificar o consumidor durante análise.

Fonte de comportamento: documentação oficial de desenvolvimento de plugins GLPI, especialmente `setup.php`, `hook.php`, `plugin_init_<nome>()`, `plugin_version_<nome>()`, `plugin_<nome>_install()` e `plugin_<nome>_uninstall()`.

## Estado da base em 2026-10-15

| Camada | Estado observado em main | Falta para fechar |
| --- | --- | --- |
| Detecção de profile | existente | fixtures para layouts e nomes reais, evitar falso positivo |
| Host GLPI 11 | resolução por variável ou host pai | testar variantes de versão e indisponibilidade |
| Configuração externa | PHPStan/Psalm/ECS/Rector + bootstrap; raiz do host é contexto | prova de ausência de efeitos no consumidor, revisão de caches/logs |
| Segurança | baseline PHP, 12 contratos SAST e regras Semgrep GLPI | revisar precisão/cobertura, validar positivos/negativos com fixtures reais |
| Semântica GLPI | sem modelo/regra nativa na baseline auditada | modelo de lifecycle, hooks, classes, ACL/Session e migrations |
| Assist | saída PHPStan/Psalm; Yii2 semântico | findings semânticos GLPI no contrato `Finding`, artefatos externos |
| Field test | fixture sintética de plugin/host | rodar corpus real, inclusive SIGAPS, em cópia descartável |
| Compatibilidade | foco declarado GLPI 11 | matriz validada contra host real e APIs com prova |

## Pacotes e dependências

| Pacote | Entrega | Aceite |
| --- | --- | --- |
| GLPI-1 | Base de observabilidade/ciclo de vida | snapshot estático auditável; callbacks obrigatórios ausentes são tri-state (true/false/unknown); nenhum `fix` |
| GLPI-2 | Hook registry | validação de chave de plugin e callback só quando nomes/alvos são literais e resolvidos; hooks calculados unknown |
| GLPI-3 | Direitos e acesso | revisão de `Session`, perfis, CSRF e autorização por fluxo; security apenas com prova, outros como REVIEW |
| GLPI-4 | DB, migrations e deprecations | inventário de APIs GLPI 11, casos perigosos comprovados, correções SAFE só idempotentes |
| GLPI-5 | Integração | `assist`/JSON, CI, documentação e relatórios externos; regressão PHP genérico/Yii2 |
| GLPI-6 | Field test e homologação | host GLPI 11 real, corpus de plugins independentes, falsos positivos triados e relatórios |
| GLPI-7 | Gate de fechamento | critérios abaixo aprovados e PR revisado, sem merge automático |

## Contratos inegociáveis

1. `check`, `assist` e `security` não fazem alterações intencionais no consumidor; ferramentas capazes de escrever caches/metadata no consumidor devem ser redirecionadas ao workspace externo ou executadas em **cópia**.
2. `fix` nunca é executado no SIGAPS original. SAFE exige prova de equivalência, patch exato e idempotência; alterações de semântica, política de domínio e PHPDoc ficam em REVIEW/SEMANTIC.
3. Ausência só vira `Finding` quando fonte/inventário são **conclusivos**. Includes, macros, registradores dinâmicos, herança externa, runtime do GLPI e casos não resolvidos são `unknown`.
4. O host GLPI fornece contexto de tipo/compatibilidade; seu core não é alvo mutável. Regras GLPI não são implementadas como cópias com prefixo alterado dos analyzers Yii2.
5. Findings de security-smell não são SQLi/XSS/CSRF comprovados; opiniões arquiteturais não viram security.
6. Testes: verdadeiro positivo, verdadeiro negativo, caso limite, unknown e, para SAFE, idempotência. Código, PHPDoc, documentação, matriz e fixtures caminham juntos.

## SIGAPS — somente leitura absoluta

Referência de field test: `/var/www/psglpi/plugins/sigaps`.

**O caminho original é intocável**, inclusive para `ninfa check`/ `assist`/`security` quando não for possível provar todos os efeitos colaterais de terceiros. Não executar `ninfa fix` contra ele. Não rodar Composer install/update, migrations, GLPI CLI install/uninstall ou testes com DB mutável diretamente nele.

Criar cópia descartável **fora da árvore original** (exemplo `/tmp/ninfa-field-tests/sigaps-YYYYMMDD-HHMMSS`), com workspace e caches também fora do original. Registrar snapshot e diff do original antes e depois, sem instalar hooks ou alterar permissão. Não importar fonte do SIGAPS como fixture versionada do NINFA.

Neste ambiente conectado ao GitHub, esse caminho local não está montado; portanto um field test real **não pode ser dado como concluído remotamente**.

## Catálogo inicial de decisões (baseline GLPI 11)

| Contrato / proposta | Estado | Justificativa |
| --- | --- | --- |
| Entrypoints `setup.php`/`hook.php` | `IMPLEMENTED` (tranche GLPI-1) | `COR-001`, ausência física comprovada |
| Callback global init/version/install/uninstall | `PARTIAL` (tranche GLPI-1) | `COR-002`, apenas declarações estáticas e identificador técnico convencional |
| Hook registry `$PLUGIN_HOOKS` | `DEFERRED` | tipos de hook/callback e chaves dinâmicas exigem índice GLPI 11 |
| `Plugin::registerClass()` | `DEFERRED` | análise precisa validar tipos registrados e autoload resolvido |
| Lifecycle de install/uninstall e migrations | `DEFERRED` | efeitos em banco e estado de host não são inferidos por texto |
| Direitos, perfis e `Session` | `REVIEW` | segurança depende de controle de fluxo e endpoint público comprovado |
| CSRF e autorização de ação | `REVIEW` | contexto do roteamento e guards deve ser demonstrado |
| SQL dinâmico / criteria API | `PARTIAL` | overlay Semgrep existente; taint/smell devem continuar separados |
| Upload, filesystem, SSRF, redirect | `PARTIAL` | contratos Semgrep/taint existentes, sujeitos a calibração |
| Deprecations e modernização GLPI 11 | `DEFERRED` | API/versionamento e equivalência SAFE ainda sem fixtures |
| Performance/arquitetura | `REVIEW` | recomendações não são correctness/security por padrão |

O catálogo não implica que todos os itens sejam implementação obrigatória para v1: os estados `DEFERRED` e `REVIEW` precisam de decisão justificada na revisão do gate. `PARTIAL` exige fronteira de cobertura expressa, e nenhum SAFE novo é autorizado por presunção.

## Executando field test local sobre cópia

Na máquina que possui o SIGAPS, a partir da raiz do NINFA, rode:

```bash
bash scripts/glpi-field-test.sh /var/www/psglpi/plugins/sigaps assist
```

Para `check`, `security` ou `fix`, substitua somente o último argumento. O script copia o consumidor para `/tmp/ninfa-glpi-field-test.*`, bloqueia symlinks, aponta o CLI exclusivamente para a cópia, mantém logs/workspace externos, compara hashes e metadata do original ao terminar e guarda evidências. Nenhuma execução automática desse harness foi realizada sobre SIGAPS neste ambiente. **Uma comparação final não substitui isolamento do processo**: execute-o apenas sob credenciais/ambiente que não tenham permissão para escrever no SIGAPS original, quando possível, e avalie efeitos de autoload no host GLPI.

## Definition of Done

- [ ] Catálogo GLPI 11 com cada candidato classificado `IMPLEMENTED`, `PARTIAL`, `REVIEW`, `SEMANTIC`, `DEFERRED` ou `REJECTED` e justificativa.
- [ ] Modelo semântico e rule engine GLPI integrados ao `assist` e auditoria JSON externa.
- [ ] Lifecycle e hooks validados com true-positive, true-negative e unknown.
- [ ] Contratos de acesso, autenticação, permissões e CSRF calibrados em código representativo, sem claims de vulnerabilidade não demonstrados.
- [ ] Overlays Semgrep revisados por fixtures e evidência de execução.
- [ ] Nenhum caminho de geração, cache ou recheck altera inadvertidamente um projeto read-only.
- [ ] `make syntax`, `make profile-test`, `make semgrep-rules` e gates pertinentes verdes no head final.
- [ ] Field tests no host GLPI 11 e no SIGAPS **somente por cópia**, com baseline de falsos positivos e prova de integridade do original.
- [ ] Documentação e resultados de homologação anexados ao PR; aprovação humana explícita para merge.

**Status do fechamento:** ABERTO até satisfazer todos os itens acima. Passar a suíte sintética não é prova suficiente para declarar o profile concluído.
