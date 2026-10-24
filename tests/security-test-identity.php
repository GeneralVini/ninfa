<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/SecurityTestIdentity.php';

$catalog = CapabilityCatalog::load(dirname(__DIR__));
$digest = static fn (string $value): string => hash('sha256', $value);
$capability = 'zap.active.reflected-xss';
$input = [
    'run_id' => str_repeat('a', 32),
    'context' => [
        'project_digest' => $digest('project'), 'scope_digest' => $digest('scope'),
        'authorization_digest' => $digest('authorization'), 'code_digest' => $digest('dirty-code-and-dependencies'),
        'deployment_digest' => $digest('verified-deploy'), 'deployment_link' => 'verified',
        'host_digest' => null, 'profile' => 'php-generic',
        'role_digest' => $digest('public-role'), 'session_context_digest' => $digest('stateless-context'),
    ],
    'asset' => ['kind' => 'http-target', 'digest' => $digest('origin-vhost-path-target')],
    'request' => ['method' => 'GET', 'parameter_location' => 'query', 'parameter_digest' => $digest('parameter-q')],
    'bindings' => [
        'engine_version' => '2.17.0', 'engine_digest' => $digest('zap-binary-and-addons'),
        'runtime_digest' => $digest('java-platform'), 'configuration_digest' => $digest('policy-strength-threshold'),
        'rules_digest' => SecurityTestIdentity::rulesDigest($catalog, $capability),
        'data_digest' => null, 'conditions_digest' => $digest('effective-applicability'),
        'payload_family_digest' => $digest('xss-payload-family'),
    ],
];
$create = static fn (array $data, string $id = 'zap.active.reflected-xss'): SecurityTestIdentity => SecurityTestIdentity::create($catalog, $id, $data);
$original = $create($input);
assert($original->verifiable);
assert($original->sameVerifiableUnit($create($input)));
$reordered = array_reverse($input, true);
foreach ($reordered as $group => $value) {
    if (is_array($value)) {
        $reordered[$group] = array_reverse($value, true);
    }
}
assert($original->key === $create($reordered)->key);
$anotherRun = $input;
$anotherRun['run_id'] = str_repeat('b', 32);
assert($original->sameVerifiableUnit($create($anotherRun)));

// Cada dimensão pertinente muda a identidade, mesmo mantendo a mesma URL lógica/CWE.
$changes = [
    ['context', 'project_digest'], ['context', 'scope_digest'], ['context', 'authorization_digest'],
    ['context', 'code_digest'], ['context', 'deployment_digest'], ['context', 'host_digest'],
    ['context', 'role_digest'], ['context', 'session_context_digest'], ['asset', 'digest'],
    ['request', 'parameter_digest'], ['bindings', 'engine_digest'], ['bindings', 'runtime_digest'],
    ['bindings', 'configuration_digest'], ['bindings', 'data_digest'],
    ['bindings', 'conditions_digest'], ['bindings', 'payload_family_digest'],
];
foreach ($changes as [$group, $field]) {
    $changed = $input;
    $changed[$group][$field] = $digest('different-' . $field);
    assert(!$original->sameVerifiableUnit($create($changed)), $group . '.' . $field);
}
foreach (['method' => 'POST', 'parameter_location' => 'body'] as $field => $value) {
    $changed = $input;
    $changed['request'][$field] = $value;
    assert(!$original->sameVerifiableUnit($create($changed)));
}
$changed = $input;
$changed['request']['method'] = 'get';
assert($original->key !== $create($changed)->key); // Métodos HTTP são case-sensitive.
$changed = $input;
$changed['context']['profile'] = 'yii2';
assert(!$original->sameVerifiableUnit($create($changed)));

// Fatos incompletos nunca sustentam deduplicação, nem dentro do mesmo run.
$unknowns = [
    ['context', 'code_digest', null], ['context', 'deployment_digest', null],
    ['context', 'deployment_link', 'declared'], ['context', 'deployment_link', 'unknown'],
    ['context', 'role_digest', null], ['context', 'session_context_digest', null],
    ['request', 'method', null], ['bindings', 'payload_family_digest', null],
];
foreach ($unknowns as [$group, $field, $value]) {
    $unknown = $input;
    $unknown[$group][$field] = $value;
    $first = $create($unknown);
    $second = $create($unknown);
    assert(!$first->verifiable && $first->limitations !== []);
    assert($first->key !== $second->key);
    assert(!$first->sameVerifiableUnit($first));
    assert(!$first->sameVerifiableUnit($second));
}
$unknown = $input;
$unknown['request']['parameter_location'] = null;
$unknown['request']['parameter_digest'] = null;
assert(!$create($unknown)->verifiable);
$unknown = $input;
$unknown['context']['profile'] = 'glpi-plugin';
assert(!$create($unknown)->verifiable);
$unknown['context']['host_digest'] = $digest('glpi11-host-revision-and-mount');
assert($create($unknown)->verifiable);

// As identidades de cobertura não autorizam separar execuções indivisíveis do motor.
$static = $input;
$static['context']['deployment_digest'] = null;
$static['context']['deployment_link'] = 'not-applicable';
$static['context']['role_digest'] = null;
$static['context']['session_context_digest'] = null;
$static['asset']['kind'] = 'codebase';
$static['request'] = ['method' => null, 'parameter_location' => null, 'parameter_digest' => null];
$static['bindings']['engine_version'] = '6.20.1';
$static['bindings']['payload_family_digest'] = null;
$static['bindings']['rules_digest'] = SecurityTestIdentity::rulesDigest($catalog, 'psalm.TaintedHtml');
$html = $create($static, 'psalm.TaintedHtml');
assert($html->verifiable);
$static['bindings']['rules_digest'] = SecurityTestIdentity::rulesDigest($catalog, 'psalm.TaintedSql');
assert(!$html->sameVerifiableUnit($create($static, 'psalm.TaintedSql')));

$semgrep = $static;
$semgrep['bindings']['engine_version'] = '1.177.0';
$semgrep['bindings']['rules_digest'] = SecurityTestIdentity::rulesDigest($catalog, 'ninfa.php.xss.request-to-output');
assert($create($semgrep, 'ninfa.php.xss.request-to-output')->verifiable);
assert(!$html->sameVerifiableUnit($create($semgrep, 'ninfa.php.xss.request-to-output')));

$sca = $static;
$sca['asset']['kind'] = 'component-inventory';
$sca['bindings']['engine_version'] = '2.9.5';
$sca['bindings']['rules_digest'] = SecurityTestIdentity::rulesDigest($catalog, 'composer.advisories');
assert(!$create($sca, 'composer.advisories')->verifiable);
$sca['bindings']['data_digest'] = $digest('advisory-snapshot');
$composer = $create($sca, 'composer.advisories');
assert($composer->verifiable);
$sca['bindings']['engine_version'] = '2.9.6';
assert(!$composer->sameVerifiableUnit($create($sca, 'composer.advisories')));
$sca['bindings']['engine_version'] = 'v1';
$sca['bindings']['rules_digest'] = SecurityTestIdentity::rulesDigest($catalog, 'osv.packagist');
assert(!$composer->sameVerifiableUnit($create($sca, 'osv.packagist')));

$cookie = $input;
$cookie['asset']['kind'] = 'http-response';
$cookie['request']['parameter_location'] = 'cookie';
$cookie['request']['parameter_digest'] = $digest('cookie-name-domain-path-no-value');
$cookie['bindings']['rules_digest'] = SecurityTestIdentity::rulesDigest($catalog, 'zap.passive.cookie-httponly');
$cookie['bindings']['payload_family_digest'] = null;
assert($create($cookie, 'zap.passive.cookie-httponly')->verifiable);
$cookie['request']['parameter_location'] = null;
$cookie['request']['parameter_digest'] = null;
assert(!$create($cookie, 'zap.passive.cookie-httponly')->verifiable);

$nuclei = $input;
$nuclei['bindings']['engine_version'] = '3.11.1';
$nuclei['bindings']['payload_family_digest'] = null;
$nuclei['bindings']['rules_digest'] = SecurityTestIdentity::rulesDigest($catalog, 'nuclei.security-headers-bundle');
$nuclei['request']['parameter_location'] = null;
$nuclei['request']['parameter_digest'] = null;
assert($create($nuclei, 'nuclei.security-headers-bundle')->verifiable);
$nuclei['bindings']['rules_digest'] = SecurityTestIdentity::rulesDigest($catalog, 'nuclei.CVE-2023-23752');
assert(!$create($nuclei, 'nuclei.CVE-2023-23752')->verifiable);
$nuclei['bindings']['payload_family_digest'] = $digest('reviewed-cve-requests');
assert($create($nuclei, 'nuclei.CVE-2023-23752')->verifiable);

$reject = static function (array $data, string $id = 'zap.active.reflected-xss') use ($create): void {
    try {
        $create($data, $id);
    } catch (InvalidArgumentException) {
        return;
    }
    throw new RuntimeException('Identidade inválida foi aceita.');
};
$reject($input, 'unreviewed-template');
foreach ([
    ['bindings', 'rules_digest', $digest('other-rule')], ['bindings', 'engine_version', '2.18.0'],
    ['context', 'scope_digest', 'not-a-digest'], ['context', 'profile', 'unsupported'],
    ['asset', 'kind', 'http-response'], ['request', 'method', "GET\r\nX-Test: value"],
    ['request', 'parameter_location', 'unrecognized'], ['request', 'parameter_digest', null],
] as [$group, $field, $value]) {
    $bad = $input;
    $bad[$group][$field] = $value;
    $reject($bad);
}
$bad = $input;
$bad['context']['password'] = 'SECRET-TEST-NEVER-SERIALIZE';
$reject($bad);
$bad = $input;
$bad['headers'] = ['Authorization' => 'SECRET-TEST-NEVER-SERIALIZE'];
$reject($bad);
$bad = $input;
unset($bad['bindings']['runtime_digest']);
$reject($bad);
assert(!str_contains(json_encode($original, JSON_THROW_ON_ERROR), 'SECRET-TEST'));
assert(!str_contains(json_encode($original, JSON_THROW_ON_ERROR), $input['run_id']));

echo "[OK] SEC-04.02: identidade canônica, contexto, vetores, hashes, seis fontes e isolamento de dados desconhecidos.\n";
