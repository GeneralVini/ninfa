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

Exemplo:

```text
╭─ PHPStan ─────────────────────────────────────────────────────
│ Arquivo: src/Web/Shared/Layout/Main/layout.php:27
│ Regra: argument.type
│
│ Corrigir:
│   Parameter #1 $path of function dirname expects string, mixed given
╰────────────────────────────────────────────────────────────────────────
```

## Fix

```bash
ninfa fix /caminho/do/projeto
```

Executa os fixers disponíveis:

```text
ECS --fix
Rector
ESLint --fix      # frontend
Prettier --write  # frontend
```

Ao terminar, executa `check` novamente. Achados sem correção mecânica permanecem bloqueantes e podem ser detalhados pelo `assist`.

## Assist

```bash
ninfa assist /caminho/do/projeto
```

É a camada separada para achados semânticos de PHPStan/Psalm. Não altera o projeto consumidor. Usa o mesmo renderer visual do `check` e acrescenta a orientação de correção.

No profile Yii2, o `assist` também executa regras nativas em field test. Atualmente isso inclui:

```text
NINFA-YII2-COR-001   view literal inexistente
NINFA-YII2-COR-002   action inexistente referenciada por filtros/behaviors estáticos
NINFA-YII2-COR-003   relation path literal inexistente em with/joinWith/innerJoinWith
NINFA-YII2-COR-004   atributo inexistente em link literal de hasOne/hasMany
NINFA-YII2-PERF-001  one()/count() usados apenas para testar existência
```

As quatro regras `COR-*` são findings de correctness. `PERF-001` é advisory de performance com `severity=warning`: quando uma chain `ActiveRecord::find()` local e comprovável termina em `one()`/`count()` e o resultado é comparado apenas para saber se há registros, o Ninfa recomenda `exists()` ou `!exists()`.

`PERF-001` reconhece equivalências estritas como `one() !== null`, `one() === null`, `count() > 0`, `count() !== 0`, `count() <= 0`, `count() >= 1` e as formas com os operandos invertidos. Uso de `count()` como número real, `one()` como registro, thresholds diferentes, variáveis de query sem tipo comprovável e factories dinâmicas não geram finding nesta tranche.

Referências dinâmicas ou cujo inventário de actions/relações não seja conclusivo permanecem `unknown` e não geram finding. Em `COR-003`, parent ActiveRecord externo desconhecido, traits que possam introduzir relações e target intermediário não resolvível impedem a afirmação de ausência.

`COR-004` só nega atributo quando o ActiveRecord daquele lado declara `attributes()` como lista literal completa ou herda esse contrato de classe local conclusiva. Schema implícito do banco, PHPDoc isolado, `parent::attributes()`, `array_merge()`, links dinâmicos e relações seguidas por `via()`/`viaTable()` não são tratados como prova de ausência.

Nenhuma regra nativa Yii2 dessa tranche executa autofix. `PERF-001` já fornece remediation textual, mas permanece classificada como `review` até field tests reais permitirem decidir se a transformação pode migrar para uma classe SAFE.

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

No Semgrep:

```text
ERROR    finding bloqueante
WARNING  hotspot para revisão, não bloqueia sozinho
```

Falha do mecanismo ou cobertura parcial inesperada continua sendo erro do gate. Exclusões deliberadas de `vendor`, `runtime` e assets gerados são registradas como política e não são confundidas com perda de cobertura.

O scan inclui arquivos ainda não rastreados pelo Git dentro dos paths permitidos pelo profile. Isso evita que um arquivo PHP recém-criado fique fora da análise apenas porque ainda não passou por `git add`.

DAST não integra o comando. A análise dinâmica foi delegada a uma frente especializada externa. Se `NINFA_DAST=1` for informado, o Ninfa emite aviso e continua sem executar OWASP ZAP.

A saída humana segue `NINFA_COLOR=auto` por padrão. Consulte [CORES.md](CORES.md) para `always`, `never` e `NO_COLOR`.

## Configuração e diagnóstico

Para gerar/inspecionar o workspace externo:

```bash
php /opt/ninfa/scripts/ninfa-configure.php /caminho/do/projeto
```

O comando informa profile, paths, workspace, configs externas, índice semântico e Lefthook gerado.

`--force` permanece aceito apenas por compatibilidade do configurador; o workspace externo é regenerado a cada execução.

## Testes do próprio Ninfa

Validação estrutural dos profiles e runners:

```bash
make profile-test
```

A suíte inclui `tests/yii2-semantic-model.php` para correctness Yii2 e `tests/yii2-query-existence.php` para `PERF-001`, além dos contratos gerais do pipeline.

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

As fixtures Semgrep usam árvores paralelas entre `security/semgrep/` e `security/semgrep-tests/`, com casos positivos (`ruleid`) e negativos (`ok`).
