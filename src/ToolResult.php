<?php

declare(strict_types=1);

require_once __DIR__ . '/Finding.php';

final class ToolResult implements JsonSerializable
{
    public const OK = 'ok';
    public const FAILED = 'failed';
    public const ERROR = 'error';
    public const SKIPPED = 'skipped';

    /**
     * @param list<Finding> $findings
     */
    public function __construct(
        public readonly string $id,
        public readonly string $state,
        public readonly ?int $exitCode,
        public readonly ?string $detail = null,
        public readonly array $findings = [],
        public readonly ?int $durationMs = null,
    ) {
        if ($this->id === '') {
            throw new InvalidArgumentException('ToolResult exige id.');
        }
        if (!in_array($this->state, [self::OK, self::FAILED, self::ERROR, self::SKIPPED], true)) {
            throw new InvalidArgumentException('Estado de ToolResult inválido: ' . $this->state);
        }
        foreach ($this->findings as $finding) {
            if (!$finding instanceof Finding) {
                throw new InvalidArgumentException('ToolResult aceita apenas Finding em findings.');
            }
        }
    }

    public static function skipped(string $id, string $detail): self
    {
        return new self($id, self::SKIPPED, null, $detail);
    }

    public static function error(string $id, string $detail, ?int $durationMs = null): self
    {
        return new self($id, self::ERROR, 1, $detail, [], $durationMs);
    }

    /** @param list<Finding> $findings */
    public static function completed(string $id, int $exitCode, array $findings = [], ?int $durationMs = null): self
    {
        return new self(
            $id,
            $exitCode === 0 ? self::OK : self::FAILED,
            $exitCode,
            null,
            $findings,
            $durationMs,
        );
    }

    public function isFailure(): bool
    {
        return $this->state === self::FAILED || $this->state === self::ERROR;
    }

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'state' => $this->state,
            'exit_code' => $this->exitCode,
            'detail' => $this->detail,
            'duration_ms' => $this->durationMs,
            'findings' => $this->findings,
        ];
    }
}
