<?php

declare(strict_types=1);

/**
 * Representa um achado normalizado produzido por uma ferramenta ou fonte.
 *
 * O contrato preserva localização, regra, problema, correção opcional e
 * atributos de segurança como severidade, confiança, tipo de evidência,
 * proveniência e metadata específica. A serialização omite campos opcionais
 * ausentes, mas sempre inclui tool, file, line, rule, problem e correction.
 *
 * Esta classe não calcula prioridade, não deduplica advisories e não decide
 * se o achado bloqueia a execução; essas responsabilidades ficam em camadas
 * superiores.
 */
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
