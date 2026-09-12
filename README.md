# Ninfa

**Ninfa** é uma esteira reutilizável de qualidade, compliance de código e segurança para projetos PHP.

O objetivo é fornecer um baseline simples, aberto e replicável, sem acoplar a esteira a um framework específico e sem exigir Docker para execução local.

## Visão geral

```text
NINFA
├── Qualidade e compliance
│   ├── ECS
│   ├── Rector
│   ├── PHPStan
│   ├── Psalm
│   └── PHPUnit
├── Segurança
│   ├── Composer Audit
│   ├── Psalm Taint Analysis
│   └── Semgrep CE
└── DAST
    └── OWASP ZAP
```

A validação normal do projeto é centralizada em:

```bash
composer check
```

O DAST permanece separado porque depende de uma aplicação em execução e realiza testes ativos:

```bash
NINFA_ZAP_TARGET=http://127.0.0.1:8080 composer security:dast
```

## Pré-requisitos

Para implantação completa em Linux:

- PHP compatível com o projeto;
- Composer 2;
- Git;
- Python 3.10+ com suporte a `venv`;
- Java 17+;
- `curl`;
- `sha256sum`;
- Lefthook opcional para hooks locais.

## Como o Ninfa entende o projeto

Antes de configurar a esteira, o Ninfa coleta contexto do próprio projeto.

A precedência é:

1. `composer.json` — fonte técnica principal para framework e dependências;
2. estrutura real de diretórios — fonte técnica para caminhos analisados;
3. `README.md` — contexto funcional e arquitetural;
4. `docs/*.md` — contexto complementar, decisões e particularidades do projeto.

O Ninfa reconhece inicialmente Yii 3, Yii 2, Laravel, Symfony e PHP genérico. Os diretórios convencionais detectados incluem `src`, `app`, `config`, `modules`, `console`, `commands`, `public`, `web` e `tests`.

O resultado da descoberta fica registrado em:

```text
.ninfa/context.json
.ninfa/paths.txt
```

Se `README.md` ou `docs/` divergirem do `composer.json`, a evidência técnica do Composer tem precedência e a divergência deve ser tratada como revisão manual.

## Geração automática das configurações

Depois de detectar os caminhos reais do projeto, o Ninfa gera automaticamente, **somente quando ainda não existirem**:

```text
ecs.php
rector.php
phpstan.neon.dist
psalm.xml
phpunit.xml.dist
```

Os caminhos detectados são aplicados diretamente nessas configurações. O Semgrep também usa automaticamente `.ninfa/paths.txt` como lista de alvos de análise.

Arquivos de configuração já existentes são preservados no modo normal e aparecem como `MANTIDO`.

Para regenerar e sobrescrever as configurações gerenciadas pelo Ninfa, use `--force`:

```bash
php scripts/ninfa-configure.php . --force
```

Ou pelo Makefile:

```bash
make configure-force
```

O modo `--force` deve ser usado somente quando a intenção for substituir o baseline existente pelos arquivos gerados a partir do contexto atual do projeto.

Se nenhum diretório convencional for encontrado, o Ninfa não inventa uma estrutura: registra o aviso e deixa a definição dos caminhos para revisão manual.

## Para implantar o Ninfa em um projeto já pronto

**Execute os comandos abaixo já estando na raiz do projeto consumidor.**

O repositório do Ninfa é clonado temporariamente fora do projeto, em `/tmp/ninfa`:

```bash
git clone --depth 1 https://github.com/GeneralVini/ninfa.git /tmp/ninfa
```

Execute o instalador apontando `.` para a raiz atual do projeto:

```bash
php /tmp/ninfa/bin/ninfa-install.php .
```

Para sobrescrever os arquivos gerenciados pelo Ninfa durante a implantação:

```bash
php /tmp/ninfa/bin/ninfa-install.php . --force
```

Com `--force`, o instalador substitui arquivos de infraestrutura Ninfa já existentes e também repassa `--force` ao configurador contextual, regenerando `ecs.php`, `rector.php`, `phpstan.neon.dist`, `psalm.xml` e `phpunit.xml.dist`.

O instalador cria os arquivos de integração **na raiz do projeto consumidor**. Isso é intencional: `Makefile`, `ecs.php`, `rector.php`, `phpstan.neon.dist`, `psalm.xml`, `phpunit.xml.dist`, `security/`, `scripts/` e `.github/workflows/ninfa.yml` passam a fazer parte do próprio projeto auditado.

O clone temporário do Ninfa permanece somente em `/tmp/ninfa` e pode ser removido ao final.

O instalador:

- lê `composer.json`;
- localiza os diretórios existentes;
- consulta `README.md` e `docs/*.md` como contexto complementar;
- copia a infraestrutura Ninfa ausente;
- gera automaticamente as configurações de ECS, Rector, PHPStan, Psalm e PHPUnit quando ainda não existem;
- preserva configurações existentes no modo normal;
- sobrescreve os arquivos gerenciados quando `--force` é informado;
- registra o contexto detectado em `.ninfa/context.json` e `.ninfa/paths.txt`;
- configura os alvos do Semgrep pelos paths detectados;
- informa apenas conflitos e divergências que realmente exigem revisão manual.

Instale as dependências PHP usadas pela esteira:

```bash
composer require --dev \
    symplify/easy-coding-standard \
    rector/rector \
    phpstan/phpstan \
    vimeo/psalm \
    phpunit/phpunit
```

Mescle os scripts do Ninfa no `composer.json` existente:

```bash
php scripts/merge-composer.php composer.json composer.ninfa.example.json
```

Depois execute:

```bash
make install
make setup
composer check
```

Para regenerar as configurações existentes antes de instalar as dependências:

```bash
make install-force
```

Revise as alterações e versione a integração:

```bash
git add .
git commit -m "chore: integra esteira Ninfa"
git push
```

### Sequência completa

Assumindo que o terminal já está na raiz do projeto consumidor:

```bash
git clone --depth 1 https://github.com/GeneralVini/ninfa.git /tmp/ninfa
php /tmp/ninfa/bin/ninfa-install.php .
composer require --dev symplify/easy-coding-standard rector/rector phpstan/phpstan vimeo/psalm phpunit/phpunit
php scripts/merge-composer.php composer.json composer.ninfa.example.json
make install
make setup
composer check

# Após validar a integração, remova o clone temporário do Ninfa
rm -rf /tmp/ninfa
```

### Sequência completa forçando regeneração

Use somente quando quiser substituir os arquivos gerenciados pelo Ninfa:

```bash
git clone --depth 1 https://github.com/GeneralVini/ninfa.git /tmp/ninfa
php /tmp/ninfa/bin/ninfa-install.php . --force
composer require --dev symplify/easy-coding-standard rector/rector phpstan/phpstan vimeo/psalm phpunit/phpunit
php scripts/merge-composer.php composer.json composer.ninfa.example.json
make install-force
make setup
composer check
rm -rf /tmp/ninfa
```

## Em uma máquina nova, depois que o projeto já usa Ninfa

Depois que a integração já foi versionada no projeto consumidor, não é necessário clonar o repositório do Ninfa novamente.

```bash
git clone URL_DO_PROJETO
cd NOME_DO_PROJETO
make install
make setup
composer check
```

## Quando revisar manualmente

A revisão manual não é uma etapa obrigatória. Ela fica restrita principalmente a:

- divergência entre `composer.json` e documentação;
- ausência de diretórios convencionais reconhecíveis;
- configurações existentes que precisem ser comparadas com os paths atuais;
- código gerado, módulos especiais ou diretórios que devam ser excluídos;
- framework ou arquitetura não identificados com segurança.

## Comandos

| Comando | Finalidade |
| --- | --- |
| `make install` | detecta contexto, gera configs ausentes e instala dependências Composer |
| `make install-force` | detecta contexto, sobrescreve configs Ninfa e instala dependências Composer |
| `make configure` | refaz descoberta e gera apenas configurações ainda ausentes |
| `make configure-force` | refaz descoberta e sobrescreve as configurações gerenciadas pelo Ninfa |
| `make setup` | prepara ferramentas locais, hooks e executa validação |
| `composer qa` | qualidade, análise estática e testes |
| `composer security` | dependências, taint analysis e Semgrep |
| `composer check` | executa `qa` + `security` |
| `composer fix` | aplica correções automáticas disponíveis |
| `composer security:dast` | executa OWASP ZAP contra aplicação local autorizada |

## DAST com OWASP ZAP

Com a aplicação local iniciada:

```bash
NINFA_ZAP_TARGET=http://127.0.0.1:8080 composer security:dast
```

Por segurança, o wrapper padrão aceita apenas `localhost` e `127.0.0.1`.

## GitHub Actions

O instalador disponibiliza o workflow em:

```text
.github/workflows/ninfa.yml
```

A pipeline executa:

```bash
composer check
```

O OWASP ZAP permanece fora desse workflow padrão porque depende de um alvo em execução.

## Princípios

- execução nativa em Linux;
- sem Docker obrigatório;
- detecção contextual do projeto antes da configuração;
- geração automática das configurações ausentes a partir dos paths reais;
- modo `--force` explícito para regeneração destrutiva dos arquivos gerenciados;
- `composer.json` e filesystem como evidência técnica principal;
- `README.md` e `docs/*.md` como contexto complementar;
- preservação de configurações existentes no modo normal;
- ferramentas PHP instaladas pelo Composer do projeto;
- Semgrep CE e OWASP ZAP instalados localmente no projeto;
- Composer Audit + Psalm Taint como baseline mínimo de segurança;
- Semgrep CE e ZAP como camadas complementares;
- DAST separado da validação comum;
- nenhuma supressão ampla apenas para deixar a pipeline verde.

## Documentação complementar

- [Instalação](docs/INSTALACAO.md)
- [Integração em projetos existentes](docs/INTEGRACAO.md)
- [Comandos](docs/COMANDOS.md)
- [Customização](docs/CUSTOMIZACAO.md)
- [Segurança](docs/SEGURANCA.md)

## Licença e uso

O Ninfa é um baseline técnico. Cada projeto consumidor continua responsável por revisar suas dependências, regras, riscos, falsos positivos e requisitos próprios de compliance e segurança.
