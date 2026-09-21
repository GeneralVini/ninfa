<?php

declare(strict_types=1);

final class Finding implements JsonSerializable
{
    /**
     * @param list<string> $provenance
     * @param array<string,mixed> $metadata
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
        if ($this->tool === '' || $this->rule === '') {
            throw new InvalidArgumentException('Finding exige tool e rule.');
        }
        if ($this->line < 0) {
            throw new InvalidArgumentException('Linha do finding não pode ser negativa.');
        }
    }

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        $data = [
            'tool' => $this->tool,
            'file' => $this->file,
            'line' => $this->line,
            'rule' => $this->rule,
            'problem' => $this->problem,
            'correction' => $this->correction,
        ];

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
