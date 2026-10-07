[Português](README.md) | [English](README.en.md)

# Ninfa

**Ninfa** is an external quality and security pipeline for PHP projects. The current state is an **experimental MVP / 0.1.0-alpha**, intended for controlled testing on real projects.

## Active profiles

The MVP currently has four official PHP profiles:

- **Yii2** — detected through `yiisoft/yii2`, with its own SAST overlay, native semantic analysis and selective SAFE remediation;
- **Yii3** — detected through consistent application/runner and Yii infrastructure signals;
- **GLPI Plugin 11** — the `glpi-plugin` profile, with GLPI 11 host context and PHPStan/Psalm at level 8;
- **Generic PHP** — the `php-generic` profile, for PHP applications, libraries and CLIs without a recognized framework.

Specialized profiles take precedence over `php-generic`. A directory without actual evidence of PHP code is not accepted as a generic PHP project.

The **Yii 22** line is tracked as an evolution of the Yii2 family and does not receive a separate profile unless concrete technical differences require distinct rules/capabilities. **Laravel** is the next PHP profile candidate on the roadmap. **Python** remains a future ecosystem.

## Quick start

While the MVP is on the `feature/glpi-plugin-profile` branch, clone Ninfa outside the project being analyzed:

```bash
git clone --branch feature/glpi-plugin-profile --single-branch \
  https://github.com/GeneralVini/ninfa.git /opt/ninfa
```

Add the CLI to your `PATH`:

```bash
export PATH="/opt/ninfa/bin:$PATH"
```

Then run:

```bash
ninfa check /path/to/project
ninfa fix /path/to/project
ninfa security /path/to/project
ninfa assist /path/to/project
```

When `root` is omitted, Ninfa uses the current directory. There is no mandatory installation or preparation step inside the consumer project.

## Profiles

### Yii2

```bash
ninfa check /path/to/yii2-app
```

The context supports both simple and Advanced Yii2 layouts, including `common`, `frontend`, `backend` and `console` when present. The security profile combines the common PHP baseline with Request/Response, DB/Command, HTML, redirects, headers and filesystem semantics.

In addition to the SAST overlay, `ninfa assist` includes a native semantic-analysis layer currently under field testing. The current catalog covers:

```text
COR-001   non-existent literal view
COR-002   non-existent action in static filters/behaviors
COR-003   non-existent literal relation path in with/joinWith/innerJoinWith
COR-004   non-existent attribute in literal hasOne/hasMany link
COR-005   invalid arity in a static query condition array
PERF-001  one()/count() used only to test existence
MOD-001   find()->where(hash)->one/all where findOne/findAll is equivalent
DEP-001   deprecated Yii::trace()
DEP-002   legacy exit-code constant
DEP-003   return 0/1 in console action
TYPE-001  relation without @property* in a class that already maintains that PHPDoc contract
ARCH-001  Yii::$app->request/response inside a proven controller context
```

The taxonomy is intentional: `COR-*` means correctness; `PERF-*` performance; `MOD-*` modernization; `DEP-*` deprecation; `TYPE-*` static-analysis/PHPDoc; and `ARCH-*` architecture advisory. These families are not automatically vulnerabilities.

Dynamic references, unknown external inheritance, runtime schemas and types that cannot be proven remain `unknown` instead of generating false positives.

`ninfa fix` includes native SAFE remediation for Yii2 before external fixers. It currently covers `DEP-001..003` and `MOD-001`. Patches are applied using exact offsets, overlaps are rejected, and the operation must be idempotent. The normal flow then performs **exactly one final `check`**.

`PERF-001`, `TYPE-001` and `ARCH-001` remain REVIEW/SEMANTIC findings without autofix. In particular, magic-property PHPDoc is not treated as cosmetic text and is not rewritten automatically.

The technical contract, limitations and examples for each rule are documented in [Yii2 semantic analysis](docs/YII2-ANALYSIS.md).

Yii 22 remains inside this family. Ninfa does not assume Yii2 requires Repository, DTO, DDD, Clean Architecture or Vertical Slice; architecture belongs to the consumer project.

### Yii3

```bash
ninfa check /path/to/yii3-app
```

A single `yiisoft/*` dependency is not enough to classify a project as Yii3. The SAST overlay considers Yii DB, PSR-7/HTTP and HTML-output APIs and conventions without imposing an architectural style.

### GLPI Plugin 11

When the plugin lives under `<glpi>/plugins/<plugin>`, the host can be discovered automatically. Outside that tree:

```bash
NINFA_GLPI_ROOT=/opt/glpi \
ninfa check /path/to/myplugin
```

Only GLPI 11 is accepted, and the host version must be identifiable. The SAST overlay incorporates database, redirect, URL and filesystem APIs specific to the GLPI host/plugin environment.

### Generic PHP

The `php-generic` profile is used only when no specialized profile matches and there is actual evidence of PHP code.

```bash
ninfa check /path/to/php-library
ninfa check /path/to/php-cli
ninfa check /path/to/simple-php-app
```

Ninfa detects only existing paths and does not require `src/`, `public/` or `tests/` to exist.

## Public commands

```bash
ninfa check [root]
ninfa fix [root]
ninfa security [root]
ninfa assist [root]
```

`check` runs quality checks, static analysis and available tests. PHPStan and Psalm findings are rendered by Ninfa with file, line, rule and problem details.

`fix` applies approved automatic fixes and performs **exactly one `check`** at the end. For Yii2, native SAFE remediations run before ECS/Rector and participate in the same final validation.

`security` remains separate from the regular quality pipeline. `assist` adds remediation guidance and stores structured audit data in the external workspace without changing the consumer project.

## External architecture

```text
/opt/ninfa/                   Ninfa source code
/path/to/project/             consumer project being analyzed
/tmp/ninfa/<project-hash>/    generated external workspace
```

The workspace is disposable and may be relocated through `NINFA_WORKSPACE_ROOT`, but it cannot live inside the consumer project.

Generated artifacts may include, depending on profile and operation:

```text
phpstan.neon
psalm.xml
rector.php
ecs.php
lefthook.yml
semantic-index.json
security-inventory.json
security-report.json
glpi-bootstrap.php
assist/yii2-semantic.json
assist/yii2-findings.json
```

The public workflow does not modify `composer.json` or copy persistent boilerplate/configuration into the consumer project.

## Quality pipeline

Depending on context, `check` includes:

```text
ECS
Rector --dry-run
PHPStan
Psalm
ESLint           # when applicable
Prettier --check # when applicable
PHPUnit          # when available
```

`fix` runs only approved fixes and validates the project once at the end.

## Auditable assistance

```bash
ninfa assist /path/to/project
```

The command does not modify the project. PHPStan/Psalm are executed in structured mode; for Yii2, `Yii2RuleEngine` adds native findings and preserves `yii2-semantic.json` and `yii2-findings.json`.

The policy is conservative: a reference becomes a correctness error only when the absence or violation can be demonstrated. Performance, modernization, deprecation, PHPDoc and architecture findings retain their own categories and risk levels.

## Security

`security` remains separate from the normal quality pipeline:

```text
SCA   Composer Audit + OSV
SAST  Psalm Taint + Semgrep
```

DAST is not part of Ninfa's public pipeline.

The current SAST specialization is:

```text
PHP common
├── php-generic        # baseline only
├── yii2               # baseline + Yii2 overlay
├── yii3               # baseline + Yii3 overlay
└── glpi-plugin        # baseline + GLPI Plugin 11 overlay
```

The twelve canonical contracts remain framework-independent: command injection, SQL injection, XSS, path traversal, file access, SSRF, unsafe redirect, header injection, dynamic include/require, unsafe deserialization, dangerous eval/assert and cryptographic misuse.

### Semgrep

Rules:

```text
security/semgrep/
├── common.yml
└── profiles/
    ├── yii2.yml
    ├── yii3.yml
    └── glpi-plugin-11.yml
```

Parallel fixtures:

```text
security/semgrep-tests/
├── common.php
└── profiles/
    ├── yii2.php
    ├── yii3.php
    └── glpi-plugin-11.php
```

Validation:

```bash
make semgrep-rules
```

Gate policy:

```text
ERROR    blocking
WARNING  review hotspot; does not block by itself
```

Engine errors or unexpected partial coverage remain blocking. Deliberate exclusions are recorded separately.

### SCA / Composer Audit + OSV

Composer Audit runs when `composer.lock` exists, in a structured and defensive mode:

```text
composer --no-plugins --no-scripts --no-interaction audit --locked --format=json
```

OSV uses the resolved inventory. CVE/GHSA/PKSA/OSV aliases are correlated while preserving provenance.

`ninfa security` writes:

```text
security-inventory.json
security-report.json
```

Network failure or invalid output is treated as an execution error, not as evidence that vulnerabilities are absent.

### DAST

DAST is disabled in the Ninfa pipeline. `scripts/zap-scan.sh` remains a frozen artifact for reference and controlled manual use.

### Interpretation

Exit code 0 from `ninfa security` means that the consulted sources produced no blocking result and there was no unexpected coverage loss within the observed scope. It does **not** mean "the system is secure."

## Developing Ninfa itself

```bash
make environment-check
make security-tools
make semgrep-rules
make syntax
make profile-test
make setup
```

`make profile-test` includes general contracts and Yii2 fixtures for correctness, query performance/conditions, deprecations, SAFE modernization, magic-property PHPDoc and architecture advisories.

## Development status

**Current stage: 4 — SAST contracts and profiles.**

### Stage 1 — Foundation

- [x] Consolidate `Finding`, `ToolResult` and `RunResult` as structured, serializable contracts.
- [x] Generate an external `SecurityInventory` with PHP runtime, constraint, extensions and Composer inventory.

### Stage 2 — Structured SCA

- [x] Run Composer Audit in defensive/JSON mode and normalize advisories as SCA `Finding` objects.
- [x] Integrate OSV batch queries from the resolved inventory.
- [x] Deduplicate CVE/GHSA/PKSA/OSV aliases into canonical vulnerabilities.
- [x] Generate a consolidated `security-report.json`.

### Stage 3 — Structured SAST

- [x] Normalize Psalm Taint and Semgrep as SAST `Finding` objects.
- [x] Distinguish findings, errors, unavailability, non-applicability and partial coverage.
- [x] Record observable coverage.
- [x] Preserve rule, severity, confidence, location, evidence and provenance.
- [x] Validate/test Semgrep rules with positive/negative fixtures.

### Stage 4 — SAST contracts and profiles

- [x] Formalize the 12 common SAST contracts.
- [x] Implement the common PHP baseline.
- [x] Implement the Yii2 overlay.
- [x] Implement the Yii3 overlay.
- [x] Implement GLPI Plugin 11 specializations.
- [x] Keep `php-generic` free of invented framework semantics.
- [x] Treat Semgrep `WARNING` as a hotspot and `ERROR` as blocking.

### Roadmap

- [ ] Calibrate/promote selected native Yii2 rules after real field tests.
- [ ] Track Yii 22 inside the Yii2 family and specialize only when there is a concrete difference.
- [ ] Evaluate Laravel as the next PHP profile.
- [ ] Evaluate Python as a future ecosystem (`python-generic` before Django/Flask).
- [ ] Evaluate NVD/exploit evidence as later enrichment.
- [ ] Evolve exposure/reachability and gates only with demonstrable evidence.

## Documentation

English:

- [Security architecture](docs/SECURITY-ARCHITECTURE.en.md)
- [Go in Ninfa](docs/GO.en.md)

Portuguese:

- [CLI](docs/CLI-DESIGN.md)
- [Installation](docs/INSTALACAO.md)
- [Integration](docs/INTEGRACAO.md)
- [Commands](docs/COMANDOS.md)
- [Yii2 semantic analysis](docs/YII2-ANALYSIS.md)
- [GLPI profile](docs/GLPI_PLUGIN.md)
- [Customization](docs/CUSTOMIZACAO.md)
- [Colors](docs/CORES.md)
- [Security](docs/SEGURANCA.md)
- [Security architecture](docs/SECURITY-ARCHITECTURE.md)
- [Internal architecture and code documentation](docs/INTERNAL-ARCHITECTURE.md)
