# Ninfa no projeto

Este documento registra como o projeto utiliza a esteira Ninfa.

## Finalidade

O Ninfa centraliza verificações de qualidade, análise estática, testes, dependências vulneráveis, taint analysis e regras adicionais de segurança.

## Comandos adotados

```bash
make setup
composer qa
composer security
composer check
composer fix
```

DAST local:

```bash
NINFA_ZAP_TARGET=http://127.0.0.1:8080 composer security:dast
```

## Ferramentas

- ECS: estilo e PSR-12;
- Rector: refatoração automatizada e verificação em dry-run;
- PHPStan: análise estática;
- Psalm: análise estática complementar;
- PHPUnit: testes automatizados;
- Composer Audit: vulnerabilidades conhecidas em dependências;
- Psalm Taint Analysis: rastreamento de dados não confiáveis;
- Semgrep CE: regras adicionais e específicas do projeto;
- OWASP ZAP: análise dinâmica da aplicação local em execução.

## Ajustes específicos deste projeto

Documente aqui apenas diferenças em relação ao baseline do Ninfa, por exemplo:

- diretórios adicionais analisados;
- extensões PHPStan/Psalm;
- regras Semgrep próprias;
- bootstrap específico de testes;
- versão de PHP adotada no CI;
- exclusões justificadas.

## Regra de manutenção

Mudanças na esteira devem manter `composer check` reproduzível localmente e no CI. Achados não devem ser ocultados por supressões amplas apenas para obter sucesso na execução.
