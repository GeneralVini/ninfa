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

A auditoria completa fica no workspace externo:

```text
/tmp/ninfa/<hash>/assist/findings.json
/tmp/ninfa/<hash>/assist/phpstan.json
/tmp/ninfa/<hash>/assist/phpstan.stderr.log
/tmp/ninfa/<hash>/assist/psalm.json
/tmp/ninfa/<hash>/assist/psalm.stderr.log
```

O comando retorna `1` quando ainda existem achados e `0` quando PHPStan/Psalm não retornam findings.

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
