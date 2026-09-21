<?php

declare(strict_types=1);

require_once __DIR__ . '/ToolResult.php';

final class RunResult implements JsonSerializable
{
    /** @param list<ToolResult> $toolResults */
    public function __construct(
        public readonly string $operation,
        public readonly array $toolResults,
        public readonly int $finalExitCode,
    ) {
        if ($this->operation === '') {
            throw new InvalidArgumentException('RunResult exige operation.');
        }
        foreach ($this->toolResults as $result) {
            if (!$result instanceof ToolResult) {
                throw new InvalidArgumentException('RunResult aceita apenas ToolResult.');
            }
        }
    }

    /** @return list<Finding> */
    public function findings(): array
    {
        $findings = [];
        foreach ($this->toolResults as $result) {
            foreach ($result->findings as $finding) {
                $findings[] = $finding;
            }
        }
        return $findings;
    }

    /** @return array<string,mixed> */
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
