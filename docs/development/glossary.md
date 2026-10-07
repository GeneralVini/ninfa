# Engineering Glossary

This glossary defines preferred Ninfa engineering terminology.

| Preferred term | Meaning / usage |
| --- | --- |
| analyzer | Component that derives findings or evidence from a target |
| finding | Normalized observation produced by an analyzer or external tool |
| rule | Stable analysis contract that decides whether a pattern should be reported |
| rule ID | Stable identifier for a rule; treat public IDs as compatibility contracts |
| target | Codebase, file, symbol or logical object under analysis |
| evidence | Information supporting a finding or decision |
| provenance | Source chain that explains where evidence originated |
| severity | Technical impact level reported by the source/rule |
| confidence | Confidence in the evidence or classification |
| remediation | Recommended change addressing a finding |
| safe remediation | Change whose semantic preconditions are proven sufficiently for automated application |
| review remediation | Suggested change requiring human judgment |
| suppression | Explicit decision to ignore a finding under documented conditions |
| baseline | Reference state used to compare future analysis |
| profile | Framework/product-specific analysis configuration and capabilities |
| rule set | Collection of rules evaluated together |
| security smell | Security-relevant pattern without sufficient evidence to claim a vulnerability |
| vulnerability | Security weakness supported by the evidence contract for that rule/source |
| coverage | Observable portion of the intended analysis scope that was actually evaluated |
| partial | Execution state indicating incomplete expected coverage |
| unavailable | Execution state indicating a required or optional source/tool could not be used |

## Usage notes

Prefer `finding` over generic alternatives such as "issue" when referring to the normalized Ninfa contract.

Prefer `analyzer` for components that inspect code and produce evidence. Use `scanner` when referring to an external scanner that uses that terminology.

Do not use `vulnerability` for correctness, performance, modernization or architecture findings unless the security evidence contract is actually satisfied.
