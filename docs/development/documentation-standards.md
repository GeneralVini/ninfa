# Documentation Standards

## Principle

Code documentation is part of the implementation contract. It must explain information that is not obvious from syntax: responsibilities, invariants, side effects, evidence boundaries, failure modes and non-trivial design decisions.

All code documentation is written in English.

## PHPDoc

Production classes and named production methods follow the existing strict documentation guard in `tests/internal-docs.php`.

PHPDoc SHOULD:

- explain responsibility and behavioral boundaries;
- document side effects and I/O when relevant;
- describe thrown exceptions when they are part of the contract;
- preserve generic/shape information that native PHP types cannot express;
- document evidence limitations for analyzers and security decisions.

PHPDoc SHOULD NOT merely restate the signature.

Preferred:

```php
/**
 * Resolves a view path using the current Yii controller context.
 *
 * @throws ViewNotFoundException When the view cannot be resolved safely.
 */
public function resolveView(string $view): string
```

Avoid:

```php
/**
 * Resolves a view.
 *
 * @param string $view
 * @return string
 */
```

## GoDoc

Exported Go identifiers MUST follow Go documentation conventions. The comment starts with the exported identifier and describes its contract.

```go
// Analyzer evaluates a target with the configured rule set.
type Analyzer struct {
    // ...
}

// Analyze returns all findings produced for target.
func (a *Analyzer) Analyze(ctx context.Context, target Target) ([]Finding, error) {
    // ...
}
```

## Local comments

Comments explain why a decision exists, not what the next line does.

Preferred:

```php
// Relative Yii views are resolved before the filesystem check so the rule
// does not depend on the consumer project's current working directory.
$path = $resolver->resolve($view);
```

Avoid:

```php
// Loop through the rules.
foreach ($rules as $rule) {
```

## Static-analysis rules

Each Ninfa rule SHOULD document:

- stable rule ID;
- category and severity;
- description;
- why the finding matters;
- non-compliant example;
- compliant example;
- configuration;
- evidence/precision boundary;
- remediation classification;
- autofix behavior;
- limitations.

Rule documentation is canonical in English. Translations may be added separately.

## ADRs

ADRs are written in English and include, at minimum:

- status;
- context;
- decision;
- consequences;
- migration or compatibility notes when applicable.

## Markdown

New developer-facing Markdown is canonical in English. Existing PT-BR engineering documents may be migrated incrementally under the engineering language policy.

Operational/user documentation may have English and PT-BR variants. When both exist, the English engineering version is canonical unless the document explicitly states otherwise.

## Terminology

Use the canonical vocabulary in `docs/development/glossary.md`. Prefer stable terms over synonyms, especially in rule metadata, JSON contracts, logs and documentation.
