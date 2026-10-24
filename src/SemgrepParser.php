<?php

declare(strict_types=1);

require_once __DIR__ . '/Finding.php';

/** Normaliza findings e cobertura do JSON nativo do Semgrep. */
final class SemgrepParser
{
    /**
     * Converte resultados em Finding e separa exclusões deliberadas de perda real de cobertura.
     *
     * Entradas ignoradas por política (`--exclude`, `.semgrepignore` ou `.gitignore` quando aplicável)
     * permanecem auditáveis, mas não tornam o scan parcial. Skips inesperados e erros do motor
     * representam cobertura incompleta e precisam permanecer visíveis ao pipeline.
     *
     * @param string $json Saída de `semgrep --json`.
     * @param string $root Raiz usada para relativizar paths.
     * @return array{findings:list<Finding>,coverage:array<string,mixed>,errors:list<mixed>}
     * @throws JsonException Quando a saída não é JSON válido.
     * @throws UnexpectedValueException Quando o schema mínimo está ausente.
     */
    public static function parse(string $json, string $root): array
    {
        // A leitura como objeto distingue {} de [], que o decode associativo iguala.
        $document = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        if (!$document instanceof stdClass || !is_array($document->results ?? null)) {
            throw new UnexpectedValueException('Semgrep não retornou uma lista JSON results.');
        }
        foreach (['errors', 'skipped_rules'] as $field) {
            if (property_exists($document, $field) && !is_array($document->{$field})) {
                throw new UnexpectedValueException('Semgrep retornou coleção ' . $field . ' inválida.');
            }
        }
        $rawPaths = $document->paths ?? null;
        if ($rawPaths instanceof stdClass && property_exists($rawPaths, 'skipped') && !is_array($rawPaths->skipped)) {
            throw new UnexpectedValueException('Semgrep retornou coleção paths.skipped inválida.');
        }
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data) || !is_array($data['results'] ?? null) || !array_is_list($data['results'])) {
            throw new UnexpectedValueException('Semgrep não retornou results em formato JSON.');
        }
        if (array_key_exists('errors', $data) && !is_array($data['errors'])) {
            throw new UnexpectedValueException('Semgrep retornou coleção errors inválida.');
        }

        /** @var list<Finding> $findings Resultados válidos convertidos na ordem do Semgrep. */
        $findings = [];
        // Identidade mínima é obrigatória para não ocultar resultados malformados.
        foreach ($data['results'] as $result) {
            if (!is_array($result) || self::text($result['check_id'] ?? null) === null
                || self::text($result['path'] ?? null) === null) {
                throw new UnexpectedValueException('Semgrep retornou resultado sem regra/path válidos.');
            }
            $extra = is_array($result['extra'] ?? null) ? $result['extra'] : [];
            $severity = strtolower(self::text($extra['severity'] ?? null) ?? '');
            if (!in_array($severity, ['error', 'warning', 'info', 'critical', 'high', 'medium', 'low', 'inventory', 'experiment'], true)) {
                throw new UnexpectedValueException('Semgrep retornou severidade ausente ou não suportada.');
            }
            $metadata = is_array($extra['metadata'] ?? null) ? $extra['metadata'] : [];
            $rule = self::text($result['check_id'] ?? null) ?? 'semgrep';
            $findings[] = new Finding(
                tool: 'semgrep',
                file: self::relativePath(self::text($result['path'] ?? null) ?? '.', $root),
                line: max(0, (int) ($result['start']['line'] ?? 0)),
                rule: $rule,
                problem: self::text($extra['message'] ?? null) ?? 'Padrão inseguro detectado pelo Semgrep',
                correction: self::text($metadata['fix'] ?? null) ?? 'Revise o uso e substitua por uma construção segura para dados não confiáveis.',
                severity: $severity,
                confidence: strtolower(self::text($metadata['confidence'] ?? null) ?? 'medium'),
                evidenceType: 'sast-pattern',
                provenance: ['semgrep', 'semgrep:' . $rule],
                metadata: [
                    'evidence' => self::text($metadata['evidence'] ?? null) ?? 'DANGEROUS_PRIMITIVE',
                    'end_line' => max(0, (int) ($result['end']['line'] ?? 0)),
                    'start' => $result['start'] ?? null,
                    'end' => $result['end'] ?? null,
                    'dataflow_trace' => $extra['dataflow_trace'] ?? null,
                    'confirmation' => 'unverified',
                    'engine' => self::text($extra['engine_kind'] ?? null),
                    'rule_metadata' => $metadata,
                ],
            );
        }

        $paths = is_array($data['paths'] ?? null) ? $data['paths'] : [];
        $scanned = is_array($paths['scanned'] ?? null) ? array_values($paths['scanned']) : [];
        $skipped = is_array($paths['skipped'] ?? null) ? array_values($paths['skipped']) : [];
        $errors = is_array($data['errors'] ?? null) ? array_values($data['errors']) : [];
        $skippedRules = $data['skipped_rules'] ?? [];
        $validScanned = $rawPaths instanceof stdClass && is_array($rawPaths->scanned ?? null);
        foreach ($scanned as $path) {
            if (!is_string($path) || trim($path) === '') {
                $validScanned = false;
            }
        }

        /** @var list<mixed> $excludedByPolicy Skips explicados por política de targeting/ignore. */
        $excludedByPolicy = [];
        /** @var list<mixed> $unexpectedSkips Skips sem justificativa operacional conhecida. */
        $unexpectedSkips = [];
        foreach ($skipped as $skip) {
            if (self::isPolicySkip($skip)) {
                $excludedByPolicy[] = $skip;
            } else {
                $unexpectedSkips[] = $skip;
            }
        }

        return [
            'findings' => $findings,
            'coverage' => [
                'status' => ($unexpectedSkips === [] && $errors === [] && $skippedRules === [] && $validScanned) ? 'complete' : 'partial',
                'scanned_files' => $validScanned ? count($scanned) : null,
                'scanned' => $scanned,
                'skipped' => $skipped,
                'excluded_by_policy' => $excludedByPolicy,
                'unexpected_skips' => $unexpectedSkips,
                'skipped_rules' => $skippedRules,
            ],
            'errors' => $errors,
        ];
    }

    /**
     * Decide se um item de `paths.skipped` representa exclusão deliberada e auditável.
     *
     * A classificação combina razão reportada pelo Semgrep com diretórios que o
     * runner exclui explicitamente. O método não trata parse error, timeout ou
     * motivo desconhecido como exclusão segura.
     */
    private static function isPolicySkip(mixed $skip): bool
    {
        if (!is_array($skip)) {
            return false;
        }

        /** @var list<string> $reasonParts Fragmentos textuais usados para classificar o motivo do skip. */
        $reasonParts = [];
        foreach (['reason', 'details'] as $key) {
            $value = $skip[$key] ?? null;
            if (is_string($value)) {
                $reasonParts[] = strtolower($value);
            } elseif (is_array($value)) {
                $reasonParts[] = strtolower((string) json_encode($value));
            }
        }
        $reason = implode(' ', $reasonParts);

        // Erros operacionais têm precedência sobre diretórios normalmente excluídos.
        if (preg_match('/timeout|timed.?out|parse.?error|analysis.?error|permission|crash/i', $reason) === 1) {
            return false;
        }
        $path = strtolower(str_replace('\\', '/', self::text($skip['path'] ?? null) ?? ''));
        if ($reason === 'cli_include_flags_do_not_match') {
            foreach (['/vendor/', '/runtime/', '/public/assets/', '/web/assets/'] as $segment) {
                if (str_contains('/' . ltrim($path, '/'), $segment)) {
                    return true;
                }
            }
        }

        return str_contains($reason, 'ignore') || str_contains($reason, 'exclude');
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
