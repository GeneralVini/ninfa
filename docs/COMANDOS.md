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

A camada semântica nativa Yii2 está inicialmente em calibração no `assist`; regras só devem ser promovidas para o gate de `check` depois de fixtures e field tests demonstrarem sinal suficiente.

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

Regras Yii2 com efeito semântico ou PHPDoc do consumidor não entram automaticamente em `fix`. Remediações específicas de framework serão habilitadas por risco somente depois de classificadas e testadas como seguras.

## Assist

```bash
ninfa assist /caminho/do/projeto
```

É a camada separada para achados semânticos. Não altera o projeto consumidor. PHPStan e Psalm continuam sendo executados em formato estruturado; profiles especializados podem acrescentar findings nativos usando o mesmo contrato `Finding` e o mesmo renderer visual.

No profile Yii2, o Ninfa constrói um modelo semântico conservador de capabilities, controllers/actions, referências literais a views e relações observáveis. A primeira regra nativa é `NINFA-YII2-COR-001`, que reporta view literal inexistente somente quando o path convencional foi resolvido de forma conclusiva. Nomes dinâmicos ou não resolvíveis não são tratados como erro.

A auditoria completa fica no workspace externo:

```text
/tmp/ninfa/<hash>/assist/findings.json
/tmp/ninfa/<hash>/assist/phpstan.json
/tmp/ninfa/<hash>/assist/phpstan.stderr.log
/tmp/ninfa/<hash>/assist/psalm.json
/tmp/ninfa/<hash>/assist/psalm.stderr.log
```

Para Yii2 também são gravados:

```text
/tmp/ninfa/<hash>/assist/yii2-semantic.json
/tmp/ninfa/<hash>/assist/yii2-findings.json
```

`findings.json` continua sendo a visão consolidada; os arquivos específicos preservam evidência/auditoria do profile.

O comando retorna `1` quando ainda existem findings estruturados e `0` quando nenhuma fonte executada retorna achados. Falha de ferramenta sem finding estruturado preserva o exit code correspondente.

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

Correctness, performance, deprecation e preferências arquiteturais da camada semântica Yii2 não são automaticamente classificadas como vulnerabilidade. Uma regra específica só entra em `security` quando houver contrato de segurança e evidência apropriada.

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

O comando informa profile, paths, workspace, configs externas, índice semântico e Lefthook gerado. Em Yii2, `semantic-index.json` recebe ainda `framework_semantics` com o snapshot do modelo semântico; isso não substitui os hints documentais já existentes.

`--force` permanece aceito apenas por compatibilidade do configurador; o workspace externo é regenerado a cada execução.

## Testes do próprio Ninfa

Validação estrutural dos profiles e runners:

```bash
make profile-test
```

O target inclui o guard documental e o teste da fundação semântica Yii2, além dos contratos já existentes.

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

Para detalhes do profile Yii2 e sua política de PHPDoc/remediação, consulte [YII2-ANALYSIS.md](YII2-ANALYSIS.md).
