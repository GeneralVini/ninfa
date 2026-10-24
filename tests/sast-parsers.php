<?php

declare(strict_types=1);

/** Verifica normalização SAST positiva, negativa e cobertura Semgrep sem scanners reais. */

require_once dirname(__DIR__) . '/src/PsalmTaintParser.php';
require_once dirname(__DIR__) . '/src/SemgrepParser.php';

$root = '/project';
$psalm = json_encode([[
    'severity' => 'error',
    'type' => 'TaintedShell',
    'message' => 'Detected tainted shell code',
    'file_name' => '/project/src/Action.php',
    'line_from' => 18,
    'selected_text' => 'exec($input)',
    'taint_trace' => [
        ['label' => 'input', 'file_name' => '/project/src/Action.php', 'line_from' => 10],
        ['label' => 'exec', 'file_name' => '/project/src/Action.php', 'line_from' => 18],
    ],
]], JSON_THROW_ON_ERROR);
$psalmFindings = PsalmTaintParser::parse($psalm, $root);
assert(count($psalmFindings) === 1);
assert($psalmFindings[0]->file === 'src/Action.php');
assert($psalmFindings[0]->evidenceType === 'sast-dataflow');
assert($psalmFindings[0]->confidence === 'high');
assert(count($psalmFindings[0]->metadata['trace'] ?? []) === 2);
assert(PsalmTaintParser::parse('[]', $root) === []);

// Objeto vazio ou issue ilegível não comprova análise sem achados.
foreach (['{}', '[null]', '[{}]'] as $invalid) {
    try {
        PsalmTaintParser::parse($invalid, $root);
        assert(false, 'Psalm deve rejeitar documento ou issue inválida.');
    } catch (UnexpectedValueException) {
    }
}
$diagnostic = PsalmTaintParser::parse('[{"type":"UndefinedClass","file_name":"Action.php","file_path":"/project/src/Action.php","line_from":2,"line_to":3,"column_from":4,"column_to":8}]', $root)[0];
assert($diagnostic->evidenceType === 'sast-diagnostic');
assert($diagnostic->file === 'src/Action.php');
assert(($diagnostic->metadata['column_from'] ?? null) === 4);
assert(($diagnostic->metadata['confirmation'] ?? null) === 'unverified');

$semgrep = json_encode([
    'results' => [[
        'check_id' => 'ninfa.php.command-injection.dangerous-primitive',
        'path' => '/project/src/Action.php',
        'start' => ['line' => 18],
        'end' => ['line' => 18],
        'extra' => [
            'message' => 'Direct shell execution',
            'severity' => 'WARNING',
            'metadata' => ['confidence' => 'HIGH', 'evidence' => 'DANGEROUS_PRIMITIVE'],
        ],
    ]],
    'errors' => [],
    'paths' => ['scanned' => ['/project/src/Action.php'], 'skipped' => []],
], JSON_THROW_ON_ERROR);
$parsed = SemgrepParser::parse($semgrep, $root);
assert(count($parsed['findings']) === 1);
assert($parsed['findings'][0]->file === 'src/Action.php');
assert($parsed['findings'][0]->evidenceType === 'sast-pattern');
assert($parsed['findings'][0]->confidence === 'high');
assert(($parsed['coverage']['status'] ?? null) === 'complete');
assert(($parsed['coverage']['scanned_files'] ?? null) === 1);

$negative = SemgrepParser::parse('{"results":[],"errors":[],"paths":{"scanned":["safe.php"],"skipped":[]}}', $root);
assert($negative['findings'] === []);
assert(($negative['coverage']['status'] ?? null) === 'complete');

$policySkip = SemgrepParser::parse(
    '{"results":[],"errors":[],"paths":{"scanned":["src/App.php"],"skipped":[{"path":"vendor/pkg/File.php","reason":"cli_include_flags_do_not_match"},{"path":"public/assets/app.php","reason":"excluded by --exclude"}]}}',
    $root,
);
assert(($policySkip['coverage']['status'] ?? null) === 'complete');
assert(count($policySkip['coverage']['excluded_by_policy'] ?? []) === 2);
assert(($policySkip['coverage']['unexpected_skips'] ?? []) === []);

$unexpectedSkip = SemgrepParser::parse(
    '{"results":[],"errors":[],"paths":{"scanned":[],"skipped":[{"path":"src/Broken.php","reason":"analysis timeout"}]}}',
    $root,
);
assert(($unexpectedSkip['coverage']['status'] ?? null) === 'partial');
assert(count($unexpectedSkip['coverage']['unexpected_skips'] ?? []) === 1);

$partial = SemgrepParser::parse('{"results":[],"errors":[{"type":"Parse error"}],"paths":{"scanned":[],"skipped":[{"path":"broken.php"}]}}', $root);
assert(($partial['coverage']['status'] ?? null) === 'partial');
assert(count($partial['errors']) === 1);

foreach (['{"results":[]}', '{"results":[],"paths":{"scanned":"invalid"}}'] as $missingCoverage) {
    assert(SemgrepParser::parse($missingCoverage, $root)['coverage']['status'] === 'partial');
}
$vendorFailure = SemgrepParser::parse('{"results":[],"paths":{"scanned":[],"skipped":[{"path":"vendor/pkg/Broken.php","reason":"parse error"}]}}', $root);
assert($vendorFailure['coverage']['status'] === 'partial');
$unknownVendorSkip = SemgrepParser::parse('{"results":[],"paths":{"scanned":[],"skipped":[{"path":"vendor/pkg/Unknown.php","reason":"unknown"}]}}', $root);
assert($unknownVendorSkip['coverage']['status'] === 'partial');
foreach (['{"results":[null]}', '{"results":[{}]}', '{"results":[],"errors":"invalid"}'] as $invalid) {
    try {
        SemgrepParser::parse($invalid, $root);
        assert(false, 'Semgrep deve rejeitar resultado inválido.');
    } catch (UnexpectedValueException) {
    }
}

// Coleções inválidas não podem desaparecer do cálculo de cobertura.
foreach ([
    '{"results":{},"paths":{"scanned":[]}}',
    '{"results":[],"paths":{"scanned":[null]}}',
    '{"results":[],"paths":{"scanned":[],"skipped":"invalid"}}',
    '{"results":[],"paths":{"scanned":[]},"skipped_rules":[{"rule_id":"missing"}]}',
] as $invalidCoverage) {
    try {
        $candidate = SemgrepParser::parse($invalidCoverage, $root);
        assert($candidate['coverage']['status'] === 'partial', 'Cobertura inválida ou regras puladas não são completas.');
    } catch (UnexpectedValueException) {
    }
}
$severityDocument = json_decode($semgrep, true, 512, JSON_THROW_ON_ERROR);
unset($severityDocument['results'][0]['extra']['severity']);
try {
    SemgrepParser::parse(json_encode($severityDocument, JSON_THROW_ON_ERROR), $root);
    assert(false, 'Severidade ausente não pode ser rebaixada para WARNING.');
} catch (UnexpectedValueException) {
}

echo "[OK] Psalm Taint e Semgrep normalizam findings e distinguem exclusões de cobertura parcial.\n";
