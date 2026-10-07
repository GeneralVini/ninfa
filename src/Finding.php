<?php

declare(strict_types=1);

/**
 * Represents a normalized finding produced by a tool or evidence source.
 *
 * The contract preserves location, rule, problem, optional remediation and
 * security attributes such as severity, confidence, evidence type, provenance
 * and source-specific metadata. Serialization omits absent optional fields but
 * always includes tool, file, line, rule, problem and correction.
 *
 * This class does not calculate priority, deduplicate advisories or decide
 * whether a finding blocks execution. Those responsibilities belong to higher
 * orchestration layers.
 */
final class Finding implements JsonSerializable
{
    /**
     * Creates a normalized finding while preserving evidence and metadata.
     *
     * `tool` and `rule` are mandatory identifiers because they preserve
     * traceability even when no file location exists. Line zero is accepted for
     * findings without a textual location, such as SCA advisories.
     *
     * @param string $tool Stable identifier of the tool/source that produced the finding.
     * @param string $file Relative file or logical artifact associated with the finding.
     * @param int $line One-based line when known; 0 represents no textual line.
     * @param string $rule Rule/advisory identifier from the originating source.
     * @param string $problem Normalized description of the observed problem.
     * @param string $correction Optional remediation guidance.
     * @param string|null $severity Technical severity when supplied by the source.
     * @param string|null $confidence Confidence assigned to the normalized evidence.
     * @param string|null $evidenceType Evidence category, for example `sca-advisory`.
     * @param list<string> $provenance Source/identifier chain supporting the finding.
     * @param array<string,mixed> $metadata Source-specific data outside the core contract.
     * @throws InvalidArgumentException When tool/rule are empty or line is negative.
     */
    public function __construct(
        public readonly string $tool,
        public readonly string $file,
        public readonly int $line,
        public readonly string $rule,
        public readonly string $problem,
        public readonly string $correction = '',
        public readonly ?string $severity = null,
        public readonly ?string $confidence = null,
        public readonly ?string $evidenceType = null,
        public readonly array $provenance = [],
        public readonly array $metadata = [],
    ) {
        // A finding needs both a source and a rule to retain minimum audit identity.
        if ($this->tool === '' || $this->rule === '') {
            throw new InvalidArgumentException('Finding requires non-empty tool and rule identifiers.');
        }

        // Line zero represents no textual location; negative values are invalid.
        if ($this->line < 0) {
            throw new InvalidArgumentException('Finding line cannot be negative.');
        }
    }

    /**
     * Converts the finding to the common JSON schema without inventing absent fields.
     *
     * Core fields are always present. Severity, confidence, evidence type,
     * provenance and metadata are emitted only when they have a value, preserving
     * the distinction between missing information and an explicitly observed value.
     *
     * @return array<string,mixed> Serializable finding representation.
     */
    public function jsonSerialize(): array
    {
        /** @var array<string,mixed> $data Normalized fields that will be serialized. */
        $data = [
            'tool' => $this->tool,
            'file' => $this->file,
            'line' => $this->line,
            'rule' => $this->rule,
            'problem' => $this->problem,
            'correction' => $this->correction,
        ];

        // Serialization preserves the difference between an absent attribute and textual data.
        if ($this->severity !== null) {
            $data['severity'] = $this->severity;
        }
        if ($this->confidence !== null) {
            $data['confidence'] = $this->confidence;
        }
        if ($this->evidenceType !== null) {
            $data['evidence_type'] = $this->evidenceType;
        }
        if ($this->provenance !== []) {
            $data['provenance'] = $this->provenance;
        }
        if ($this->metadata !== []) {
            $data['metadata'] = $this->metadata;
        }

        return $data;
    }
}
