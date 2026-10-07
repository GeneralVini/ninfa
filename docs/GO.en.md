[Português](GO.md) | [English](GO.en.md)

# Go in Ninfa

## Status

Go was introduced in a controlled way as an experimental foundation for a future Ninfa **control plane**. The public CLI remains `bin/ninfa`, written in PHP, and the current `ProjectContext`, profiles, pipeline, `Finding`, `ToolResult`, `RunResult`, SCA and SAST contracts remain authoritative in the PHP implementation.

This stage is not a PHP → Go migration and does not introduce a microservice, HTTP API, gRPC, queue, or distributed runtime.

## Architectural boundary

The initial ownership rule is:

```text
PHP
├── profile detection and PHP/Yii/GLPI semantics
├── PHPStan / Psalm / Rector / ECS
├── existing SAST and SCA contracts
├── Finding / ToolResult / RunResult
└── public CLI bin/ninfa

Go
├── future control-plane bootstrap
├── Go environment diagnostics
├── future multi-stack orchestration
├── future controlled concurrent execution
└── future reporting/interoperability when there is a demonstrated benefit
```

Go should not reimplement parsers, type systems, or rules that belong to PHPStan/Psalm/Rector. It should also not duplicate the existing PHP models merely to create a premature cross-language abstraction.

## Structure

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

`ninfa-go` deliberately uses a separate name until the Go implementation has enough parity and validation to take over `bin/ninfa`.

The `go/devtools` module is separate from the module that builds the binary. Staticcheck, gosec, and govulncheck dependencies do not enter the runtime dependency graph of `go/go.mod`. There is no `go.work`: this separation prevents tooling minimal version selection from changing dependency selection for the control plane.

## Reproducible Go tooling

Development tools are declared with Go's official `tool` mechanism and installed into `.tools/go/bin` through `go install tool`.

Approved versions at this stage:

```text
staticcheck   v0.8.1   (Staticcheck 2026.2.1 release)
gosec         v2.29.0
govulncheck   v1.1.4
```

Do not use `@latest` in reproducible CI. Dependabot tracks the runtime module, devtools module, and GitHub Actions separately.

The scanner binary version is reproducible; the vulnerability database queried by govulncheck continues to evolve. Pinning `govulncheck` does not freeze vulnerability data.

## Development usage

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

The experimental binary is generated at:

```text
build/ninfa-go
```

The local gosec SARIF is generated at:

```text
build/security/gosec.sarif
```

`build/` is generated output and remains outside Git.

Bootstrap usage:

```bash
./build/ninfa-go version
./build/ninfa-go doctor
```

`doctor` is read-only. PHP, Git, Go, and Make are bootstrap requirements; other tools are reported as optional capabilities. The primary environment diagnostic and distribution-specific hints still belong to `scripts/check-environment.sh`.

## Gate model

Controls remain separate:

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

`staticcheck` is not described as a security scanner. `govulncheck` is not traditional SAST. None of these controls replaces another.

`go-check` is the consolidated local gate for Go code. `go-security` runs gosec and the normal govulncheck scan. The regular `govulncheck ./...` scan is the vulnerability gate; alternate report formats do not replace that status.

Govulncheck reachability is based on static analysis. A reachable finding requires review but does not prove exploitability; absence of findings does not prove absence of risk either.

## gosec policy

`gosec ./...` is the Go SAST gate. Findings should be investigated and fixed at the root cause when reasonable.

Suppressions are exceptional. Any suppression must identify the rule and provide a specific technical justification. Do not add global suppressions simply to make CI green, and do not suppress command injection, subprocess handling, SSRF, file access, or URL handling without concrete risk analysis.

`make go-security-report` generates SARIF while preserving the gosec exit code. In GitHub Actions, the step captures the outcome; the artifact is uploaded with `if: always()`, and a final step restores gate failure. A finding can therefore produce both a preserved SARIF artifact and a failed job.

## Subprocess security

Ninfa Go code should:

- use `exec.CommandContext` with executable and arguments separated;
- avoid shells and never concatenate consumer input into `sh -c`;
- apply timeout/cancellation to potentially long-running operations;
- distinguish missing tools, execution failures, and findings;
- never convert scanner failure into success.

The `doctor` probes use fixed commands, no shell, and a per-tool timeout.

## Version and build

The runtime module declares Go 1.27 and the workflow pins Go 1.27.1. The build uses `-trimpath` and disables automatic VCS stamping. Version and commit can be injected through `-ldflags`; timestamps are not embedded by default.

## CI and periodic scans

`.github/workflows/go.yml` runs on push, pull request, and weekly. The weekly schedule exists because new vulnerabilities may emerge even without source-code changes.

The workflow uses minimum permissions (`contents: read`). Third-party actions are pinned by immutable SHA, with the readable version kept in a comment. The gosec SARIF artifact is retained for 14 days even when the scanner fails the gate.

The existence of the YAML means **CI is configured**; only real workflow executions justify saying **CI is validated**.

## Next steps

1. keep `go/devtools/go.mod` and `go.sum` consistent and versioned;
2. calibrate real staticcheck, gosec, and govulncheck findings without generic suppressions;
3. model a small orchestration bridge without prematurely duplicating `Finding`/`ToolResult`;
4. migrate an operational responsibility only when there is a measurable advantage;
5. evaluate unified Ninfa SARIF after PHP ↔ Go contracts are stable;
6. evaluate bounded concurrency and cancellation only when there are independent analyzers;
7. only then evaluate whether the Go binary can take over the public `ninfa` name/entrypoint.

Any change to this boundary should update this document and `docs/INTERNAL-ARCHITECTURE.md` through a controlled merge.
