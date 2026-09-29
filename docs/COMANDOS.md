# Comandos do Ninfa

A API pública do MVP possui quatro comandos. Os exemplos abaixo assumem que `/opt/ninfa/bin` já está no `PATH`:

```bash
export PATH="/opt/ninfa/bin:$PATH"
```

## Check

```bash
ninfa check /caminho/do/projeto
```

Executa ECS, Rector em dry-run, PHPStan, Psalm e PHPUnit quando disponível. Em projetos com contexto JS/TS, também executa ESLint e `prettier --check`.

Os achados de PHPStan e Psalm são capturados em formato estruturado e apresentados pelo renderer do Ninfa, sem depender da tabela nativa de cada ferramenta. O `check` mostra arquivo, linha, regra e o que precisa ser corrigido, mas não sugere alteração semântica.

As regras nativas Yii2 ainda permanecem em field test no `assist`; não foram promovidas em bloco ao gate de `check`.

## Fix

```bash
ninfa fix /caminho/do/projeto
```

O fluxo comum executa os fixers aplicáveis:

```text
ECS --fix
Rector
ESLint --fix      # frontend
Prettier --write  # frontend
```

No profile Yii2 existe uma etapa anterior de remediação nativa SAFE. Ela não é um pseudo-binário de `PipelinePlan`: o CLI executa `Yii2SafeRemediator` antes dos fixers externos e o `RecheckingPipelineRunner` mantém exatamente **um `check` final** para validar o resultado completo.

SAFE nativo atual:

```text
NINFA-YII2-DEP-001  Yii::trace() -> Yii::debug()
NINFA-YII2-DEP-002  Controller::EXIT_CODE_* -> ExitCode::*
NINFA-YII2-DEP-003  return 0/1 em console action -> ExitCode::*
NINFA-YII2-MOD-001  find()->where(hash)->one/all -> findOne/findAll
```

`MOD-001` só é aplicado quando `where()` recebe array associativo literal não vazio com chaves string literais e a classe é comprovadamente ActiveRecord Yii2. Listas, operator format, arrays vazios, spread, variáveis e classes incertas não são alterados.

Patches SAFE são aplicados por offset em ordem reversa, intervalos sobrepostos são rejeitados e a suíte exige idempotência: a segunda aplicação sobre código já corrigido precisa resultar em zero mudanças.

Regras REVIEW/SEMANTIC, como `PERF-001`, `TYPE-001` e `ARCH-001`, nunca são alteradas por `fix` nesta fase.

## Assist

```bash
ninfa assist /caminho/do/projeto
```

É a camada separada para achados semânticos de PHPStan/Psalm e do profile Yii2. Não altera o projeto consumidor. Usa o renderer do Ninfa e preserva evidência auditável no workspace externo.

Catálogo Yii2 atual:

```text
NINFA-YII2-COR-001   view literal inexistente
NINFA-YII2-COR-002   action inexistente em filtros/behaviors estáticos
NINFA-YII2-COR-003   relation path literal inexistente em with/joinWith/innerJoinWith
NINFA-YII2-COR-004   atributo inexistente em link literal de hasOne/hasMany
NINFA-YII2-COR-005   aridade inválida em operator de condition array estática
NINFA-YII2-PERF-001  one()/count() usados apenas para testar existência
NINFA-YII2-MOD-001   shortcut findOne/findAll seguro
NINFA-YII2-DEP-001   Yii::trace() deprecated
NINFA-YII2-DEP-002   constante de exit code legada
NINFA-YII2-DEP-003   magic number 0/1 em console action
NINFA-YII2-TYPE-001  relation ausente de PHPDoc @property* já adotado pela classe
NINFA-YII2-ARCH-001  Yii::$app->request/response dentro de controller comprovado
```

Política por família:

| Família | Severidade atual | Autofix | Observação |
| --- | --- | --- | --- |
| `COR-*` | error | não | ausência/contrato objetivo comprovável |
| `PERF-*` | warning | não | advisory, REVIEW |
| `MOD-*` | warning | somente SAFE | modernização mecânica |
| `DEP-*` | warning | somente SAFE | API/forma legada com equivalência comprovada |
| `TYPE-*` | warning | não | PHPDoc semântico, SEMANTIC |
| `ARCH-*` | warning | não | opinião arquitetural/advisory |

`PERF-001` recomenda `exists()`/`!exists()` somente quando `one()`/`count()` são usados como booleano em chain `ActiveRecord::find()` local comprovável. Uso real do registro/contagem, threshold diferente ou tipo de query incerto não gera finding.

`COR-005` analisa apenas `where()/andWhere()/orWhere()` com condition array literal em ActiveRecord comprovado. Hash conditions, variáveis, spread e receivers incertos permanecem fora da regra.

`TYPE-001` só exige property tag quando a própria classe já mantém contrato `@property*`. Classes sem essa convenção, com `__get/__set` customizado ou propriedade pública nativa de mesmo nome são ignoradas. O Ninfa não autoedita PHPDoc semanticamente sensível.

`ARCH-001` é explicitamente arquitetura, não vulnerabilidade: em controller Yii2 comprovado, orienta preferir `$this->request`/`$this->response` a `Yii::$app->request`/`response`.

Referências dinâmicas ou inventários inconclusivos permanecem `unknown` e não geram finding.

A auditoria completa fica no workspace externo:

```text
/tmp/ninfa/<hash>/assist/findings.json
/tmp/ninfa/<hash>/assist/phpstan.json
/tmp/ninfa/<hash>/assist/phpstan.stderr.log
/tmp/ninfa/<hash>/assist/psalm.json
/tmp/ninfa/<hash>/assist/psalm.stderr.log
/tmp/ninfa/<hash>/assist/yii2-semantic.json   # somente Yii2
/tmp/ninfa/<hash>/assist/yii2-findings.json  # somente Yii2
```

O comando retorna `1` quando ainda existem achados e `0` quando PHPStan/Psalm e as regras nativas aplicáveis não retornam findings.

## Security

```bash
ninfa security /caminho/do/projeto
```

Executa o escopo ativo de segurança do Ninfa:

```text
Composer Audit        # SCA, quando houver composer.lock
OSV                   # SCA sobre packages resolvidos
Psalm Taint Analysis  # SAST/dataflow
Semgrep               # SAST/regras common + overlay do profile
```

Os profiles PHP oficiais são `php-generic`, `yii2`, `yii3` e `glpi-plugin`. `php-generic` usa o baseline comum. Yii2, Yii3 e GLPI Plugin 11 recebem overlays de segurança próprios.

Findings de correctness, performance, modernization, deprecation, PHPDoc ou architecture advisory não migram automaticamente para `security`. Uma regra só é SAST quando existe contrato de segurança e evidência apropriada.

No Semgrep:

```text
ERROR    finding bloqueante
WARNING  hotspot para revisão, não bloqueia sozinho
```

Falha do mecanismo ou cobertura parcial inesperada continua sendo erro do gate. Exclusões deliberadas de `vendor`, `runtime` e assets gerados são registradas como política e não são confundidas com perda de cobertura.

O scan inclui arquivos ainda não rastreados pelo Git dentro dos paths permitidos pelo profile.

DAST não integra o comando. Se `NINFA_DAST=1` for informado, o Ninfa emite aviso e continua sem executar OWASP ZAP.

A saída humana segue `NINFA_COLOR=auto` por padrão. Consulte [CORES.md](CORES.md) para `always`, `never` e `NO_COLOR`.

## Configuração e diagnóstico

Para gerar/inspecionar o workspace externo:

```bash
php /opt/ninfa/scripts/ninfa-configure.php /caminho/do/projeto
```

O comando informa profile, paths, workspace, configs externas, índice semântico e Lefthook gerado.

`--force` permanece aceito apenas por compatibilidade do configurador; o workspace externo é regenerado a cada execução.

## Testes do próprio Ninfa

Validação estrutural dos profiles, regras e remediações:

```bash
make profile-test
```

A suíte Yii2 dedicada inclui:

```text
tests/yii2-semantic-model.php
tests/yii2-query-existence.php
tests/yii2-query-condition.php
tests/yii2-deprecation-remediation.php
tests/yii2-find-shortcut.php
tests/yii2-magic-property.php
tests/yii2-controller-access.php
```

Validação das regras Semgrep do próprio Ninfa:

```bash
make semgrep-rules
```

Esse target garante a instalação gerenciada do Semgrep e executa, nesta ordem:

```text
semgrep --validate --config security/semgrep
semgrep --test --config security/semgrep security/semgrep-tests
```

O setup completo executa ambos:

```bash
make setup
```
