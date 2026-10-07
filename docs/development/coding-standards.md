# Coding Standards

## Language

English is mandatory for:

- identifiers;
- PHPDoc, GoDoc and JSDoc;
- technical comments;
- developer-facing exceptions;
- technical logs;
- rule IDs and rule metadata;
- commit messages.

User-facing prose uses localization rather than hardcoded language-specific strings.

## Identifiers

Prefer explicit domain names over abbreviations.

Examples:

```text
securityInventory
relationReference
evidenceType
remediationRisk
```

Avoid translating domain concepts into Portuguese identifiers.

## Static-analysis naming

Ninfa-owned rules SHOULD use English stable identifiers. Public IDs are compatibility contracts and are not renamed casually.

Rule metadata SHOULD use established values for categories such as:

```text
correctness
security
performance
modernization
deprecation
static-analysis
architecture
```

## Error boundary

Developer-facing exceptions describe the technical failure in English.

User-facing errors must cross the localization boundary before presentation. A technical exception SHOULD NOT become a translated UI message implicitly.

## Comments

Do not add comments for obvious syntax. Add comments when the implementation depends on:

- framework behavior that is not obvious locally;
- security assumptions;
- precision/recall tradeoffs;
- compatibility constraints;
- ordering requirements;
- deliberate non-actions;
- safe-remediation preconditions.

## Commits

Commit messages are written in English. Conventional Commit prefixes are preferred where useful.

Examples:

```text
feat: add Yii view existence rule
fix: preserve module-relative view resolution
docs: define engineering language policy
refactor: move user-facing errors behind localization
test: cover unresolved controller actions
```

## Compatibility

Do not rename stable public rule IDs, serialized fields, CLI options or external contracts solely to satisfy naming style. Compatibility takes precedence; deprecation and migration must be explicit.
