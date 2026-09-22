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
$notApplicable = ToolResult::notApplicable('composer-audit', 'sem lock');
$unavailable = ToolResult::unavailable('semgrep', 'binario ausente');
$run = new RunResult('check', [$ok, $skipped]);

assert($ok->state === ToolResult::OK);
assert($ok->findings === [$finding]);
assert($run->findings() === [$finding]);
assert($notApplicable->exitCode === null);
assert($unavailable->isFailure());

$data = json_decode(json_encode($run, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
assert(($data['operation'] ?? null) === 'check');
assert(($data['tools'][0]['duration_ms'] ?? null) === 15);
assert(($data['findings'][0]['rule'] ?? null) === 'argument.type');
assert(($data['findings'][0]['confidence'] ?? null) === 'high');
assert(($data['findings'][0]['evidence_type'] ?? null) === 'static-analysis');
assert(($data['findings'][0]['metadata']['category'] ?? null) === 'quality');

$failed = ToolResult::completed('phpstan', 2);
$laterFailure = ToolResult::completed('psalm', 3);
$failedRun = new RunResult('check', [$ok, $failed, $laterFailure]);
assert($failedRun->finalExitCode === 2);

$invalidToolResults = [
    static fn (): ToolResult => new ToolResult('phpstan', ToolResult::OK, null),
    static fn (): ToolResult => new ToolResult('phpstan', ToolResult::OK, 1),
    static fn (): ToolResult => new ToolResult('phpstan', ToolResult::FAILED, 0),
    static fn (): ToolResult => new ToolResult('phpstan', ToolResult::ERROR, 0),
    static fn (): ToolResult => new ToolResult('test', ToolResult::SKIPPED, 1),
    static fn (): ToolResult => ToolResult::completed('phpstan', 0, [], -1),
];
foreach ($invalidToolResults as $invalidToolResult) {
    try {
        $invalidToolResult();
        assert(false, 'ToolResult contraditório deveria ser rejeitado.');
    } catch (InvalidArgumentException) {
    }
}

try {
    new RunResult('', []);
    assert(false, 'RunResult sem operação deveria ser rejeitado.');
} catch (InvalidArgumentException) {
}

echo "[OK] Finding, ToolResult e RunResult possuem contratos estruturados e serializáveis.\n";
