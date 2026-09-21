<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/RunResult.php';

$finding = new Finding(
    tool: 'phpstan',
    file: 'src/Example.php',
    line: 12,
    rule: 'argument.type',
    problem: 'Tipo incompatível',
    correction: 'Corrigir na origem',
    severity: 'medium',
    confidence: 'high',
    evidenceType: 'static-analysis',
    provenance: ['phpstan'],
    metadata: ['category' => 'quality'],
);

$ok = ToolResult::completed('phpstan', 0, [$finding], 15);
$skipped = ToolResult::skipped('test', 'nao aplicavel');
$run = new RunResult('check', [$ok, $skipped], 0);

assert($ok->state === ToolResult::OK);
assert($ok->findings === [$finding]);
assert($run->findings() === [$finding]);

$data = json_decode(json_encode($run, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
assert(($data['operation'] ?? null) === 'check');
assert(($data['tools'][0]['duration_ms'] ?? null) === 15);
assert(($data['findings'][0]['rule'] ?? null) === 'argument.type');
assert(($data['findings'][0]['confidence'] ?? null) === 'high');
assert(($data['findings'][0]['evidence_type'] ?? null) === 'static-analysis');
assert(($data['findings'][0]['metadata']['category'] ?? null) === 'quality');

echo "[OK] Finding, ToolResult e RunResult possuem contratos estruturados e serializáveis.\n";
