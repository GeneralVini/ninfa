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
     * Cria um achado normalizado preservando evidência e metadata sem interpretá-las.
     *
     * `tool` e `rule` são identificadores obrigatórios porque permitem rastrear
     * a origem e a regra mesmo quando não existe localização de arquivo. Linha 0
     * é aceita para achados sem localização textual, como advisories SCA.
     *
     * @param string $tool Identificador estável da ferramenta/fonte que produziu o achado.
     * @param string $file Arquivo relativo ou artefato lógico associado ao achado.
     * @param int $line Linha 1-based quando conhecida; 0 representa ausência de linha.
     * @param string $rule Identificador da regra/advisory na fonte de origem.
     * @param string $problem Descrição normalizada do problema observado.
     * @param string $correction Orientação opcional de correção/remediação.
     * @param string|null $severity Severidade técnica quando fornecida pela origem.
     * @param string|null $confidence Confiança atribuída à evidência normalizada.
     * @param string|null $evidenceType Categoria da evidência, por exemplo `sca-advisory`.
     * @param list<string> $provenance Cadeia de fontes/IDs que sustentam o achado.
     * @param array<string,mixed> $metadata Dados específicos que não cabem no núcleo do contrato.
     * @throws InvalidArgumentException Quando tool/rule estão vazios ou a linha é negativa.
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
        // Sem origem e regra não há identidade mínima para auditoria do finding.
        if ($this->tool === '' || $this->rule === '') {
            throw new InvalidArgumentException('Finding exige tool e rule.');
        }
        // Linha zero representa ausência de localização; valores negativos são inválidos.
        if ($this->line < 0) {
            throw new InvalidArgumentException('Linha do finding não pode ser negativa.');
        }
    }

    /**
     * Converte o finding para o schema JSON comum sem inventar campos ausentes.
     *
     * Campos nucleares permanecem sempre presentes. Severidade, confiança,
     * evidence type, proveniência e metadata só entram quando possuem valor,
     * reduzindo ambiguidade entre `null` e informação efetivamente observada.
     *
     * @return array<string,mixed> Representação serializável do achado.
     */
    public function jsonSerialize(): array
    {
        /** @var array<string,mixed> $data Campos normalizados que serão serializados. */
        $data = [
            'tool' => $this->tool,
            'file' => $this->file,
            'line' => $this->line,
            'rule' => $this->rule,
            'problem' => $this->problem,
            'correction' => $this->correction,
        ];

        // A serialização preserva a diferença entre atributo não informado e valor textual.
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
