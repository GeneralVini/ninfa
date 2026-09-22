<?php

declare(strict_types=1);

/**
 * Descreve uma classe de vulnerabilidade independentemente do motor SAST.
 *
 * O contrato preserva sources, sinks, sanitizers, primitives e padrões seguros
 * como semântica do Ninfa. Adapters podem consumir esses dados sem embutir
 * condicionais de profile ou redefinir o significado da vulnerabilidade.
 */
final class SastContract implements JsonSerializable
{
    /**
     * Cria um contrato SAST validando seu identificador e suas listas semânticas.
     *
     * @param string $id Identificador estável da classe de vulnerabilidade.
     * @param list<string> $sources Origens de dados não confiáveis conhecidas.
     * @param list<string> $sinks Operações sensíveis que recebem dados.
     * @param list<string> $sanitizers Operações que reduzem ou eliminam o risco.
     * @param list<string> $dangerousPrimitives Primitives perigosas mesmo sem dataflow comprovado.
     * @param list<string> $safePatterns Padrões seguros relevantes para revisão e fixtures negativas.
     * @param list<string> $evidenceTypes Evidências que adapters podem produzir para o contrato.
     * @param list<string> $provenance Origem comum ou overlay que declarou a semântica.
     */
    public function __construct(
        public readonly string $id,
        public readonly array $sources,
        public readonly array $sinks,
        public readonly array $sanitizers,
        public readonly array $dangerousPrimitives,
        public readonly array $safePatterns,
        public readonly array $evidenceTypes,
        public readonly array $provenance,
    ) {
        if ($id === '' || $evidenceTypes === [] || $provenance === []) {
            throw new InvalidArgumentException('Contrato SAST exige id, evidência e provenance.');
        }
    }

    /**
     * Combina a base comum com uma especialização sem descartar semântica anterior.
     *
     * @param self $overlay Complemento específico de um profile.
     * @return self Contrato consolidado com listas únicas e provenance preservada.
     */
    public function merge(self $overlay): self
    {
        if ($overlay->id !== $this->id) {
            throw new InvalidArgumentException('Overlay SAST deve possuir o mesmo id do contrato base.');
        }

        /** @var callable(list<string>,list<string>):list<string> $combine Combina listas sem duplicação. */
        $combine = static fn (array $base, array $extra): array => array_values(array_unique([...$base, ...$extra]));

        return new self(
            $this->id,
            $combine($this->sources, $overlay->sources),
            $combine($this->sinks, $overlay->sinks),
            $combine($this->sanitizers, $overlay->sanitizers),
            $combine($this->dangerousPrimitives, $overlay->dangerousPrimitives),
            $combine($this->safePatterns, $overlay->safePatterns),
            $combine($this->evidenceTypes, $overlay->evidenceTypes),
            $combine($this->provenance, $overlay->provenance),
        );
    }

    /**
     * Serializa o contrato sem perder listas semânticas ou provenance.
     *
     * @return array<string,mixed> Payload estável para relatório e inspeção.
     */
    public function jsonSerialize(): array
    {
        return get_object_vars($this);
    }
}
