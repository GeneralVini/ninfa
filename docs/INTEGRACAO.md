# Integração do Ninfa

O Ninfa deve ser incorporado de forma incremental ao projeto existente.

## 1. Fontes de contexto

Antes de configurar a esteira, o Ninfa usa esta precedência:

1. `composer.json` para framework e dependências;
2. estrutura real do filesystem para caminhos analisados;
3. `README.md` para contexto funcional e arquitetural;
4. `docs/*.md` para decisões, particularidades e convenções do projeto.

`composer.json` e filesystem têm precedência técnica. README e docs complementam o entendimento e ajudam a detectar divergências.

O contexto detectado é salvo em:

```text
.ninfa/context.json
.ninfa/paths.txt
```

## 2. Frameworks e caminhos reconhecidos

O baseline reconhece inicialmente Yii 3, Yii 2, Laravel, Symfony e PHP genérico.

Os caminhos convencionais pesquisados incluem:

```text
src/
app/
config/
modules/
console/
commands/
public/
web/
tests/
```

Se nenhum desses diretórios existir, o Ninfa não cria uma estrutura fictícia. Ele informa que o projeto possui estrutura não convencional e exige definição manual dos paths.

## 3. Geração automática

Com os caminhos detectados, o Ninfa gera automaticamente, quando ausentes:

```text
ecs.php
rector.php
phpstan.neon.dist
psalm.xml
phpunit.xml.dist
```

Os arquivos gerados já recebem os diretórios reais encontrados no projeto.

Para o Semgrep, os caminhos são gravados em `.ninfa/paths.txt` e utilizados automaticamente por `scripts/semgrep-scan.sh`.

Se qualquer uma dessas configurações já existir, ela é preservada e marcada como `MANTIDO`. O Ninfa nunca substitui silenciosamente uma configuração madura.

## 4. Pré-requisitos

- PHP compatível com as dependências do projeto;
- Composer 2;
- Git;
- Python 3.10+ com suporte a `venv`;
- Java 17+;
- `curl`;
- `sha256sum`;
- Lefthook opcional para hooks locais.

## 5. Implantação inicial

Depois de executar o instalador do Ninfa e instalar as dependências PHP, execute:

```bash
make install
make setup
composer check
```

`make install` executa novamente a descoberta contextual, gera apenas configurações ainda ausentes e então executa `composer install`.

Para refazer somente a descoberta e a geração de arquivos ausentes:

```bash
make configure
```

## 6. Preservação de configurações existentes

A revisão manual deixa de ser uma etapa comum. Ela deve ficar restrita a casos como:

- divergência entre documentação e `composer.json`;
- estrutura não convencional;
- configuração existente que precise ser comparada com os paths atuais;
- diretórios gerados ou que devam ser excluídos;
- framework ou arquitetura não identificados com segurança.

Uma configuração existente de ECS, Rector, PHPStan, Psalm ou PHPUnit nunca é reescrita pelo configurador.

## 7. README e docs existentes

Não substitua o `README.md` do projeto. Use `templates/README-NINFA.md` como referência para incorporar uma seção operacional.

Como os projetos normalmente já possuem `docs/`, copie ou incorpore `templates/docs/NINFA.md` ao documento equivalente já existente.

## 8. Validação

A integração está concluída quando:

```bash
composer check
```

executa com sucesso localmente e no GitHub Actions, sem supressões genéricas criadas apenas para contornar achados.

O DAST permanece separado:

```bash
NINFA_ZAP_TARGET=http://127.0.0.1:8080 composer security:dast
```
