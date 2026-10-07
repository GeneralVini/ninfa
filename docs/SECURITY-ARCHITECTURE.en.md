[Português](SECURITY-ARCHITECTURE.md) | [English](SECURITY-ARCHITECTURE.en.md)

# Security Architecture

This document records the architectural direction of `ninfa security`, what is currently implemented, and the boundaries for future evolution. The README is the quick overview; this file preserves contracts, responsibilities, and expansion criteria.

## Current scope

The public security pipeline is currently limited to:

```text
SCA   Composer Audit + OSV
SAST  Psalm Taint + Semgrep
```

DAST is outside the Ninfa pipeline and is handled by a separate institutional track.

The active ecosystem is PHP:

```text
PHP common
├── php-generic
├── yii2
├── yii3
└── glpi-plugin
```

`php-generic` uses only the common baseline. Yii2, Yii3, and GLPI Plugin 11 add profile-specific security overlays. Yii 22 remains inside the Yii2 family and does not receive a separate profile at this stage.

Laravel remains on the PHP roadmap. Python (`python-generic`, Django, Flask) remains a future ecosystem. These roadmap items must not trigger premature cross-language interfaces or abstractions before real implementation exists.

## Architecture status

The foundation and structured SCA remain:

```text
SecurityInventory
      ↓
Composer Audit ─┐
                ├─ Finding SCA
OSV querybatch ─┘
      ↓
alias/canonical dedup
      ↓
SecurityReport
      ↓
security-report.json
```

SAST follows:

```text
ProjectContext
      ↓
SecurityContract::forProfile()
      ↓
common + profile overlay
      ↓
Psalm Taint / Semgrep
      ↓
Finding[] + coverage
      ↓
ToolResult / SecurityReport
```

## SAST architectural principle

Ninfa separates three responsibilities:

```text
SAST Contract
  defines WHAT characterizes the vulnerability class

Profile SecurityContract
  defines WHAT the profile APIs/conventions mean

Tool Adapter
  defines HOW the tool executes and returns analysis
```

The detector answers "what project is this?". The contract answers "which sources, sinks, sanitizers, and primitives exist in this profile?". The adapter must not be the primary source of framework semantics.

This architecture does not turn Ninfa into an architectural-style auditor. DTO, Repository, DDD, Clean Architecture, and Vertical Slice are consumer-project decisions and should only produce rules when there is an objectively demonstrable technical risk.

## SAST contracts

The twelve canonical contracts are:

```text
command-injection
sql-injection
xss
path-traversal
file-access
ssrf
unsafe-redirect
header-injection
dynamic-include-require
unsafe-deserialization
dangerous-eval-assert
cryptographic-misuse
```

Each common contract may define sources, sinks, sanitizers, dangerous primitives, safe patterns, evidence types, and provenance. Overlays add only semantics that genuinely belong to the framework.

Example:

```text
sql-injection common
        +
yii2 overlay
        ↓
yii\web\Request source
yii\db\Connection::createCommand sink
bindValue/bindValues as known safe operations
```

The same contract in Yii3 or GLPI keeps the same risk class while using framework-specific APIs.

## SecurityContract by profile

### php-generic

Receives only the common contracts. No framework, ORM, template engine, or architecture is assumed.

### yii2

Adds capabilities:

```text
web-request
database
view-html
http-client
redirect-response
filesystem
console
```

The overlay represents Request/Response, DB/Command, HTML encoding, redirects, headers, and filesystem semantics. Yii 22 remains in this profile; generational differences should be modeled only when a concrete rule requires them.

### yii3

Adds similar capabilities with Yii3/PSR-specific semantics, including Yii DB, PSR-7, HTML helpers, and immutable responses.

Do not model CSRF as a text search for `_csrf`. Yii3 may enforce protection through middleware/infrastructure; only reliable static bypass/configuration evidence should become a rule.

### glpi-plugin

Adds `host-api` in addition to the usual web capabilities. The contract understands APIs such as `$DB`, `Html::redirect`, and `Toolbox`. The GLPI host provides context to PHPStan/Psalm but must not become an indiscriminate SAST target for the plugin.

## Semgrep rule structure

The implemented structure is:

```text
security/
├── semgrep/
│   ├── common.yml
│   └── profiles/
│       ├── yii2.yml
│       ├── yii3.yml
│       └── glpi-plugin-11.yml
└── semgrep-tests/
    ├── common.php
    └── profiles/
        ├── yii2.php
        ├── yii3.php
        └── glpi-plugin-11.php
```

Configuration and test trees remain parallel so that `semgrep --test` can associate rules and fixtures using the same basename/relative path.

Do not create one file per contract merely to mirror a conceptual tree. Physical splitting should increase only when volume or maintenance justifies it.

## Rule testing

`make semgrep-rules` runs:

```text
semgrep --validate --config security/semgrep
semgrep --test --config security/semgrep security/semgrep-tests
```

Parsing validation happens before tests. Each material rule should keep a relevant positive case and, when a safe equivalent exists, a negative case.

Fixtures act as false-positive/false-negative regressions. For example, local bootstrap code derived from `dirname(__DIR__)` must remain safe under include/require rules because it does not derive from external input.

## Role of SAST engines

### Psalm Taint

Primary PHP dataflow engine when the problem depends on propagation from source to sink. Valid taint findings are blocking.

### Semgrep

Complements Psalm with:

- profile-aware local taint;
- dangerous primitives;
- misuse/configuration;
- framework-specific APIs.

When a vulnerability depends on flow, prefer `mode: taint` over rules that effectively treat "any variable" as vulnerable.

## Severity, confidence, and gate

SAST evidence distinguishes at least:

```text
DATAFLOW
DANGEROUS_PRIMITIVE
MISUSE
```

`severity` and `confidence` remain separate concepts.

For Semgrep:

```text
ERROR    blocks the gate
WARNING  review hotspot; does not block by itself
```

The runner does not use `semgrep --error` to decide policy. Semgrep returns JSON and Ninfa applies policy to normalized findings. This keeps WARNING findings visible without artificially converting them into execution failures.

Engine errors or unexpected partial coverage are operational failures and block independently of finding severity.

## Targeting and coverage

The runner sends Semgrep only the paths detected by `ProjectContext` and uses `--no-git-ignore` so newly created, untracked files are still analyzed.

Deliberate exclusions:

```text
**/vendor/**
**/runtime/**
**/public/assets/**
**/web/assets/**
```

`SemgrepParser` normalizes:

```text
scanned
excluded_by_policy
unexpected_skips
errors
```

Coverage is `complete` when there are no `unexpected_skips` or `errors`. Deliberately excluded files remain auditable but must not be confused with scanner failure.

## Human output and structured artifact

Human-facing output uses `CliStyle` and follows one global policy:

```text
NINFA_COLOR=auto   default
NINFA_COLOR=always
NINFA_COLOR=never
NO_COLOR           absolute precedence
```

The terminal may summarize coverage, blockers, and hotspots, but `security-report.json` remains the structured representation intended for automation and audit.

## Implemented SCA

### SecurityInventory

Version precedence remains:

```text
composer.lock
  ↓ fallback when lock does not exist
vendor/composer/installed.json
```

The inventory records dependencies, PHP runtime, declared constraint, extensions, profile, paths, and GLPI context when applicable.

### Composer Audit

Runs:

```text
composer --no-plugins --no-scripts --no-interaction audit --locked --format=json
```

Advisories are SCA findings; abandoned packages are dependency-policy findings.

### OSV

OSV is the second SCA source over resolved Composer packages. Deduplication preserves aliases and provenance instead of counting the same vulnerability twice.

## SCA versus SAST

Do not combine SCA and SAST through weak inference. A generic Semgrep finding does not prove reachability of a dependency CVE.

Future exposure states should allow something like:

```text
confirmed
observed
not_observed
unknown
```

Absence of observed use does not mean absence of vulnerability.

## Framework evolution

Product order:

```text
now
  stabilize php-generic/yii2/yii3/glpi-plugin

track
  Yii 22 inside yii2

future PHP
  Laravel

future multi-language
  Python
    python-generic
    Django
    Flask
```

Laravel should reuse the existing PHP ecosystem when its turn comes, preferring capabilities/APIs over profiles per major version. Python will require a different toolchain and therefore should not be anticipated through generic interfaces today.

## Evolution criteria

A new rule/profile should advance only when there is:

```text
valid rule
positive case
negative case when applicable
low observed false-positive rate
auditable coverage
normalized result
no mutation of the consumer project
```

Rule count is not a quality metric. Ten valid and tested rules are more valuable than dozens of noisy patterns.
