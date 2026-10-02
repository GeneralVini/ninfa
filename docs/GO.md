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

## Estrutura inicial

```text
go/
├── go.mod
├── cmd/
│   └── ninfa-go/
│       ├── main.go
│       └── main_test.go
└── internal/
    ├── doctor/
    │   ├── doctor.go
    │   └── doctor_test.go
    └── version/
        ├── version.go
        └── version_test.go
```

`ninfa-go` é deliberadamente um nome separado enquanto a implementação em Go não possuir paridade e validação suficientes para assumir `bin/ninfa`.

## Uso de desenvolvimento

```bash
make go-fmt-check
make go-vet
make go-test
make go-build
make go-check
```

O binário experimental é gerado em:

```text
build/ninfa-go
```

Uso:

```bash
./build/ninfa-go version
./build/ninfa-go doctor
```

`doctor` é somente leitura. Ele executa probes de versão com executável e argumentos explícitos, sem shell. PHP, Git, Go e Make são requisitos do bootstrap; Composer, Semgrep, staticcheck, gosec e govulncheck são apresentados como capacidades opcionais nesta primeira etapa.

O diagnóstico principal e os hints específicos de distribuição ainda pertencem a `scripts/check-environment.sh`. A implementação Go não deve copiar essa política operacional sem uma decisão posterior de migração.

## Versão Go

O módulo declara Go 1.27 e o workflow fixa o toolchain CI em Go 1.27.1. A escolha acompanha a release estável adotada na introdução do módulo.

O build usa `-trimpath` e desabilita VCS stamping automático. Versão e commit podem ser injetados por `-ldflags`; timestamp não é incorporado por padrão para não prejudicar reprodutibilidade.

## Segurança de subprocessos

Código Go do Ninfa deve seguir estas regras:

- usar `exec.CommandContext` com executável e argumentos separados;
- não usar `sh -c` para dados vindos de configuração ou do projeto consumidor;
- aplicar timeout/cancelamento a operações potencialmente longas;
- distinguir ausência de ferramenta, falha de execução e finding quando a orchestration for implementada;
- não converter falha de scanner em sucesso apenas para deixar o CI verde.

O `doctor` atual já segue a primeira fronteira: probes fixos, sem concatenação em shell e com timeout por ferramenta.

## Gates iniciais

Nesta primeira entrega, o código Go é validado por:

```text
gofmt
  ↓
go vet ./...
  ↓
go test ./...
  ↓
go build
```

`staticcheck`, `gosec` e `govulncheck` são o próximo gate. Eles deverão usar versões fixadas e mecanismo de tooling isolado, evitando contaminar dependências do binário. Não usar `@latest` no CI reproduzível.

## CI

`.github/workflows/go.yml` executa `make go-check` em push e pull request com permissões mínimas (`contents: read`). Actions de terceiros são fixadas por SHA imutável, com o release correspondente registrado em comentário.

A existência do workflow significa **CI configurado**. Somente uma execução real do GitHub Actions permite declarar **CI validado**.

## Próximas etapas

A evolução deve ocorrer nesta ordem:

1. validar a fundação Go em CI e em desenvolvimento local;
2. adicionar tooling isolado para staticcheck, gosec e govulncheck;
3. modelar uma ponte pequena para orchestration sem duplicar `Finding`/`ToolResult` prematuramente;
4. migrar uma responsabilidade operacional apenas quando houver vantagem mensurável;
5. avaliar reporting/SARIF e concorrência depois que os contratos entre PHP e Go estiverem estáveis;
6. somente então avaliar se o binário Go pode assumir o nome/entrypoint público `ninfa`.

Qualquer mudança dessa fronteira deve atualizar este documento e `docs/INTERNAL-ARCHITECTURE.md` por merge controlado.
