# Engineering Language Policy

Status: **Accepted**

## Decision

English is the canonical engineering language of Ninfa.

All new or materially changed engineering artifacts MUST use English for code-facing and developer-facing content. Portuguese remains a first-class product and documentation language where localization or operational adoption requires it.

## Scope

The following artifacts MUST be written in English:

- source-code identifiers;
- PHPDoc, GoDoc, JSDoc and equivalent code documentation;
- code comments that explain behavior, decisions, constraints or invariants;
- developer-facing exception messages;
- technical logs intended for maintainers;
- static-analysis rule identifiers and metadata;
- API/OpenAPI developer descriptions;
- commit messages;
- ADRs;
- contribution and engineering standards;
- new developer-facing Markdown documentation.

User-facing content MUST use the localization mechanism instead of being hardcoded for a single language.

## Product localization boundary

The engineering language policy does not require the product UI to be English-only.

User-facing labels, validation messages, notifications, help text and equivalent content SHOULD be represented by stable translation keys and provided in the supported product languages, including `en` and `pt-BR`.

Technical failures that are not rendered directly to users SHOULD remain in English.

## Existing Portuguese content

The repository contains legacy engineering documentation and code comments in Portuguese. This decision does not require a repository-wide translation in one change.

Migration follows these rules:

1. New engineering content is English-only.
2. A materially modified symbol SHOULD have its related code documentation migrated to English in the same change.
3. Shared contracts, public APIs, security code and static-analysis engines have migration priority.
4. Large translation-only changes SHOULD be isolated from functional changes when practical.
5. Existing operational or user documentation in Portuguese may remain while an English canonical version is introduced.

The CI language gate is intentionally incremental: it checks newly added engineering text so the policy can become enforceable without breaking the current main branch.

## Exceptions

Portuguese is allowed when it is data rather than engineering prose, for example:

- localization fixtures;
- examples that explicitly demonstrate Portuguese input/output;
- quoted third-party text;
- legal or attribution text that must preserve original wording;
- proper names and external identifiers.

Exceptions SHOULD be obvious from context and SHOULD NOT be used to bypass the canonical engineering language.

## Rule identifiers

Ninfa-owned static-analysis rules SHOULD use stable English identifiers. New identifiers SHOULD follow:

```text
NINFA.<Domain>.<Rule>
```

Examples:

```text
NINFA.Yii.RenderViewExists
NINFA.Yii.InefficientExistsQuery
NINFA.Security.DynamicSqlQuery
```

Existing public identifiers are compatibility contracts and are not renamed solely for style.

## Enforcement

`scripts/check-engineering-language.php` checks newly added engineering prose for common Portuguese technical vocabulary.

The check is deliberately conservative:

- it focuses on new lines;
- it checks all added text in canonical engineering documentation;
- in source files it checks comment/documentation lines rather than product strings;
- it does not attempt natural-language classification of arbitrary runtime data.

Human review remains authoritative for ambiguous cases.
