# Comandos do Ninfa

A API pública do MVP possui três comandos. Nos exemplos abaixo, o Ninfa está clonado em `/opt/ninfa`, fora do projeto consumidor.

## Check

```bash
/opt/ninfa/bin/ninfa check /caminho/do/projeto
```

Executa ECS, Rector em dry-run, PHPStan, Psalm e PHPUnit quando disponível. Em projetos com contexto JS/TS, também executa ESLint e `prettier --check`.

## Fix

```bash
/opt/ninfa/bin/ninfa fix /caminho/do/projeto
```

Executa os fixers disponíveis:

```text
ECS --fix
Rector
ESLint --fix      # frontend
Prettier --write  # frontend
```

Ao terminar, executa `check` novamente.

## Security

```bash
/opt/ninfa/bin/ninfa security /caminho/do/projeto
```

Executa Composer Audit, Psalm Taint Analysis e Semgrep.

Para incluir DAST local autorizado:

```bash
NINFA_DAST=1 \
NINFA_ZAP_TARGET=http://127.0.0.1:8080 \
/opt/ninfa/bin/ninfa security /caminho/do/projeto
```

## Configuração e diagnóstico

Para gerar ou inspecionar o workspace externo:

```bash
php /opt/ninfa/scripts/ninfa-configure.php /caminho/do/projeto
```

O comando informa profile, paths, workspace, configs externas, índice semântico e Lefthook gerado.

`--force` permanece aceito apenas por compatibilidade do configurador; o workspace externo é regenerado a cada execução.

## Testes do próprio Ninfa

Somente dentro do repositório Ninfa:

```bash
make profile-test
```

Esse target executa os testes de profiles, configs externas, instalação sem boilerplate, pipelines, semântica, tooling e fixture GLPI.
