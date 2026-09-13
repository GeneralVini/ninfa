# Ninfa CLI

O Ninfa recebe opcionalmente a raiz do projeto e detecta os profiles ativos `glpi-plugin`, `yii3`, `yii2` e `php-generic`.

Profiles especializados têm precedência sobre `php-generic`. O profile genérico exige evidência real de código PHP e também suporta projetos simples sem `composer.json`. Laravel e Symfony permanecem em **stand by**.

## API pública

```bash
ninfa check [root]
ninfa fix [root]
ninfa security [root]
```

Quando `root` é omitido, o diretório atual é usado.

Também está disponível:

```bash
ninfa --help
```

Não existem comandos públicos separados para PHPStan, Psalm, Rector ou ECS. Essas ferramentas são detalhes internos dos pipelines.

`check` executa qualidade e testes. `security` permanece separado. `fix` aplica os hooks corrigíveis e executa um único `check` ao final.

Em projetos com JavaScript/TypeScript aplicável, ESLint e Prettier entram no mesmo pipeline e respeitam a ordem definida pelo plano.

No profile `glpi-plugin`, PHPStan e Psalm usam nível 8 e o host precisa ser GLPI 11 com versão identificável.

No profile `php-generic`, os paths são descobertos apenas entre diretórios ou arquivos PHP existentes; não há estrutura de framework presumida.

Configurações e hooks são gerados em workspace externo; o CLI não exige boilerplate Ninfa dentro do consumidor.
