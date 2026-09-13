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

É a camada separada para achados semânticos de PHPStan/Psalm. Não altera o projeto consumidor. A saída evita a tabela bruta como interface principal e apresenta cada achado em três campos:

```text
Regra: argument.type
Corrigir: Parameter #1 ... expects string, mixed given
Correção: Faça o valor atender ao contrato exigido antes da chamada...
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

Executa Composer Audit, Psalm Taint Analysis e Semgrep.

Para incluir DAST local autorizado:

```bash
NINFA_DAST=1 \
NINFA_ZAP_TARGET=http://127.0.0.1:8080 \
ninfa security /caminho/do/projeto
```

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
