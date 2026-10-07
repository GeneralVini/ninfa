[Português](GO.md) | [English](GO.en.md)

# Go no Ninfa

## Estado

Go foi introduzido de forma controlada como fundação experimental para um futuro **control plane** do Ninfa. O CLI público continua sendo `bin/ninfa`, em PHP, e os contratos atuais de `ProjectContext`, profiles, pipeline, `Finding`, `ToolResult`, `RunResult`, SCA e SAST permanecem autoritativos no código PHP.

Esta etapa não é uma migração PHP → Go e não cria microserviço, API HTTP, gRPC, fila ou runtime distribuído.

## Fronteira arquitetural

A regra de ownership inicial é:

```text
PHP
├── detecção de profile e semântica PHP/Yii/GLPI
├── PHPStan / Psalm / Rector / ECS
├── contratos SAST e SCA existentes
├── Finding / ToolResult / RunResult
└── CLI público bin/ninfa

Go
├── bootstrap do futuro control plane
├── diagnóstico do ambiente Go
├── futura orchestration multi-stack
├── futura execução concorrente controlada
└── futuro reporting/interoperabilidade quando houver ganho demonstrável
```

Go não deve reimplementar parser, sistema de tipos ou regras que pertencem a PHPStan/Psalm/Rector. Também não deve duplicar os modelos PHP existentes apenas para criar uma abstração multilíngue prematura.

## Estrutura

```text
go/
├── go.mod
├── cmd/
│   └── ninfa-go/
├── internal/
│   ├── doctor/
│   └── version/
└── devtools/
    ├── go.mod
    └── go.sum
```

`ninfa-go` é deliberadamente um nome separado enquanto a implementação em Go não possuir paridade e validação suficientes para assumir `bin/ninfa`.

O módulo `go/devtools` é separado do módulo que compila o binário. Dependências de staticcheck, gosec e govulncheck não participam do grafo runtime de `go/go.mod`. Não há `go.work`: a separação evita que minimal version selection do tooling altere a seleção de dependências do control plane.

## Tooling Go reproduzível

As ferramentas de desenvolvimento são declaradas com o mecanismo oficial `tool` do Go e instaladas em `.tools/go/bin` por `go install tool`.

Versões homologadas nesta etapa:

```text
staticcheck   v0.8.1   (release Staticcheck 2026.2.1)
gosec         v2.29.0
govulncheck   v1.1.4
```

Não usar `@latest` no CI reproduzível. Dependabot acompanha separadamente o módulo runtime, o módulo de devtools e GitHub Actions.

A versão do binário do scanner é reproduzível; a base de vulnerabilidades consultada por govulncheck continua evoluindo. Fixar `govulncheck` não congela os dados de vulnerabilidade.

## Uso de desenvolvimento

```bash
make go-tools
make go-fmt-check
make go-vet
make go-lint
make go-sast
make go-vuln
make go-test
make go-build
make go-security
make go-security-report
make go-check
```

O binário experimental é gerado em:

```text
build/ninfa-go
```

O SARIF local do gosec é gerado em:

```text
build/security/gosec.sarif
```

`build/` é artefato gerado e permanece fora do Git.

Uso do bootstrap:

```bash
./build/ninfa-go version
./build/ninfa-go doctor
```

`doctor` é somente leitura. PHP, Git, Go e Make são requisitos do bootstrap; outras ferramentas são apresentadas como capacidades opcionais. O diagnóstico principal e os hints específicos de distribuição ainda pertencem a `scripts/check-environment.sh`.

## Modelo dos gates

Os controles permanecem separados:

```text
FORMAT
    gofmt

CORRECTNESS / QUALITY
    go vet
    staticcheck

SAST
    gosec

KNOWN VULNERABILITIES
    govulncheck

BEHAVIOR
    go test

BUILD
    go build
```

`staticcheck` não é descrito como scanner de segurança. `govulncheck` não é SAST tradicional. Nenhum desses controles substitui outro.

`go-check` é o gate local consolidado do código Go. `go-security` executa gosec e o scan normal de govulncheck. O scan normal `govulncheck ./...` é o gate de vulnerabilidades; formatos alternativos de relatório não substituem esse status.

Reachability do govulncheck é baseada em análise estática. Um finding alcançável exige revisão, mas não prova explorabilidade; ausência de findings também não prova ausência de risco.

## Política gosec

`gosec ./...` é o gate SAST Go. Findings devem ser investigados e corrigidos na causa quando razoável.

Suppressions são excepcionais. Qualquer suppression deve indicar a regra e uma justificativa técnica específica. Não introduzir suppressions globais para obter CI verde e não suprimir command injection, subprocess handling, SSRF, file access ou URL handling sem análise concreta do risco.

`make go-security-report` gera SARIF e preserva o exit code do gosec. No GitHub Actions, o passo é executado com captura de outcome; o artifact é enviado com `if: always()` e um passo final restaura a falha do gate. Portanto, um finding pode produzir simultaneamente SARIF preservado e job reprovado.

## Segurança de subprocessos

Código Go do Ninfa deve:

- usar `exec.CommandContext` com executável e argumentos separados;
- evitar shell e nunca concatenar entrada do consumidor em `sh -c`;
- aplicar timeout/cancelamento a operações potencialmente longas;
- distinguir ausência de ferramenta, falha de execução e finding;
- não converter falha de scanner em sucesso.

Os probes de `doctor` usam comandos fixos, sem shell, e timeout por ferramenta.

## Versão e build

O módulo runtime declara Go 1.27 e o workflow fixa Go 1.27.1. O build usa `-trimpath` e desabilita VCS stamping automático. Versão e commit podem ser injetados por `-ldflags`; timestamp não é incorporado por padrão.

## CI e scans periódicos

`.github/workflows/go.yml` roda em push, pull request e semanalmente. O schedule semanal existe porque novas vulnerabilidades podem surgir sem mudança de código.

O workflow usa permissões mínimas (`contents: read`). Actions de terceiros são fixadas por SHA imutável, com a versão legível em comentário. O SARIF do gosec é preservado como artifact por 14 dias mesmo quando o scanner reprova o gate.

A existência do YAML significa **CI configurado**; somente execuções reais permitem declarar **CI validado**.

## Próximas etapas

1. manter `go/devtools/go.mod` e `go.sum` consistentes e versionados;
2. calibrar findings reais de staticcheck, gosec e govulncheck sem suppressions genéricas;
3. modelar uma ponte pequena para orchestration sem duplicar `Finding`/`ToolResult` prematuramente;
4. migrar uma responsabilidade operacional apenas quando houver vantagem mensurável;
5. avaliar um SARIF unificado do Ninfa depois que os contratos PHP ↔ Go estiverem estáveis;
6. avaliar concorrência com limites e cancelamento somente quando houver analyzers independentes;
7. somente então avaliar se o binário Go pode assumir o nome/entrypoint público `ninfa`.

Qualquer mudança dessa fronteira deve atualizar este documento e `docs/INTERNAL-ARCHITECTURE.md` por merge controlado.
