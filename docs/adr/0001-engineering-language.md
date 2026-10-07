# ADR-0001: Adopt English as the Canonical Engineering Language

Status: **Accepted**

Date: 2026-10-07

## Context

Ninfa combines PHP, Go, PHPStan, Psalm, Rector, Semgrep, GitHub Actions and framework-specific analyzers. The repository currently contains engineering documentation and code comments in Portuguese while the surrounding tooling ecosystems, APIs and external projects are primarily documented in English.

Maintaining mixed engineering languages increases terminology drift, reduces search consistency and makes future external collaboration harder. At the same time, translating the entire repository in one change would create high review cost and would obscure meaningful history.

## Decision

English becomes the canonical engineering language for all new and materially changed engineering artifacts.

This includes source identifiers, code documentation, technical comments, developer-facing errors/logs, rule metadata, ADRs, contribution standards and new developer-facing documentation.

Portuguese remains supported for product and operational content through explicit localization or translated documentation.

The migration is incremental. Existing Portuguese engineering content does not block main solely because it predates this ADR. Newly added engineering prose is checked in CI.

## Consequences

Positive consequences:

- consistent terminology across PHP, Go and analysis tooling;
- easier reuse of upstream terminology and documentation;
- lower friction for external contributors;
- simpler search and review;
- clearer separation between engineering contracts and product localization.

Tradeoffs:

- the repository remains bilingual during migration;
- touched legacy files may include documentation-only changes;
- CI uses a conservative vocabulary-based guard rather than pretending to solve natural-language detection perfectly.

## Migration

Priority order:

1. new code and new rules;
2. shared contracts and public APIs;
3. security and static-analysis engines;
4. Go control-plane code;
5. active framework analyzers;
6. remaining legacy code comments and engineering Markdown.

Large translation-only migrations should be isolated from behavior changes where practical.

## Enforcement

See:

- `docs/development/language-policy.md`;
- `docs/development/documentation-standards.md`;
- `scripts/check-engineering-language.php`.
