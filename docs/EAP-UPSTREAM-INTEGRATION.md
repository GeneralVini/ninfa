# EAP — Integração de referências upstream no NINFA

## 1. Objetivo

Esta EAP organiza a assimilação controlada de ideias, práticas e capacidades dos projetos:

- `mspirkov/yii2-phpstan-rules`;
- `mspirkov/yii2-rector`;
- `php-forge/foxy`.

A ordem é deliberada:

1. encerrar uma baseline Yii2 v1 tecnicamente coerente;
2. validar a integração em projetos reais;
3. somente então incorporar as práticas transversais e a capacidade de Frontend SCA inspiradas no Foxy.

A EAP é a fonte de verdade da **entrega**. A decisão técnica por regra Yii2 continua registrada em `docs/YII2-UPSTREAM-RULE-MATRIX.md`.

## 2. Baselines congeladas

Esta EAP usa os seguintes pontos de referência:

| Origem | Baseline |
| --- | --- |
| NINFA | `main@db2c79aae8bd7ab3c45d9e0c75fcf74f48ecd1fb` |
| `mspirkov/yii2-phpstan-rules` | `d50f6f44045d04932c01004e2dbf59322140e929` |
| `mspirkov/yii2-rector` | `b80c7cab2e983f023ae3885c4ba5b4be885469e1` |
| `php-forge/foxy` | `0.3.0@9ac0d0c979e93d6310636188fbb5638c8c24b535` |

Mudanças upstream posteriores não ampliam silenciosamente este ciclo. Elas devem ser avaliadas em revisão futura da EAP.

## 3. Princípios de execução

- O NINFA mantém contratos, IDs, taxonomia, políticas e implementações próprias.
- Referência dinâmica ou tipo não demonstrável permanece `unknown`; não vira falso positivo para aumentar cobertura.
- `check`, `security` e `assist` não modificam o consumidor.
- `fix` só aplica transformações classificadas como SAFE ou fixers externos já homologados.
- PHPDoc do consumidor é contrato estático, não comentário cosmético.
- Opinião arquitetural não é promovida automaticamente a correctness ou security.
- Uma ideia upstream pode ser encerrada como `IMPLEMENTED`, `PARTIAL`, `SAFE-CANDIDATE`, `REVIEW`, `SEMANTIC`, `DEFERRED` ou `REJECTED`.
- Encerrar uma integração significa tomar uma decisão auditável sobre 100% do baseline, não reproduzir 100% das funcionalidades upstream.
- Mudanças de framework ficam no profile; hardening de execução, parsing e reporting fica no core.
- Frontend SCA será capability detectada por lockfile, não feature fixa de Yii2/Yii3/GLPI.

## 4. Estrutura analítica do projeto

```text
1.0 Governança e baseline
    1.1 Congelar referências upstream
    1.2 Consolidar taxonomia de decisão
    1.3 Registrar proveniência/licenças
    1.4 Definir Definition of Done

2.0 yii2-phpstan-rules — assimilação semântica
    2.1 Views
    2.2 Controllers, actions e behaviors
    2.3 Models
    2.4 ActiveRecord e Query
    2.5 Config arrays / BaseObject
    2.6 Forms e uploads
    2.7 Architecture / maintainability
    2.8 Atualização da matriz upstream

3.0 yii2-rector — modernização e remediação
    3.1 SAFE já implementado
    3.2 REVIEW
    3.3 PHPDoc / magic properties
    3.4 Typing-dependent candidates
    3.5 Atualização da matriz e contrato SAFE

4.0 Gate Yii2 upstream integration v1
    4.1 Testes positivos/negativos/unknown
    4.2 Idempotência SAFE
    4.3 Field tests
    4.4 Documentação
    4.5 Gate de encerramento

5.0 Foxy — hardening transversal
    5.1 Structured output
    5.2 Limites de stdout/stderr
    5.3 Exit code × conteúdo
    5.4 Sanitização de output externo
    5.5 schema_version
    5.6 Tool provenance/version
    5.7 Fix transaction / rollback

6.0 Foxy — Frontend SCA
    6.1 Detecção de manager por lockfile
    6.2 npm
    6.3 pnpm
    6.4 Yarn
    6.5 Bun
    6.6 Deno
    6.7 Normalização em Finding
    6.8 Integração com SecurityInventory/SecurityReport

7.0 Engenharia e release
    7.1 Corpus de fixtures externas
    7.2 Mutation testing
    7.3 CI por responsabilidade
    7.4 CHANGELOG
    7.5 Repository quality
    7.6 Ownership marker do workspace

8.0 Gate pós-upstreams
    8.1 Regressão completa
    8.2 Documentação final
    8.3 Baseline de arquitetura
    8.4 Encerramento da EAP
```

## 5. Pacote 1.0 — Governança e baseline

### 1.1 Congelamento

Registrar os commits desta EAP na documentação e nos PRs relacionados.

**Aceite**

- baseline NINFA registrada;
- baseline dos três upstreams registrada;
- nenhuma alteração posterior entra sem decisão explícita.

### 1.2 Taxonomia de decisão

Estados permitidos:

| Estado | Significado |
| --- | --- |
| `IMPLEMENTED` | conceito possui implementação NINFA equivalente |
| `PARTIAL` | parte segura/objetiva foi incorporada |
| `SAFE-CANDIDATE` | pode chegar a autofix, mas a prova ainda não está suficiente |
| `REVIEW` | útil como finding/orientação; não é autofix padrão |
| `SEMANTIC` | depende de typing/PHPDoc/contrato arquitetural forte |
| `DEFERRED` | útil, mas fora da tranche corrente |
| `REJECTED` | incompatível com a filosofia/escopo do NINFA |

**Aceite**

- nenhum item upstream sem classificação;
- `DEFERRED` e `REJECTED` não são usados como sinônimos.

### 1.3 Proveniência

- manter `THIRD_PARTY_NOTICES.md` atualizado;
- preservar licenças quando houver adaptação substancial de código;
- manter os READMEs com agradecimento aos projetos de referência.

## 6. Pacote 2.0 — `yii2-phpstan-rules`

### 2.1 Views

Objetivo: consolidar a família de existência/resolução de views sem executar código do consumidor.

Cobertura a avaliar:

- `Controller::render()`;
- `renderPartial()`;
- `renderAjax()`;
- `renderFile()`;
- `View::render()`;
- `View::renderFile()`;
- nested view rendering;
- aliases estáticos;
- `//...` quando a raiz pode ser resolvida;
- extensões de view configuráveis;
- view paths configuráveis quando houver contrato explícito.

Regra de segurança:

```text
resolvido + inexistente → finding
dinâmico/inconclusivo   → unknown
```

**Aceite**

- gaps objetivos de `COR-001` classificados/implementados;
- true positive;
- true negative;
- alias/path edge cases;
- casos dinâmicos permanecem `unknown`.

### 2.2 Controllers, actions e behaviors

Consolidar:

- `actions()`;
- `ActionFilter.only`;
- `ActionFilter.except`;
- `AuthMethod.optional`;
- `AccessControl.rules[].actions`;
- `VerbFilter.actions`.

**Aceite**

- `COR-002` cobre apenas referências cuja existência pode ser provada;
- herança/composição dinâmica permanece tri-state;
- ausência não é inferida quando o inventário é inconclusivo.

### 2.3 Models — prioridade imediata

Aproveitar o inventário de atributos já introduzido por `Yii2ModelRulesAnalyzer`.

Entregas:

- 2.3.1 `rules()` — consolidar `COR-006`;
- 2.3.2 `scenarios()`;
- 2.3.3 `attributeLabels()`;
- 2.3.4 `attributeHints()`.

Preferência de design: IDs separados quando as regras representarem contratos diferentes, evitando transformar `COR-006` em uma regra excessivamente ampla.

**Aceite por regra**

- inventário conclusivo obrigatório;
- true positive;
- true negative;
- case boundary;
- case `unknown`;
- finding com categoria/severidade/confiança;
- PHPDoc narrativo do analyzer;
- documentação atualizada;
- sem autofix quando a correção depender de intenção de domínio.

### 2.4 ActiveRecord / Query

Revisar e consolidar:

- relation existence/path;
- `hasOne()/hasMany()` link attributes;
- query operator arity;
- ActiveRecord condition validation;
- update values/counters quando o schema puder ser demonstrado;
- existence checks;
- `findOne()/findAll()`;
- dynamic equality smell.

Mapeamentos já existentes:

```text
COR-003
COR-004
COR-005
PERF-001
MOD-001
SEC-001
```

**Aceite**

- schema runtime não é usado como prova negativa;
- Redis/MongoDB permanecem capabilities Yii2;
- `SEC-001` permanece security-smell enquanto `taint_proven=false`.

### 2.5 Config arrays / BaseObject

Avaliar em conjunto:

- `baseObjectInstantiationValidation`;
- `widgetPropertiesValidation`;
- `yiiCreateObjectValidation`;
- `componentBehaviorsValidation`;
- `controllerActionsValidation`;
- `behaviorAttributesValidation`.

Direção arquitetural:

```text
Yii2ConfigModel
├── class
├── properties
├── setters
├── configurable attributes
├── expected types
└── confidence
```

Não criar seis mecanismos independentes quando o problema-base é o mesmo.

**Aceite v1**

- desenho técnico documentado;
- itens podem permanecer `DEFERRED` se o typing necessário ainda não for confiável;
- não bloquear o Gate Yii2 v1 apenas por não implementar essa família completa.

### 2.6 Forms e uploads

Avaliar:

- ActiveForm field validation;
- Html active attribute validation;
- UploadedFile instance validation.

Dependência:

```text
resolver expressão do model
→ resolver classe
→ obter inventário conclusivo
→ validar atributo
```

Pode permanecer `DEFERRED` no v1 se essa cadeia não puder ser provada com baixa taxa de falso positivo.

### 2.7 Architecture / maintainability

Avaliar como advisory/configuração, não como verdade universal:

- DB query em controller/action/view;
- action chamando action via `$this`;
- superglobals diretas;
- propriedades globais da aplicação;
- mutação de `Yii::$app`;
- complexidade de actions/controllers.

**Aceite**

- nenhuma regra opinativa promovida silenciosamente a correctness/security;
- default conservador;
- política futura configurável quando aplicável.

## 7. Pacote 3.0 — `yii2-rector`

### 3.1 SAFE fechado

Considerar fechados, salvo bug/regressão:

- `DEP-001` — `Yii::trace()`;
- `DEP-002` — exit code constants;
- `DEP-003` — return literal em console action;
- `DEP-004` — cache aliases;
- `DEP-005` — `getHasChanged()`;
- `DEP-006` — `className()`;
- `PERF-001` — existência;
- `MOD-001` — `findOne/findAll`.

**Aceite**

- mesma evidência alimenta `assist` e `fix`;
- patches exatos;
- overlap controlado;
- idempotência comprovada.

### 3.2 REVIEW

Manter como REVIEW enquanto não houver equivalência mecânica suficiente:

- `ReplaceAppRequestResponseWithThisRector` → `ARCH-001`;
- `ReplaceWhereEqualityConditionWithArrayRector` → `SEC-001`;
- `MergeModelRulesRector`.

### 3.3 PHPDoc / magic properties

Avaliar:

- `AddPropertyTagsRector`;
- `RemoveRedundantPropertyTagsRector`;
- `ReplaceGetterWithPropertyRector`;
- `ReplaceSetterWithPropertyRector`.

Política:

- PHPDoc é contrato de tipos;
- `TYPE-001` pode produzir orientação;
- remoção/reescrita automática permanece `SEMANTIC` enquanto não houver prova equivalente para PHPStan/Psalm/IDE.

### 3.4 Typing-dependent candidates

`RemoveRedundantHtmlEncodeRector` permanece `SAFE-CANDIDATE` enquanto o NINFA não tiver prova forte equivalente a `numeric-string`.

## 8. Pacote 4.0 — Gate Yii2 upstream integration v1

Este gate encerra a primeira fase e libera o início funcional da frente Foxy.

### 4.1 Critérios obrigatórios

- 100% dos itens dos dois upstreams classificados;
- nenhum item sem decisão;
- todo `IMPLEMENTED` possui código, finding, PHPDoc, teste e documentação;
- todo `PARTIAL` documenta explicitamente a fronteira de cobertura;
- todo SAFE possui idempotência;
- REVIEW/SEMANTIC não entram em `fix`;
- `DEFERRED` possui justificativa técnica;
- field tests executados em projetos Yii2 reais;
- `make profile-test` verde;
- `docs/YII2-UPSTREAM-RULE-MATRIX.md` sincronizada;
- `docs/YII2-ANALYSIS.md` sincronizada;
- `docs/YII2-SAFE-REMEDIATION.md` sincronizada.

### 4.2 Saída do gate

Registrar explicitamente:

```text
Yii2 upstream assimilation baseline v1
yii2-phpstan-rules: d50f6f4
yii2-rector:        b80c7ca
```

Novas regras upstream passam a pertencer a uma revisão futura, não reabrem o v1.

## 9. Pacote 5.0 — Foxy: hardening transversal

Inicia somente após o Gate 4.0.

### 5.1 Structured output

Criar contrato comum para parsing defensivo:

```text
StructuredOutput
├── assertSize()
├── decodeObject()
├── decodeList()
├── requireString()
├── optionalString()
├── requireList()
├── sanitizeExternalText()
└── malformed()
```

Aplicar gradualmente em:

- Composer Audit;
- Semgrep;
- Psalm;
- OSV;
- EPSS;
- CISA KEV;
- futuros frontend audit adapters.

### 5.2 Limites de output

- definir limite máximo por processo capturado;
- distinguir truncamento, malformed e erro de ferramenta;
- nunca tratar truncamento como scan limpo.

### 5.3 Exit code × conteúdo

Contrato público desejado:

```text
0 = execução confiável sem finding bloqueante
1 = execução confiável com finding bloqueante
2 = execução inconclusiva / infraestrutura / parser / cobertura
```

Estados internos de `ToolResult` continuam mais ricos.

### 5.4 Sanitização

Toda mensagem derivada de processo/API externa deve ser sanitizada antes de terminal/log.

### 5.5 `schema_version`

Adicionar aos artefatos públicos versionáveis, começando por:

- `security-inventory.json`;
- `security-report.json`;
- outputs estruturados de `assist` quando estabilizados.

### 5.6 Tool provenance

Registrar por ferramenta:

```text
id
executable
source
version
supported_range
compatibility
```

Fontes possíveis:

- consumer;
- ninfa-managed;
- ninfa-local;
- PATH.

### 5.7 Fix transaction / rollback

Criar transação de mutação:

```text
snapshot
  ↓
native SAFE remediation
  ↓
ECS / Rector / outros fixers homologados
  ↓
check final
  ├── success → commit
  └── failure → rollback
```

Regras:

- somente arquivos do consumidor entram na transação;
- GLPI host nunca entra;
- registrar hash/estado antes e depois;
- rollback falho deve preservar o erro original como contexto.

## 10. Pacote 6.0 — Foxy: Frontend SCA

### 6.1 Detecção

Criar `FrontendDependencyDetector` separado da semântica de framework.

Lockfiles:

| Lockfile | Manager |
| --- | --- |
| `package-lock.json` / `npm-shrinkwrap.json` | npm |
| `pnpm-lock.yaml` | pnpm |
| `yarn.lock` | Yarn |
| `bun.lock` | Bun |
| `deno.lock` | Deno |

Mais de um lockfile reconhecido:

```text
state = ambiguous
```

Não escolher silenciosamente.

### 6.2 Adapters

```text
FrontendAuditAdapter
├── NpmAuditAdapter
├── PnpmAuditAdapter
├── YarnAuditAdapter
├── BunAuditAdapter
└── DenoAuditAdapter
```

Todos convergem para o `Finding` canônico do NINFA.

### 6.3 Aplicação por profile

Frontend SCA é capability transversal:

```text
php-generic ─┐
yii2 --------┤
yii3 --------┼── frontend-sca quando aplicável
glpi-plugin -┘
```

Para GLPI Plugin:

- plugin consumidor é target;
- host GLPI permanece `context-only`;
- host só poderá ser auditado futuramente por opção explícita e com escopo separado.

### 6.4 SecurityInventory / SecurityReport

Evoluir o inventário para representar frontend sem misturar ecossistemas:

```json
{
  "schema_version": 1,
  "profile": "yii2",
  "composer": {},
  "frontend": {
    "present": true,
    "manager": "pnpm",
    "lockfile": "pnpm-lock.yaml"
  }
}
```

## 11. Pacote 7.0 — Engenharia e release

### 7.1 Corpus de fixtures

Separar por ferramenta e por profile:

```text
tests/fixtures/
├── tools/
│   ├── composer-audit/
│   ├── semgrep/
│   ├── psalm/
│   ├── npm/
│   ├── pnpm/
│   ├── yarn/
│   ├── bun/
│   └── deno/
└── profiles/
    ├── php-generic/
    ├── yii2/
    ├── yii3/
    └── glpi-plugin/
```

Cada parser deve ter, quando aplicável:

- clean;
- valid finding;
- duplicate;
- partial;
- malformed;
- schema drift;
- oversized.

### 7.2 Mutation testing

Introduzir somente depois que os contratos centrais estiverem estáveis.

Prioridade:

- parsers;
- deduplicação SCA;
- security policy;
- Yii2 SAFE analyzers/remediator.

### 7.3 CI

Dividir workflows somente quando houver ganho real de isolamento/tempo de diagnóstico.

Direção futura:

```text
php-quality.yml
security-rules.yml
go.yml
repository-quality.yml
```

### 7.4 Release engineering

- criar/manter `CHANGELOG.md`;
- criar `UPGRADE.md` quando existirem migrações reais de usuário;
- manter documentação sincronizada com comportamento observado.

### 7.5 Ownership do workspace

Preparar marcador de ownership antes de qualquer operação destrutiva futura:

```text
/tmp/ninfa/<hash>/.ninfa-managed
```

Nenhuma limpeza recursiva deve atingir diretório cuja ownership não possa ser comprovada.

## 12. Pacote 8.0 — Gate pós-upstreams

Critérios finais:

- Gate Yii2 v1 preservado;
- hardening transversal aplicado sem regressão dos profiles;
- Frontend SCA produz `Finding` normalizado;
- `SecurityInventory` e `SecurityReport` versionados;
- tool provenance disponível;
- falha de scanner continua distinta de ausência de finding;
- `fix` possui política transacional/rollback definida;
- GLPI host permanece fora de mutação e fora de scan de dependências por padrão;
- `make profile-test` verde;
- workflow Go verde;
- documentação e matriz sincronizadas.

## 13. Dependências entre pacotes

```text
1.0
 ├── 2.0 ─┐
 └── 3.0 ─┴──> 4.0 ──> 5.0 ──> 6.0 ──> 7.0 ──> 8.0
```

Regras de precedência:

- 2.0 e 3.0 podem avançar em paralelo quando não editarem o mesmo contrato;
- 4.0 exige 2.0 e 3.0 classificados/estabilizados;
- 5.0 só começa funcionalmente após 4.0;
- 6.0 depende do hardening mínimo de parsing/reporting de 5.0;
- mutation testing de 7.0 não deve antecipar contratos ainda instáveis.

## 14. Estratégia de branches e PRs

Evitar branches longas e PRs monolíticos.

Preferência:

```text
feat/yii2-model-scenarios
feat/yii2-model-labels-hints
feat/yii2-view-resolution
hardening/structured-output
feat/fix-transaction
feat/frontend-sca-npm
feat/frontend-sca-pnpm-yarn
feat/frontend-sca-bun-deno
```

Cada PR deve:

- ter escopo único;
- atualizar documentação relacionada no mesmo PR;
- atualizar a matriz quando mudar estado upstream;
- conter fixtures/testes;
- deixar `main` utilizável após o merge.

## 15. Definition of Done por item

Um item implementado só é considerado concluído quando aplicável:

- [ ] contrato técnico definido;
- [ ] implementação concluída;
- [ ] finding normalizado;
- [ ] categoria/severidade/confiança definidas;
- [ ] política de `unknown` documentada;
- [ ] teste positivo;
- [ ] teste negativo;
- [ ] caso limite;
- [ ] caso inconclusivo;
- [ ] PHPDoc do próprio NINFA atualizado;
- [ ] documentação funcional atualizada;
- [ ] matriz upstream atualizada;
- [ ] remediação SAFE com idempotência, se houver;
- [ ] sem escrita indevida no consumidor;
- [ ] CI verde.

## 16. Fora de escopo desta EAP

- transformar o NINFA em Composer plugin;
- copiar o modelo de lifecycle do Foxy;
- reescrever `package.json` para agregar dependências Composer;
- instalar/atualizar automaticamente dependências frontend;
- transformar opiniões arquiteturais do upstream em gates universais;
- reescrever PHPDoc sem prova semântica forte;
- auditar host GLPI automaticamente junto com o plugin;
- exigir paridade 1:1 com todos os detalhes internos dos projetos de referência.

## 17. Estado inicial

| Pacote | Estado |
| --- | --- |
| 1.0 Governança e baseline | iniciado |
| 2.0 yii2-phpstan-rules | em andamento |
| 3.0 yii2-rector | avançado |
| 4.0 Gate Yii2 v1 | pendente |
| 5.0 Foxy hardening | planejado |
| 6.0 Frontend SCA | planejado |
| 7.0 Engenharia/release | planejado |
| 8.0 Gate final | pendente |

A próxima execução recomendada é o pacote **2.3 — Models**, começando por `scenarios()`, `attributeLabels()` e `attributeHints()`, seguido da revisão objetiva de gaps de views antes do Gate 4.0.
