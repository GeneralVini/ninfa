<?php

declare(strict_types=1);
require_once __DIR__ . '/Finding.php';

/** Normaliza matches JSONL Nuclei; estatísticas e erros de processo usam canais separados. */
final class NucleiParser
{
    /**
     * Valida cada evento sem descartar silenciosamente linhas incompatíveis.
     * @param string $jsonl Apenas o canal JSONL de resultados do motor.
     * @return array{findings:list<Finding>,coverage:array<string,mixed>} Matches com cobertura explicitamente desconhecida.
     */
    public static function parse(string $jsonl): array
    {
        /** @var list<Finding> $findings Matches válidos, sem agrupar templates ou matchers. */
        $findings = [];
        foreach (preg_split('/\R/', trim($jsonl)) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }
            $event = json_decode($line, false, 512, JSON_THROW_ON_ERROR);
            if (!$event instanceof stdClass) {
                throw new UnexpectedValueException('Nuclei exige objeto JSON por linha.');
            }
            $a = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            $id = $a['template-id'] ?? null;
            $target = $a['matched-at'] ?? $a['host'] ?? null;
            $info = $a['info'] ?? null;
            if (!is_string($id) || trim($id) === '' || !is_string($target) || trim($target) === ''
                || !is_array($info) || !is_string($info['name'] ?? null) || !is_string($a['type'] ?? null)) {
                throw new UnexpectedValueException('Nuclei evento sem identidade, alvo, tipo ou info.');
            }
            $severity = $info['severity'] ?? null;
            if (!in_array($severity, ['unknown', 'info', 'low', 'medium', 'high', 'critical'], true)) {
                throw new UnexpectedValueException('Nuclei severidade incompatível.');
            }
            if (array_key_exists('matcher-status', $a) && $a['matcher-status'] !== true) {
                throw new UnexpectedValueException('Nuclei evento não representa match positivo.');
            }
            // Payloads opacos não fazem parte do modelo publicável de findings.
            $findings[] = new Finding(
                tool: 'nuclei', file: $target, line: 0, rule: $id, problem: $info['name'],
                correction: is_string($info['remediation'] ?? null) ? $info['remediation'] : '',
                severity: $severity, confidence: null, evidenceType: 'dast-match', provenance: ['nuclei', 'nuclei:' . $id],
                metadata: ['template' => $a['template-path'] ?? $a['template'] ?? null,
                    'template_url' => $a['template-url'] ?? null, 'matcher' => $a['matcher-name'] ?? null,
                    'extractor' => $a['extractor-name'] ?? null, 'extracted_results' => $a['extracted-results'] ?? null,
                    'host' => $a['host'] ?? null, 'matched_at' => $target, 'type' => $a['type'],
                    'method' => $a['method'] ?? null, 'parameter' => $a['parameter'] ?? null,
                    'classification' => $info['classification'] ?? null, 'references' => $info['reference'] ?? null,
                    'timestamp' => $a['timestamp'] ?? null, 'native_id' => $a['id'] ?? null,
                    'confirmation' => 'unverified'],
            );
        }
        return ['findings' => $findings, 'coverage' => ['status' => 'unknown', 'matches' => count($findings),
            'reason' => 'matches alone do not prove selected templates executed or target coverage']];
    }
}
