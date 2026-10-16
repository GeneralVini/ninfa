# Gate de fechamento — profile GLPI Plugin 11

> **EAP mestre:** [GLPI-PLUGIN-EAP.md](GLPI-PLUGIN-EAP.md) — coordenação das cinco ondas, IDs, prioridades, evidências e gates. Este arquivo mantém seu escopo especializado e não deve ser usado como segunda fonte concorrente de status.

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
| Field test | fixtures sintéticas com nomes `sigaps` e `egide`; harness copy-only | homologar SIGAPS e ÉGIDE reais em cópias descartáveis, com host GLPI 11 validado e origem do ÉGIDE informada |
| Compatibilidade | foco declarado GLPI 11 | matriz validada contra host real e APIs com prova |

A homologação deve respeitar a [EAP de dois consumidores e preservação das issues SIGAPS](GLPI-PLUGIN-DUAL-CORPUS-PLAN.md). A ausência de um finding em scan posterior, ou no ÉGIDE, **não resolve uma issue SIGAPS**. O gate DUAL-0 exige inventariar fontes/IDs e histórico antes de declarar o perfil fechado; a origem do plugin local SIGAPS deve ser distinguida do repositório homônimo que aparenta ser Yii2.

## Pacotes e dependências

| Pacote | Entrega | Aceite |
| --- | --- | --- |
| GLPI-1 | Base de observabilidade/ciclo de vida | snapshot estático auditável; callbacks obrigatórios ausentes são tri-state (true/false/unknown); nenhum `fix` |
| GLPI-2 | Hook registry | validação de chave de plugin e callback só quando nomes/alvos são literais e resolvidos; hooks calculados unknown |
| GLPI-3 | Direitos e acesso | revisão de `Session`, perfis, CSRF e autorização por fluxo; security apenas com prova, outros como REVIEW |
| GLPI-4 | DB, migrations e deprecations | inventário de APIs GLPI 11, casos perigosos comprovados, correções SAFE só idempotentes |
| GLPI-5 | Integração | `assist` e `check`/JSON integrados à engine GLPI (`COR-001..003`); manter regressões, field tests e revisão de exit codes no gate final |
| GLPI-6 | Field test e homologação | host GLPI 11 real; corpus SIGAPS + ÉGIDE (ambos read-only), falsos positivos triados e relatórios comparativos |
| GLPI-7 | Gate de fechamento | critérios abaixo aprovados e PR revisado, sem merge automático |

## Contratos inegociáveis

1. `check`, `assist` e `security` não fazem alterações intencionais no consumidor; ferramentas capazes de escrever caches/metadata no consumidor devem ser redirecionadas ao workspace externo ou executadas em **cópia**.
2. `fix` nunca é executado no SIGAPS **nem no ÉGIDE** original. SAFE exige prova de equivalência, patch exato e idempotência; alterações de semântica, política de domínio e PHPDoc ficam em REVIEW/SEMANTIC.
3. Ausência só vira `Finding` quando fonte/inventário são **conclusivos**. Includes, macros, registradores dinâmicos, herança externa, runtime do GLPI e casos não resolvidos são `unknown`.
4. O host GLPI fornece contexto de tipo/compatibilidade; seu core não é alvo mutável. Regras GLPI não são implementadas como cópias com prefixo alterado dos analyzers Yii2.
5. Findings de security-smell não são SQLi/XSS/CSRF comprovados; opiniões arquiteturais não viram security.
6. Testes: verdadeiro positivo, verdadeiro negativo, caso limite, unknown e, para SAFE, idempotência. Código, PHPDoc, documentação, matriz e fixtures caminham juntos.

## SIGAPS e ÉGIDE — somente leitura absoluta

Referências: SIGAPS em `/var/www/psglpi/plugins/sigaps`; ÉGIDE no [repositório privado `GeneralVini/egide`](https://github.com/GeneralVini/egide) (`main`), inspecionado estaticamente por leitura no GitHub. O caminho da instalação local do ÉGIDE **ainda não foi confirmado**. Não supor host/diretório local comum ao SIGAPS; preservar o basename técnico confirmado no checkout.

**O caminho original é intocável**, inclusive para `ninfa check`/ `assist`/`security` quando não for possível provar todos os efeitos colaterais de terceiros. Não executar `ninfa fix` contra ele. Não rodar Composer install/update, migrations, GLPI CLI install/uninstall ou testes com DB mutável diretamente nele.

Criar cópia descartável **fora da árvore original** (exemplo `/tmp/ninfa-field-tests/sigaps-YYYYMMDD-HHMMSS`), com workspace e caches também fora do original. Registrar snapshot e diff do original antes e depois, sem instalar hooks ou alterar permissão. Não importar fonte do SIGAPS nem do ÉGIDE como fixture versionada do NINFA.

Neste ambiente conectado ao GitHub, o código do ÉGIDE foi consultado remotamente em modo read-only, mas nenhuma árvore real foi montada no host de execução; portanto field tests de SIGAPS e ÉGIDE **não podem ser dados como concluídos remotamente**. O [corpus de homologação](GLPI-PLUGIN-FIELD-TEST-CORPUS.md) define matriz comparativa, triagem e requisitos do ÉGIDE.

## Catálogo inicial de decisões (baseline GLPI 11)

| Contrato / proposta | Estado | Justificativa |
| --- | --- | --- |
| Entrypoints `setup.php`/`hook.php` | `IMPLEMENTED` (tranche GLPI-1) | `COR-001`, ausência física comprovada |
| Callback global init/version/install/uninstall | `PARTIAL` (tranche GLPI-1) | `COR-002`, apenas declarações estáticas e identificador técnico convencional |
| Hook registry `$PLUGIN_HOOKS` | `PARTIAL` | GLPI-2: inventário literal + `COR-003` para valor escalar em hook por plugin sem índice de plugin; constantes literais resolvidas do host GLPI 11; callbacks de função simples têm inventário `declared=true/null` (sem prova negativa); arrays/métodos/classes e valores dinâmicos continuam unknown |
| `Plugin::registerClass()` | `PARTIAL` | índice estático de classes/métodos, strings e aliases `::class` resolvidos (`declared=true/null`); autoload/herança, imports complexos e chamadas dinâmicas continuam pendentes |
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

Para `check`, `security` ou `fix`, substitua somente o último argumento. O script copia o consumidor para `/tmp/ninfa-glpi-field-test.*/<nome-tecnico-do-plugin>`, preservando o basename do diretório necessário à resolução dos callbacks, bloqueia symlinks, aponta o CLI exclusivamente para a cópia, mantém logs/workspace externos, compara hashes e metadata do original ao terminar e guarda evidências. Nenhuma execução automática desse harness foi realizada sobre SIGAPS ou ÉGIDE neste ambiente. Para ÉGIDE, primeiro obter a raiz técnica real e passá-la como primeiro argumento, sem inventar um caminho. **Uma comparação final não substitui isolamento do processo**: execute-o apenas sob credenciais/ambiente que não tenham permissão para escrever no SIGAPS original, quando possível, e avalie efeitos de autoload no host GLPI.

## Evidência histórica do SIGAPS

O [registro inicial de evidências](GLPI-PLUGIN-SIGAPS-HISTORICAL-EVIDENCE.md) e o [ledger versionado](GLPI-PLUGIN-HISTORICAL-LEDGER.json) recuperam a validação do consumidor SigaPS documentada no PR #1 e três apontamentos P1 de infraestrutura NINFA, sem confundi-los com issues no código consumidor. Os três IDs de origem são protegidos por `tests/glpi-historical-ledger.php`; as regressões de configuração estão em `tests/glpi-historical-pr1.php`. P1-03 ainda exige verificar a aplicação de configurações personalizadas. O histórico integral de chats deste Projeto NINFA ainda necessita reconciliação verificável. Nenhuma ausência nos novos scans, testes do ÉGIDE ou issue tracker vazio permite marcar pendências SIGAPS como resolvidas.

## Revisão de lacunas

A auditoria de defects e pendências de fechamento está registrada em [GLPI-PLUGIN-REVIEW-2026-10-15.md](GLPI-PLUGIN-REVIEW-2026-10-15.md), incluindo os riscos do harness, integração com `check`, hooks por constantes, callbacks/classes, direitos/CSRF, migrações e homologação. Itens P0/P1 precisam de resolução ou classificação expressa para o gate.

## Definition of Done

- [ ] Catálogo GLPI 11 com cada candidato classificado `IMPLEMENTED`, `PARTIAL`, `REVIEW`, `SEMANTIC`, `DEFERRED` ou `REJECTED` e justificativa.
- [ ] Modelo semântico e rule engine GLPI integrados ao `assist` e auditoria JSON externa.
- [ ] Lifecycle e hooks validados com true-positive, true-negative e unknown.
- [ ] Contratos de acesso, autenticação, permissões e CSRF calibrados em código representativo, sem claims de vulnerabilidade não demonstrados.
- [ ] Overlays Semgrep revisados por fixtures e evidência de execução.
- [ ] Nenhum caminho de geração, cache ou recheck altera inadvertidamente um projeto read-only.
- [ ] `make syntax`, `make profile-test`, `make semgrep-rules` e gates pertinentes verdes no head final.
- [ ] Field tests nos hosts GLPI 11, no SIGAPS **e no ÉGIDE**, somente por cópias, com baseline comparativa de falsos positivos e prova de integridade de ambos os originais.
- [ ] Documentação e resultados de homologação anexados ao PR; aprovação humana explícita para merge.

**Status do fechamento:** ABERTO até satisfazer todos os itens acima. Passar a suíte sintética não é prova suficiente para declarar o profile concluído.
