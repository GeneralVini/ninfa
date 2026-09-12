# Customização

O Ninfa define um baseline. Projetos consumidores devem adaptar somente o necessário.

## Caminhos de código

O template assume `src/` e `tests/`. Ajuste ECS, Rector, PHPStan, Psalm e PHPUnit conforme a estrutura real do projeto.

## Semgrep

Mantenha `security/semgrep.yml` pequeno e revisável. Regras específicas de arquitetura ou negócio pertencem ao projeto consumidor.

Evite duplicar verificações já cobertas de forma melhor por PHPStan, Psalm ou Composer Audit.

## Níveis de análise

O PHPStan inicia em `level: max`. Caso um legado não suporte adoção imediata, reduza temporariamente de forma explícita e documente a evolução planejada. Não use baseline permanente sem justificativa.

## Frameworks

Yii, Symfony e Laravel podem demandar extensões do PHPStan/Psalm ou bootstrap de testes. Essas integrações devem ficar no projeto consumidor e não no núcleo do Ninfa.

## CI

O workflow fornecido usa PHP 8.3 como valor neutro. Ajuste `php-version` para a versão oficialmente suportada pelo projeto.

## DAST

O DAST fornecido pelo template é destinado ao ambiente local de desenvolvimento. Outros ambientes devem seguir procedimento próprio de autorização e governança do projeto.
