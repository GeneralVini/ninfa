<?php
/** Casos sintéticos de falha e precisão, complementados pelas capturas reais. */
declare(strict_types=1);
require_once __DIR__ . '/../src/ZapParser.php';
require_once __DIR__ . '/../src/NucleiParser.php';
$zap = ['site' => [['alerts' => [['pluginid' => '10020', 'name' => 'Missing header', 'riskcode' => '2', 'confidence' => '2', 'instances' => [['uri' => 'http://localhost/one', 'method' => 'GET', 'param' => 'q'], ['uri' => 'http://localhost/two', 'method' => 'POST']]]]]]];
$parsed = ZapParser::parse(json_encode($zap));
assert(count($parsed['findings']) === 1);
assert(count($parsed['findings'][0]->metadata['instances']) === 2);
assert($parsed['coverage']['status'] === 'unknown');
assert($parsed['findings'][0]->metadata['confirmation'] === 'unverified');
assert(ZapParser::parse('{"site":[]}')['coverage']['status'] === 'unknown');
$event = ['template-id' => 'fixture', 'info' => ['name' => 'Fixture', 'severity' => 'high', 'classification' => ['cwe-id' => ['CWE-79']]], 'type' => 'http', 'matched-at' => 'http://localhost/test', 'matcher-name' => 'marker'];
$match = NucleiParser::parse(json_encode($event));
assert($match['findings'][0]->metadata['matcher'] === 'marker');
assert($match['findings'][0]->confidence === null);
assert(NucleiParser::parse('')['coverage']['status'] === 'unknown');
foreach (['{}', '[]', '{"site":{}}', '{"alerts":[{}]}', '{"site":[{"alerts":{}}]}'] as $bad) {
    try { ZapParser::parse($bad); assert(false, 'bad ZAP accepted'); } catch (UnexpectedValueException) {}
}
foreach (['{}', '[]', '{"error":"timeout"}', json_encode($event) . "\ninvalid", json_encode(array_replace($event, ['matcher-status' => false]))] as $bad) {
    try { NucleiParser::parse($bad); assert(false, 'bad Nuclei accepted'); } catch (UnexpectedValueException|JsonException) {}
}
echo "[OK] DAST parsers: instâncias, matchers, erros e cobertura desconhecida.\n";
