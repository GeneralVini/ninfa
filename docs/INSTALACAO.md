# Instalação do Ninfa

O Ninfa é um orquestrador externo. O projeto consumidor não recebe cópias de `Makefile`, scripts, configs ou workflows do Ninfa.

## Uso inicial

Enquanto o MVP ainda estiver na branch `feature/glpi-plugin-profile`, clone explicitamente essa branch fora do projeto consumidor:

```bash
git clone --branch feature/glpi-plugin-profile --single-branch \
  https://github.com/GeneralVini/ninfa.git /opt/ninfa
```

Não use o clone padrão sem `--branch` nesta fase, porque ele aponta para o branch default do repositório e pode não conter o MVP atual.

Prepare o contexto e o workspace externo:

```bash
php /opt/ninfa/bin/ninfa-install.php /caminho/do/projeto
```

O instalador apenas valida a raiz e delega para o configurador do próprio Ninfa. Ele não altera `.gitignore`, `composer.json` nem cria boilerplate no consumidor.

Também é possível chamar o configurador diretamente:

```bash
php /opt/ninfa/scripts/ninfa-configure.php /caminho/do/projeto
```

O workspace é criado fora do projeto, por padrão em `/tmp/ninfa/<hash>`.

## Execução

```bash
/opt/ninfa/bin/ninfa check /caminho/do/projeto
/opt/ninfa/bin/ninfa fix /caminho/do/projeto
/opt/ninfa/bin/ninfa security /caminho/do/projeto
```

O projeto deve possuir `composer.json` e corresponder a um dos profiles suportados: `glpi-plugin`, `yii3` ou `yii2`.

## Dependências de ferramentas

O Ninfa tenta resolver ferramentas no ambiente do projeto, no ambiente do próprio Ninfa e depois no `PATH`. Ferramentas Node também são procuradas em `node_modules/.bin`.

No MVP, é esperado que projetos de teste tenham disponíveis as ferramentas que pretendem executar, como ECS, Rector, PHPStan, Psalm, PHPUnit, ESLint, Prettier, Composer e Semgrep.

## GLPI

Plugins GLPI precisam de um host GLPI 11. Quando o plugin não estiver em `<glpi>/plugins/<plugin>`, informe:

```bash
export NINFA_GLPI_ROOT=/opt/glpi
```

## DAST

DAST é desabilitado por padrão. Para um alvo local autorizado:

```bash
NINFA_DAST=1 \
NINFA_ZAP_TARGET=http://127.0.0.1:8080 \
/opt/ninfa/bin/ninfa security /caminho/do/projeto
```

## Estado atual

Esta instalação corresponde ao MVP experimental. O foco atual é testar a arquitetura externa em projetos reais antes de definir empacotamento/distribuição definitiva.
