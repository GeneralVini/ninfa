<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/SecurityScanPlan.php';

$catalog = CapabilityCatalog::load(dirname(__DIR__));
$catalogData = $catalog->jsonSerialize();
$caps = array_column($catalogData['capabilities'], null, 'id');
$hash = static fn (string $value): string => hash('sha256', $value);
$proposal = static function (string $id) use ($catalog, $caps, $hash): array {
    $cap = $caps[$id];
    $owner = $cap['owner'];
    $web = in_array($owner, ['zap', 'nuclei'], true);
    $sca = in_array($owner, ['composer-audit', 'osv'], true);
    $passive = $cap['technique'] === 'passive-response';
    $versions = ['zap' => '2.17.0', 'nuclei' => '3.11.1', 'semgrep' => '1.177.0', 'psalm' => '6.20.1', 'composer-audit' => '2.9.5', 'osv' => 'v1'];
    return ['capability' => $id, 'applicability_verified' => true, 'selection_control' => 'exact', 'limits_verified' => true,
        'input' => [
            'run_id' => str_repeat('a', 32),
            'context' => ['project_digest' => $hash('synthetic-project'), 'scope_digest' => $hash('scope'),
                'authorization_digest' => $hash('authorization'), 'code_digest' => $hash('source-snapshot'),
                'deployment_digest' => $web ? $hash('verified-deploy') : null,
                'deployment_link' => $web ? 'verified' : 'not-applicable', 'host_digest' => null,
                'profile' => 'php-generic', 'role_digest' => $web ? $hash('public-role') : null,
                'session_context_digest' => $web ? $hash('stateless-context') : null],
            'asset' => ['kind' => $web ? ($passive ? 'http-response' : 'http-target') : ($sca ? 'component-inventory' : 'codebase'),
                'digest' => $hash($web ? 'synthetic-web-asset' : 'source-asset')],
            'request' => ['method' => $web ? 'GET' : null,
                'parameter_location' => $cap['technique'] === 'active-injection' ? 'query' : null,
                'parameter_digest' => $cap['technique'] === 'active-injection' ? $hash('parameter-q') : null],
            'bindings' => ['engine_version' => $versions[$owner], 'engine_digest' => $hash($owner),
                'runtime_digest' => $hash('runtime'), 'configuration_digest' => $hash('effective-configuration'),
                'rules_digest' => SecurityTestIdentity::rulesDigest($catalog, $id),
                'data_digest' => $sca ? $hash('advisory-snapshot') : null, 'conditions_digest' => $hash('verified-conditions'),
                'payload_family_digest' => in_array($cap['technique'], ['active-injection', 'http-specific-cve'], true) ? $hash('payload-family') : null],
        ]];
};
$build = static fn (array $requests, string $scope = 'web-full'): array => SecurityScanPlan::build($catalog, $scope, $requests)->jsonSerialize();
$html = $proposal('psalm.TaintedHtml');
$sql = $proposal('psalm.TaintedSql');
$plan = $build([$html, $sql, $html]);
assert(count($plan['executions']) === 1);
assert(count($plan['executions'][0]['units']) === 2);
assert($plan['executions'][0]['selection_mode'] === 'whole-program');
assert($plan['excluded'][0]['reason'] === 'identical-verifiable-unit');
assert($plan['excluded'][0]['covered_by'] === $plan['excluded'][0]['identity']['key']);
assert($plan['execution_status'] === 'not-started');
assert(!$plan['source_selection_complete']);
assert(in_array('indivisible-engine-analysis', array_column($plan['warnings'], 'code'), true));
assert($plan['plan_id'] === $build([$html, $html, $sql])['plan_id']);

$differentRole = $proposal('zap.active.reflected-xss');
$otherRole = $differentRole;
$otherRole['input']['context']['role_digest'] = $hash('administrator');
$plan = $build([$differentRole, $otherRole]);
assert(count($plan['executions']) === 2);
$differentParameter = $differentRole;
$differentParameter['input']['request']['parameter_digest'] = $hash('another-parameter');
assert(count($build([$differentRole, $differentParameter])['executions']) === 2);

$unknown = $html;
$unknown['input']['context']['code_digest'] = null;
$plan = $build([$unknown, $unknown]);
assert(count($plan['executions']) === 2 && $plan['excluded'] === []);
assert(in_array('identity-unverifiable', array_column($plan['warnings'], 'code'), true));

$header = $proposal('zap.passive.csp');
$bundle = $proposal('nuclei.security-headers-bundle');
$plan = $build([$header, $bundle]);
assert(count($plan['executions']) === 2);
assert($plan['cross_engine_equivalence_exclusions'] === [] && $plan['excluded'] === []);
assert(in_array('uncontrolled-overlap', array_column($plan['warnings'], 'code'), true));
assert(count($plan['executions'][1]['units'][0]['selectors'][0]['matchers']) === 12);

$common = $proposal('ninfa.php.sql-injection.request-to-query');
$overlay = $proposal('ninfa.yii2.sql-injection.request-to-create-command');
$plan = $build([$common, $overlay], 'code');
assert($plan['excluded'][0]['reason'] === 'profile-not-applicable');
assert($plan['excluded'][0]['evidence']['profile'] === 'php-generic');
$common['input']['context']['profile'] = 'yii2';
$overlay['input']['context']['profile'] = 'yii2';
$plan = $build([$common, $overlay], 'code');
assert(count($plan['executions']) === 1 && count($plan['executions'][0]['units']) === 2);

$unsafe = $differentRole;
$unsafe['limits_verified'] = false;
$plan = $build([$unsafe]);
assert($plan['executions'] === [] && $plan['required_sources']['zap'] === 'blocked');
assert($plan['blocked'][0]['reasons'] === ['dast-limits-unverified']);
$unsafe = $html;
$unsafe['selection_control'] = 'uncontrolled';
$plan = $build([$unsafe]);
assert($plan['executions'] === []);
assert(in_array('uncontrolled-overlap', array_column($plan['warnings'], 'code'), true));
$unsafe = $bundle;
$unsafe['applicability_verified'] = false;
assert($build([$unsafe])['blocked'][0]['reasons'] === ['applicability-unverified']);
$plan = $build([$header], 'code');
assert($plan['executions'] === [] && $plan['excluded'][0]['reason'] === 'outside-code-scope');
assert(!isset($plan['required_sources']['zap']));

// Um plano completo em seleção continua sem afirmar execução/cobertura concluída.
$baseline = array_map($proposal, ['composer.advisories', 'osv.packagist', 'ninfa.php.xss.request-to-output',
    'psalm.TaintedHtml', 'zap.passive.csp', 'zap.active.reflected-xss', 'nuclei.CVE-2023-23752']);
$fullPlan = $build($baseline);
assert($fullPlan['source_selection_complete']);
assert(count($fullPlan['required_sources']) === 6 && count($fullPlan['executions']) === 7);
assert(count($fullPlan['unrequested_capabilities']) === 45);
assert(array_column($fullPlan['executions'], 'phase') === ['sca', 'sca', 'sast', 'sast', 'zap-passive', 'zap-active', 'nuclei-http']);
assert($fullPlan['plan_id'] === $build(array_reverse($baseline))['plan_id']);
assert(in_array('complementary-overlap', array_column($fullPlan['warnings'], 'code'), true));
$partial = $build([]);
assert(!$partial['source_selection_complete'] && $partial['run_id'] === null);
assert(count($partial['warnings']) === 6);

$reject = static function (array $requests) use ($build): void {
    try {
        $build($requests);
    } catch (InvalidArgumentException) {
        return;
    }
    throw new RuntimeException('Proposta inválida foi aceita.');
};
$otherRun = $html;
$otherRun['input']['run_id'] = str_repeat('b', 32);
$reject([$html, $otherRun]);
$otherProject = $html;
$otherProject['input']['context']['project_digest'] = $hash('other-project');
$reject([$html, $otherProject]);
$bad = $html;
unset($bad['limits_verified']);
$reject([$bad]);
$bad = $html;
$bad['selection_control'] = true;
$reject([$bad]);
$bad = $html;
$bad['capability'] = 'unlisted-rule';
$reject([$bad]);

echo "[OK] SEC-04.03: seleção, agrupamento, exclusões justificadas, bloqueios, seis fontes e avisos de sobreposição.\n";
