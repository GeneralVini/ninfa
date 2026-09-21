<?php

declare(strict_types=1);

require_once __DIR__ . '/ToolResult.php';

/**
 * Consolida o resultado completo de uma operação do Ninfa.
 *
 * Mantém o nome da operação, os resultados de cada ferramenta/etapa e o exit
 * code final derivado do primeiro exit não zero. `findings()` apenas achata os findings de
 * todos os ToolResult; não deduplica advisories, não calcula prioridade e não
 * altera o estado das ferramentas.
 */
final class RunResult implements JsonSerializable
{
    /** Primeiro exit code diferente de zero, ou zero quando todas as etapas passam. */
    public readonly int $finalExitCode;

    /**
     * Cria o snapshot imutável de uma execução já finalizada.
     *
     * O construtor valida operação e coleção, então deriva o exit final do
     * primeiro ToolResult com código diferente de zero. Etapas `skipped` não
     * possuem código e não alteram o resultado global.
     *
     * @param string $operation Operação pública executada (`check`, `fix` ou `security`).
     * @param list<ToolResult> $toolResults Resultados das etapas na ordem observada.
     * @throws InvalidArgumentException Quando operation está vazia ou a coleção contém outro tipo.
     */
    public function __construct(
        public readonly string $operation,
        public readonly array $toolResults,
    ) {
        if ($this->operation === '') {
            throw new InvalidArgumentException('RunResult exige operation.');
        }

        $finalExitCode = 0;
        // A coleção tipada é protegida em runtime porque arrays PHP não impõem o tipo dos elementos.
        foreach ($this->toolResults as $result) {
            if (!$result instanceof ToolResult) {
                throw new InvalidArgumentException('RunResult aceita apenas ToolResult.');
            }
            if ($finalExitCode === 0 && $result->exitCode !== null && $result->exitCode !== 0) {
                $finalExitCode = $result->exitCode;
            }
        }
        $this->finalExitCode = $finalExitCode;
    }

    /**
     * Achata os findings de todas as etapas sem alterar ordem ou conteúdo.
     *
     * @return list<Finding> Findings na ordem dos ToolResult e, dentro deles, na ordem original.
     */
    public function findings(): array
    {
        /** @var list<Finding> $findings */
        $findings = [];
        foreach ($this->toolResults as $result) {
            foreach ($result->findings as $finding) {
                $findings[] = $finding;
            }
        }
        return $findings;
    }

    /**
     * Serializa a execução preservando resultados por ferramenta e visão achatada dos findings.
     *
     * @return array<string,mixed> Schema serializável do resultado completo da operação.
     */
    public function jsonSerialize(): array
    {
        return [
            'operation' => $this->operation,
            'final_exit_code' => $this->finalExitCode,
            'tools' => $this->toolResults,
            'findings' => $this->findings(),
        ];
    }
}
