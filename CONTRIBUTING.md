# Contributing to Ninfa

## Engineering language

English is the canonical engineering language.

All new or materially changed contributions MUST use English for:

- source-code identifiers;
- PHPDoc, GoDoc and JSDoc;
- technical comments;
- developer-facing exception messages;
- technical logs;
- static-analysis rule IDs and metadata;
- commit messages;
- ADRs and developer-facing documentation.

User-facing content must use the localization boundary rather than introducing a new hardcoded language-specific string.

See `docs/development/language-policy.md` for the complete policy.

## Development checks

Before opening a pull request, run the checks relevant to your change. The repository-wide entry points are:

```bash
make syntax
make profile-test
make semgrep-rules
make go-check
make language-check
```

`make language-check` validates newly added engineering prose. Existing Portuguese legacy documentation is migrated incrementally and is not treated as a reason for unrelated changes to fail.

## Documentation

Code documentation is part of the implementation contract. New or materially modified production symbols should be documented in the same change.

Follow:

- `docs/development/documentation-standards.md`;
- `docs/development/coding-standards.md`;
- `docs/development/glossary.md`.

## Compatibility

Do not rename public rule IDs, serialized fields, CLI options or other stable contracts solely for style. If a contract must change, document compatibility, migration and deprecation behavior explicitly.

## Pull requests

Keep behavior changes and broad translation-only changes separate when practical. This preserves useful history and makes review of semantic changes easier.

The pull-request checklist is defined in `.github/pull_request_template.md`.
