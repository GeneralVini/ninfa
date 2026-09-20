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

É a camada separada para achados semânticos de PHPStan/Psalm. Não altera o projeto consumidor. Usa o mesmo renderer visual do `check` e acrescenta a orientação de correção:

```text
╭─ PHPStan ─────────────────────────────────────────────────────
│ Arquivo: src/Web/Shared/Layout/Main/layout.php:27
│ Regra: argument.type
│
│ Corrigir:
│   Parameter #1 $path of function dirname expects string, mixed given
│
│ Correção:
│   Valide/refine o valor como string na origem antes do uso; evite cast
│   cego de mixed.
╰────────────────────────────────────────────────────────────────────────
```

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
Psalm Taint Analysis  # SAST
Semgrep               # SAST
```

DAST não integra mais o comando. A análise dinâmica foi delegada a uma frente especializada externa. Se `NINFA_DAST=1` for informado, o Ninfa emite aviso e continua sem executar OWASP ZAP.

A prioridade do comando `security` é amadurecer SAST orientado a profile e normalizar findings de Psalm Taint e Semgrep antes de ampliar o conjunto de scanners.

## Configuração e diagnóstico

Para gerar/inspecionar o workspace externo:

```bash
php /opt/ninfa/scripts/ninfa-configure.php /caminho/do/projeto
```

O comando informa profile, paths, workspace, configs externas, índice semântico e Lefthook gerado.

`--force` permanece aceito apenas por compatibilidade do configurador; o workspace externo é regenerado a cada execução.

## Testes do próprio Ninfa

Dentro do repositório Ninfa:

```bash
make profile-test
```

Esse target executa os testes de profiles, configs externas, pipelines, semântica, tooling, `assist` e fixture GLPI.
