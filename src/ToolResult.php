<?php

declare(strict_types=1);

require_once __DIR__ . '/Finding.php';

/**
 * Registra o resultado observável de uma única etapa do pipeline.
 *
 * O estado distingue conclusão sem erro (`ok`), falha da ferramenta (`failed`),
 * erro de orquestração (`error`), etapa ignorada (`skipped`), não aplicável,
 * indisponível e cobertura parcial. O objeto também preserva duração, detalhe,
 * findings e evidências de cobertura produzidos pela etapa.
 *
 * `completed()` deriva `ok`/`failed` exclusivamente do exit code recebido;
 * políticas de bloqueio ou cobertura ficam fora deste contrato.
 */
final class ToolResult implements JsonSerializable
{
    /** Estado de etapa executada com exit code 0. */
    public const OK = 'ok';
    /** Estado de etapa executada que terminou com exit code não zero. */
    public const FAILED = 'failed';
    /** Estado de falha de orquestração/infraestrutura sem conclusão normal da ferramenta. */
    public const ERROR = 'error';
    /** Estado de etapa deliberadamente não executada. */
    public const SKIPPED = 'skipped';
    /** Etapa conhecida que não se aplica ao contexto. */
    public const NOT_APPLICABLE = 'not_applicable';
    /** Capability aplicável cujo executável/recurso não está disponível. */
    public const UNAVAILABLE = 'unavailable';
    /** Etapa executada com cobertura explicitamente incompleta. */
    public const PARTIAL = 'partial';

    /**
     * Cria o resultado imutável de uma etapa e valida seu estado/coleção de findings.
     *
     * `exitCode` é null para `skipped` e `not_applicable`; `ok` exige zero e
     * estados de falha exigem código diferente de zero. O construtor não infere
     * o estado, mas rejeita combinações contraditórias. Duração, quando presente,
     * nunca pode ser negativa.
     *
     * @param string $id Identificador da etapa no PipelinePlan.
     * @param string $state Um dos estados públicos definidos nesta classe.
     * @param int|null $exitCode Código observado ou null quando não existe processo concluído.
     * @param string|null $detail Contexto adicional para skipped/error.
     * @param list<Finding> $findings Achados normalizados produzidos pela etapa.
     * @param int|null $durationMs Duração observada em milissegundos quando medida.
     * @param array<string,mixed> $coverage Evidências estruturadas da cobertura observada.
     * @throws InvalidArgumentException Quando id/estado/coleção são inválidos.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $state,
        public readonly ?int $exitCode,
        public readonly ?string $detail = null,
        public readonly array $findings = [],
        public readonly ?int $durationMs = null,
        /** @var array<string,mixed> */
        public readonly array $coverage = [],
    ) {
        if ($this->id === '') {
            throw new InvalidArgumentException('ToolResult exige id.');
        }
        if (!in_array($this->state, [self::OK, self::FAILED, self::ERROR, self::SKIPPED, self::NOT_APPLICABLE, self::UNAVAILABLE, self::PARTIAL], true)) {
            throw new InvalidArgumentException('Estado de ToolResult inválido: ' . $this->state);
        }
        if (in_array($this->state, [self::SKIPPED, self::NOT_APPLICABLE], true) && $this->exitCode !== null) {
            throw new InvalidArgumentException('ToolResult não executado não possui exit code.');
        }
        if (!in_array($this->state, [self::SKIPPED, self::NOT_APPLICABLE], true) && $this->exitCode === null) {
            throw new InvalidArgumentException('ToolResult executado exige exit code.');
        }
        if ($this->state === self::OK && $this->exitCode !== 0) {
            throw new InvalidArgumentException('ToolResult ok exige exit code 0.');
        }
        if (in_array($this->state, [self::FAILED, self::ERROR, self::UNAVAILABLE, self::PARTIAL], true) && $this->exitCode === 0) {
            throw new InvalidArgumentException('ToolResult de falha exige exit code diferente de 0.');
        }
        if ($this->durationMs !== null && $this->durationMs < 0) {
            throw new InvalidArgumentException('Duração de ToolResult não pode ser negativa.');
        }

        // Arrays PHP não protegem o tipo dos elementos; o contrato é validado em runtime.
        foreach ($this->findings as $finding) {
            if (!$finding instanceof Finding) {
                throw new InvalidArgumentException('ToolResult aceita apenas Finding em findings.');
            }
        }
    }

    /**
     * Representa uma etapa conhecida que não se aplica ao contexto atual.
     *
     * @param string $id Identificador da etapa ignorada.
     * @param string $detail Razão observável para a não execução.
     */
    public static function skipped(string $id, string $detail): self
    {
        return new self($id, self::SKIPPED, null, $detail);
    }

    /** Representa uma etapa conhecida que não se aplica ao contexto. */
    public static function notApplicable(string $id, string $detail): self
    {
        return new self($id, self::NOT_APPLICABLE, null, $detail);
    }

    /** Representa uma capability aplicável sem recurso executável disponível. */
    public static function unavailable(string $id, string $detail, ?int $durationMs = null): self
    {
        return new self($id, self::UNAVAILABLE, 1, $detail, [], $durationMs);
    }

    /**
     * Representa scanner concluído sem comprovação de cobertura integral.
     *
     * @param string $id Identificador da etapa.
     * @param string $detail Motivo observável da cobertura parcial.
     * @param list<Finding> $findings Achados ainda válidos na parte analisada.
     * @param array<string,mixed> $coverage Evidência de paths/erros do scanner.
     * @param int|null $durationMs Duração observada.
     */
    public static function partial(string $id, string $detail, array $findings, array $coverage, ?int $durationMs = null): self
    {
        return new self($id, self::PARTIAL, 1, $detail, $findings, $durationMs, $coverage);
    }

    /**
     * Representa falha de execução/orquestração que impediu conclusão normal da etapa.
     *
     * O exit code lógico é 1 porque não há necessariamente um processo externo
     * que possa fornecer código próprio.
     *
     * @param string $id Identificador da etapa que falhou.
     * @param string $detail Mensagem factual da falha observada.
     * @param int|null $durationMs Duração até a falha quando disponível.
     */
    public static function error(string $id, string $detail, ?int $durationMs = null): self
    {
        return new self($id, self::ERROR, 1, $detail, [], $durationMs);
    }

    /**
     * Converte uma execução concluída em estado `ok` ou `failed` pelo exit code.
     *
     * @param string $id Identificador da etapa executada.
     * @param int $exitCode Código retornado pela ferramenta/adapter.
     * @param list<Finding> $findings Achados normalizados preservados com o resultado.
     * @param int|null $durationMs Duração total observada em milissegundos.
     * @param array<string,mixed> $coverage Evidências estruturadas da cobertura observada.
     */
    public static function completed(string $id, int $exitCode, array $findings = [], ?int $durationMs = null, array $coverage = []): self
    {
        return new self(
            $id,
            $exitCode === 0 ? self::OK : self::FAILED,
            $exitCode,
            null,
            $findings,
            $durationMs,
            $coverage,
        );
    }

    /**
     * Indica se o estado representa falha que deve participar do resumo de erro.
     *
     * `skipped` não é classificado como falha neste contrato, mesmo que a
     * cobertura de uma etapa ignorada possa ser interpretada por políticas futuras.
     */
    public function isFailure(): bool
    {
        return in_array($this->state, [self::FAILED, self::ERROR, self::UNAVAILABLE, self::PARTIAL], true);
    }

    /**
     * Serializa todos os atributos observáveis sem recalcular estado ou findings.
     *
     * @return array<string,mixed> Representação estável da etapa para relatórios/JSON.
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'state' => $this->state,
            'exit_code' => $this->exitCode,
            'detail' => $this->detail,
            'duration_ms' => $this->durationMs,
            'findings' => $this->findings,
            'coverage' => $this->coverage,
        ];
    }
}
