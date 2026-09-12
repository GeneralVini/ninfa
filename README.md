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

## Princípios

- execução nativa em Linux;
- sem Docker obrigatório;
- ferramentas PHP instaladas pelo Composer do projeto;
- Semgrep CE instalado em ambiente Python local do projeto;
- OWASP ZAP instalado localmente no projeto;
- versões das ferramentas externas fixadas pelo instalador;
- análise de dependências com `composer audit`;
- análise de fluxo de dados com Psalm Taint;
- regras Semgrep pequenas, explícitas e customizáveis;
- DAST separado da validação comum;
- nenhuma supressão ampla apenas para deixar a pipeline verde;
- integração incremental em projetos existentes.

## Comandos

| Comando | Finalidade |
| --- | --- |
| `make setup` | prepara dependências, ferramentas e hooks locais |
| `composer qa` | qualidade, análise estática e testes |
| `composer security` | dependências, taint analysis e Semgrep |
| `composer check` | executa `qa` + `security` |
| `composer fix` | aplica correções automáticas disponíveis |
| `composer security:dast` | executa OWASP ZAP contra aplicação local autorizada |

## Fluxo recomendado

Após integrar o Ninfa a um projeto:

```bash
make setup
composer check
```

No trabalho diário:

```bash
composer fix && git add . && git commit -m "feat: descrição da alteração" && git push
```

Antes de integração ou entrega:

```bash
composer check
```

Com a aplicação local em execução:

```bash
NINFA_ZAP_TARGET=http://127.0.0.1:8080 composer security:dast
```

## Estrutura

```text
.
├── README.md
├── Makefile
├── composer.ninfa.example.json
├── ecs.php
├── rector.php
├── phpstan.neon.dist
├── psalm.xml
├── phpunit.xml.dist
├── lefthook.yml
├── security/
│   └── semgrep.yml
├── scripts/
│   ├── bootstrap.sh
│   ├── install-security-tools.sh
│   ├── semgrep-scan.sh
│   └── zap-scan.sh
├── templates/
│   └── github-actions/
│       └── qa-security.yml
└── docs/
    ├── INTEGRACAO.md
    ├── COMANDOS.md
    ├── CUSTOMIZACAO.md
    └── SEGURANCA.md
```

## Integração em projetos existentes

O Ninfa não deve substituir automaticamente `README.md`, documentação em `docs/`, `composer.json` ou configurações que o projeto já possua.

A adoção correta é incremental:

1. copiar os scripts e configurações necessários;
2. incorporar os scripts Composer de `composer.ninfa.example.json` ao `composer.json` existente;
3. revisar os caminhos de código em ECS, Rector, PHPStan, Psalm e PHPUnit;
4. copiar o workflow de `templates/github-actions/qa-security.yml` para `.github/workflows/` do projeto;
5. acrescentar ao `README.md` existente apenas a seção operacional do Ninfa;
6. registrar decisões específicas do projeto nos documentos já existentes em `docs/`;
7. executar `make setup` e `composer check`.

Detalhes: [docs/INTEGRACAO.md](docs/INTEGRACAO.md).

## Compatibilidade

O baseline foi pensado para PHP moderno e pode ser usado com Yii, Symfony, Laravel ou PHP sem framework. Cada projeto deve ajustar diretórios analisados, bootstrap de testes e regras específicas de segurança.

## Escopo das ferramentas

**ECS** verifica estilo e padrões de código. **Rector** verifica e automatiza refatorações. **PHPStan** e **Psalm** realizam análise estática. **PHPUnit** executa testes. **Composer Audit** identifica vulnerabilidades conhecidas em dependências. **Psalm Taint Analysis** rastreia dados potencialmente não confiáveis até sinks sensíveis. **Semgrep CE** aplica regras de segurança e padrões específicos do projeto. **OWASP ZAP** testa dinamicamente a aplicação em execução.

A combinação mínima de segurança priorizada é:

```text
Composer Audit + Psalm Taint Analysis
```

Semgrep CE e OWASP ZAP acrescentam camadas complementares.

## Licença e uso

O Ninfa é um baseline técnico. Cada projeto consumidor continua responsável por revisar suas dependências, regras, riscos, falsos positivos e requisitos próprios de compliance e segurança.
