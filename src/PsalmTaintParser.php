<?php

declare(strict_types=1);

require_once __DIR__ . '/Finding.php';

/** Normaliza a saída JSON do Psalm Taint sem interpretar políticas de bloqueio. */
final class PsalmTaintParser
{
    /**
     * Converte cada issue do Psalm em evidência DATAFLOW preservando o taint trace.
     *
     * @param string $json Saída JSON produzida pelo Psalm.
     * @param string $root Raiz usada para relativizar arquivos.
     * @return list<Finding> Findings SAST de dataflow.
     * @throws JsonException Quando a saída não é JSON válido.
     * @throws UnexpectedValueException Quando a raiz JSON não é uma lista de issues.
     */
    public static function parse(string $json, string $root): array
    {
        $issues = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($issues) || !array_is_list($issues)) {
            throw new UnexpectedValueException('Psalm Taint não retornou uma lista JSON de issues.');
        }

        /** @var list<Finding> $findings Issues válidas convertidas na ordem da fonte. */
        $findings = [];
        // Entradas malformadas são ignoradas individualmente; a raiz inválida falha antes do loop.
        foreach ($issues as $issue) {
            if (!is_array($issue)) {
                continue;
            }
            $rule = self::text($issue['type'] ?? null) ?? 'PsalmTaint';
            $message = self::text($issue['message'] ?? null) ?? 'Fluxo de dados inseguro detectado';
            $trace = is_array($issue['taint_trace'] ?? null) ? $issue['taint_trace'] : [];
            $findings[] = new Finding(
                tool: 'psalm-taint',
                file: self::relativePath(self::text($issue['file_name'] ?? null) ?? '.', $root),
                line: max(0, (int) ($issue['line_from'] ?? 0)),
                rule: $rule,
                problem: $message,
                correction: 'Interrompa o fluxo não confiável com validação, sanitização ou API segura antes do sink.',
                severity: self::severity($issue['severity'] ?? null),
                confidence: $trace === [] ? 'medium' : 'high',
                evidenceType: 'sast-dataflow',
                provenance: ['psalm-taint', 'psalm:' . $rule],
                metadata: array_filter([
                    'evidence' => 'DATAFLOW',
                    'selected_text' => self::text($issue['selected_text'] ?? null),
                    'trace' => $trace,
                ], static fn (mixed $value): bool => $value !== null && $value !== []),
            );
        }
        return $findings;
    }

    /**
     * Normaliza a severidade textual sem inventar valor quando ausente.
     *
     * @param mixed $value Severidade bruta do Psalm.
     */
    private static function severity(mixed $value): ?string
    {
        $severity = self::text($value);
        return $severity === null ? null : strtolower($severity);
    }

    /**
     * Retorna texto não vazio após trim ou null para payload não textual.
     *
     * @param mixed $value Valor textual opcional.
     */
    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /** Relativiza paths absolutos sem tentar resolver symlinks. */
    private static function relativePath(string $file, string $root): string
    {
        $file = str_replace('\\', '/', $file);
        $root = rtrim(str_replace('\\', '/', $root), '/');
        return str_starts_with($file, $root . '/') ? substr($file, strlen($root) + 1) : $file;
    }
}
