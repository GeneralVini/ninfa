# Ninfa CLI

O Ninfa recebe opcionalmente a raiz do projeto e detecta quatro profiles PHP oficiais: `glpi-plugin`, `yii3`, `yii2` e `php-generic`.

Profiles especializados têm precedência sobre `php-generic`. O profile genérico exige evidência real de código PHP e também suporta projetos simples sem `composer.json`. Yii 22 é acompanhado como evolução da família Yii2, sem profile separado enquanto não houver necessidade técnica concreta. Laravel permanece como futuro profile PHP; Python permanece futuro ecossistema e não participa do detector atual.

## API pública

```bash
ninfa check [root]
ninfa fix [root]
ninfa security [root]
ninfa assist [root]
```

Quando `root` é omitido, o diretório atual é usado.

Também está disponível:

```bash
ninfa --help
```

Não existem comandos públicos separados para PHPStan, Psalm, Rector, ECS ou Semgrep. Essas ferramentas são detalhes internos dos pipelines.

`check` executa qualidade e testes. Os findings de PHPStan e Psalm são capturados em formato estruturado e apresentados por um renderer único do Ninfa, com arquivo, linha, regra e problema, em vez de expor a tabela nativa de cada ferramenta.

`security` permanece separado e executa SCA + SAST. Composer Audit, OSV, Psalm Taint e Semgrep entregam resultados estruturados. No Semgrep, `ERROR` é bloqueante; `WARNING` é hotspot para revisão e não reprova sozinho o gate. Erro do mecanismo ou cobertura parcial inesperada continua bloqueante.

O resumo de segurança expõe, quando disponível:

```text
profile
fonte/configuração do scanner
arquivos analisados
exclusões deliberadas por política
skips inesperados
erros do mecanismo
findings bloqueantes
hotspots
```

A apresentação reutiliza exclusivamente `CliStyle`. `NINFA_COLOR=auto` permanece o padrão global; `always`, `never` e `NO_COLOR` continuam com o comportamento documentado em [CORES.md](CORES.md). Não existe configuração visual específica para segurança.

`fix` aplica os hooks corrigíveis e executa um único `check` ao final.

`assist` é separado do `fix`: reutiliza o mesmo renderer do `check`, acrescenta orientação de correção, grava o resultado bruto e `findings.json` no workspace externo e não altera o consumidor. Essa separação permite auditoria e evita que correções semânticas sejam aplicadas silenciosamente.

Em projetos com JavaScript/TypeScript aplicável, ESLint e Prettier entram no mesmo pipeline e respeitam a ordem definida pelo plano.

No profile `glpi-plugin`, PHPStan e Psalm usam nível 8 e o host precisa ser GLPI 11 com versão identificável.

No profile `yii2`, o Ninfa reconhece a família por `yiisoft/yii2` e aplica baseline PHP mais overlay de segurança Yii2. A linha Yii 22 não cria outro identificador de profile por enquanto.

No profile `yii3`, a detecção exige combinação de marcador de aplicação/runner e infraestrutura Yii3; uma dependência `yiisoft/*` isolada não basta.

No profile `php-generic`, os paths são descobertos apenas entre diretórios ou arquivos PHP existentes; não há estrutura de framework presumida.

Configurações, hooks e auditorias são gerados em workspace externo; o CLI não exige boilerplate Ninfa dentro do consumidor.
