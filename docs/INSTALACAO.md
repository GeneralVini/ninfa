# Instalação do Ninfa

O Ninfa é um orquestrador externo. O projeto consumidor não recebe cópias de `Makefile`, scripts, configs ou workflows do Ninfa.

## Uso inicial

Enquanto o MVP estiver na branch `feature/glpi-plugin-profile`, clone explicitamente essa branch fora do projeto consumidor:

```bash
git clone --branch feature/glpi-plugin-profile --single-branch \
  https://github.com/GeneralVini/ninfa.git /opt/ninfa
```

Adicione `/opt/ninfa/bin` ao `PATH`:

```bash
export PATH="/opt/ninfa/bin:$PATH"
```

Depois disso, já é possível executar diretamente:

```bash
ninfa check /caminho/do/projeto
ninfa fix /caminho/do/projeto
ninfa security /caminho/do/projeto
```

Não existe etapa obrigatória de instalação, merge de `composer.json` ou preparação do projeto consumidor.

## Plataformas Linux suportadas no setup

Os scripts internos de preparação e diagnóstico reconhecem duas famílias Linux:

- Debian-like: Debian, Ubuntu e derivados;
- RHEL-like: Oracle Linux, RHEL, Rocky Linux, AlmaLinux, CentOS e Fedora.

A detecção usa `/etc/os-release` e, como fallback, `apt-get` ou `dnf`. Quando um pré-requisito estiver ausente, o Ninfa informa o comando correspondente e encerra; não executa instalação de pacotes do sistema automaticamente.

O requisito efetivo para Semgrep é Python 3.10+ com `venv` funcional. O Ninfa não exige alteração do `python3` padrão do sistema.

No Oracle Linux/RHEL-like, os scripts procuram nesta ordem:

```text
python3.12
python3.11
python3.10
python3
```

O primeiro interpretador >= 3.10 é usado no virtualenv privado do Semgrep.

Orientação preferencial em Oracle Linux/RHEL-like:

```bash
sudo dnf install -y python3.12 python3.12-pip
# fallback quando necessário
sudo dnf install -y python3.11 python3.11-pip
```

Em Debian/Ubuntu:

```bash
sudo apt-get install -y python3 python3-venv
```

Os nomes de pacotes podem variar conforme a distribuição/repositórios habilitados. Não remapeie o Python do sistema apenas para executar o Ninfa.

## Diretórios

```text
/opt/ninfa/                   código do Ninfa
/caminho/do/projeto/          projeto consumidor analisado
/tmp/ninfa/<hash-do-projeto>/ workspace externo gerado
```

`NINFA_WORKSPACE_ROOT` pode alterar a raiz do workspace, mas o destino não pode ficar dentro do projeto consumidor.

## Diagnóstico manual

Para inspecionar profile, paths e configurações geradas:

```bash
php /opt/ninfa/scripts/ninfa-configure.php /caminho/do/projeto
```

Para validar o ambiente do próprio Ninfa:

```bash
bash /opt/ninfa/scripts/check-environment.sh
```

Esse script verifica PHP 8.2+, Git, arquivos obrigatórios, permissões dos entrypoints e Python 3.10+ adequado às ferramentas de segurança.

## Dependências

O Ninfa resolve ferramentas preferencialmente no consumidor, depois no ambiente do próprio Ninfa e por fim no `PATH`. Nenhuma dependência deve ser instalada silenciosamente no consumidor.

Projetos `php-generic` podem ter `composer.json` ou ser aplicações PHP simples sem Composer. Ferramentas dependentes de Composer só são executadas quando o contexto necessário existe.

O Semgrep CE gerenciado pelo próprio Ninfa é preparado por `scripts/install-security-tools.sh` em `.tools/semgrep`, sem Docker e sem instalação global. A versão é homologada pelo script e o ambiente é validado antes do uso.

## GLPI

Quando o plugin não estiver em `<glpi>/plugins/<plugin>`, informe:

```bash
export NINFA_GLPI_ROOT=/opt/glpi
ninfa check /caminho/do/plugin
```

Somente GLPI 11 identificável é aceito no profile atual.

## Desenvolvimento do próprio Ninfa

O `Makefile` é interno ao repositório:

```bash
make environment-check
make security-tools
make semgrep-rules
make syntax
make profile-test
make setup
```

`make semgrep-rules` depende da ferramenta gerenciada e executa:

```text
semgrep --validate --config security/semgrep
semgrep --test --config security/semgrep security/semgrep-tests
```

As configurações ficam em `security/semgrep/` e as fixtures em árvore paralela `security/semgrep-tests/`.

`make setup` executa, em cadeia, diagnóstico do ambiente, preparação do Semgrep, sintaxe, validação/testes das regras e suíte interna de profiles/runners. Projetos consumidores não precisam do `Makefile`.

Para validar apenas a sintaxe dos scripts shell alterados:

```bash
bash -n scripts/check-environment.sh
bash -n scripts/install-security-tools.sh
```

## Estado atual

Os profiles oficiais são:

```text
php-generic
yii2
yii3
glpi-plugin
```

Yii 22 é acompanhado dentro da família Yii2. Laravel e Python permanecem roadmap, sem dependências ou setup adicionais no estado atual.
