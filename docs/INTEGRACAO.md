# Integração do Ninfa

O Ninfa deve ser incorporado de forma incremental ao projeto existente.

## 1. Fontes de contexto

Antes de configurar a esteira, o Ninfa usa esta precedência:

1. `composer.json` para framework e dependências;
2. estrutura real do filesystem para caminhos analisados;
3. `README.md` para contexto funcional e arquitetural;
4. `docs/*.md` para decisões, particularidades e convenções do projeto.

`composer.json` e filesystem têm precedência técnica. README e docs complementam o entendimento e ajudam a detectar divergências.

O contexto detectado é salvo em `.ninfa/context.json`.

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

Estruturas fora desse padrão devem ser revisadas manualmente.

## 3. Pré-requisitos

- PHP compatível com as dependências do projeto;
- Composer 2;
- Git;
- Python 3.10+ com suporte a `venv`;
- Java 17+;
- `curl`;
- `sha256sum`;
- Lefthook opcional para hooks locais.

## 4. Implantação inicial

Depois de copiar a estrutura do Ninfa para o projeto e instalar as dependências PHP, execute:

```bash
make install
make setup
composer check
```

`make install` executa a descoberta contextual antes de `composer install`.

Para refazer somente a descoberta:

```bash
make configure
```

## 5. Preservação de configurações existentes

O Ninfa não deve sobrescrever silenciosamente configurações maduras do projeto.

Arquivos existentes são preservados. A revisão manual deve ficar restrita a casos como:

- divergência entre documentação e `composer.json`;
- estrutura não convencional;
- configuração própria de ECS, Rector, PHPStan, Psalm, PHPUnit ou Semgrep;
- diretórios gerados ou que devam ser excluídos;
- framework ou arquitetura não identificados com segurança.

## 6. README e docs existentes

Não substitua o `README.md` do projeto. Use `templates/README-NINFA.md` como referência para incorporar uma seção operacional.

Como os projetos normalmente já possuem `docs/`, copie ou incorpore `templates/docs/NINFA.md` ao documento equivalente já existente.

## 7. Validação

A integração está concluída quando:

```bash
composer check
```

executa com sucesso localmente e no GitHub Actions, sem supressões genéricas criadas apenas para contornar achados.

O DAST permanece separado:

```bash
NINFA_ZAP_TARGET=http://127.0.0.1:8080 composer security:dast
```
