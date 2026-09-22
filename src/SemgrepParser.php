<?php

declare(strict_types=1);

require_once __DIR__ . '/Finding.php';

/** Normaliza findings e cobertura do JSON nativo do Semgrep. */
final class SemgrepParser
{
    /**
     * Converte resultados em Finding e mantém paths/erros como evidência de cobertura.
     *
     * @param string $json Saída de `semgrep --json`.
     * @param string $root Raiz usada para relativizar paths.
     * @return array{findings:list<Finding>,coverage:array<string,mixed>,errors:list<mixed>}
     * @throws JsonException Quando a saída não é JSON válido.
     * @throws UnexpectedValueException Quando o schema mínimo está ausente.
     */
    public static function parse(string $json, string $root): array
    {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data) || !is_array($data['results'] ?? null)) {
            throw new UnexpectedValueException('Semgrep não retornou results em formato JSON.');
        }

        /** @var list<Finding> $findings Resultados válidos convertidos na ordem do Semgrep. */
        $findings = [];
        // Resultado individual incompleto não invalida o documento; recebe defaults auditáveis.
        foreach ($data['results'] as $result) {
            if (!is_array($result)) {
                continue;
            }
            $extra = is_array($result['extra'] ?? null) ? $result['extra'] : [];
            $metadata = is_array($extra['metadata'] ?? null) ? $extra['metadata'] : [];
            $rule = self::text($result['check_id'] ?? null) ?? 'semgrep';
            $findings[] = new Finding(
                tool: 'semgrep',
                file: self::relativePath(self::text($result['path'] ?? null) ?? '.', $root),
                line: max(0, (int) ($result['start']['line'] ?? 0)),
                rule: $rule,
                problem: self::text($extra['message'] ?? null) ?? 'Padrão inseguro detectado pelo Semgrep',
                correction: self::text($metadata['fix'] ?? null) ?? 'Revise o uso e substitua por uma construção segura para dados não confiáveis.',
                severity: strtolower(self::text($extra['severity'] ?? null) ?? 'warning'),
                confidence: strtolower(self::text($metadata['confidence'] ?? null) ?? 'medium'),
                evidenceType: 'sast-pattern',
                provenance: ['semgrep', 'semgrep:' . $rule],
                metadata: [
                    'evidence' => self::text($metadata['evidence'] ?? null) ?? 'DANGEROUS_PRIMITIVE',
                    'end_line' => max(0, (int) ($result['end']['line'] ?? 0)),
                    'engine' => self::text($extra['engine_kind'] ?? null),
                    'rule_metadata' => $metadata,
                ],
            );
        }

        $paths = is_array($data['paths'] ?? null) ? $data['paths'] : [];
        $scanned = is_array($paths['scanned'] ?? null) ? array_values($paths['scanned']) : [];
        $skipped = is_array($paths['skipped'] ?? null) ? array_values($paths['skipped']) : [];
        $errors = is_array($data['errors'] ?? null) ? array_values($data['errors']) : [];
        return [
            'findings' => $findings,
            'coverage' => [
                'status' => ($skipped === [] && $errors === []) ? 'complete' : 'partial',
                'scanned_files' => count($scanned),
                'scanned' => $scanned,
                'skipped' => $skipped,
            ],
            'errors' => $errors,
        ];
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

    /** Relativiza paths absolutos sem alterar paths já relativos. */
    private static function relativePath(string $file, string $root): string
    {
        $file = str_replace('\\', '/', $file);
        $root = rtrim(str_replace('\\', '/', $root), '/');
        return str_starts_with($file, $root . '/') ? substr($file, strlen($root) + 1) : $file;
    }
}
