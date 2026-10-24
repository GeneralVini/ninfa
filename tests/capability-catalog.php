<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/CapabilityCatalog.php';

$root = dirname(__DIR__);
$document = CapabilityCatalog::load($root)->jsonSerialize();
assert(count($document['capabilities']) === 52);
assert(count($document['relationships']) === 8);
$owners = array_unique(array_column($document['capabilities'], 'owner'));
sort($owners);
assert($owners === ['composer-audit', 'nuclei', 'osv', 'psalm', 'semgrep', 'zap']);
$byId = array_column($document['capabilities'], null, 'id');
assert(count($byId['nuclei.security-headers-bundle']['selectors'][0]['matchers']) === 12);
assert($byId['zap.active.reflected-xss']['selectors'][0]['id'] === '40012');
assert($byId['zap.passive.cookie-httponly']['selectors'][0]['id'] === '10010');
assert(count(array_filter($document['relationships'], fn ($r) => $r['status'] === 'candidate')) === 4);

// O inventário revisado deve enumerar todas as regras locais, inclusive overlays.
$declared = [];
foreach (glob($root . '/security/semgrep/*.yml') as $file) {
    preg_match_all('/^  - id: (.+)$/m', (string) file_get_contents($file), $matches);
    $declared = [...$declared, ...$matches[1]];
}
foreach (glob($root . '/security/semgrep/profiles/*.yml') as $file) {
    preg_match_all('/^  - id: (.+)$/m', (string) file_get_contents($file), $matches);
    $declared = [...$declared, ...$matches[1]];
}
$selected = [];
foreach ($document['capabilities'] as $capability) {
    if ($capability['owner'] === 'semgrep') {
        $selected[] = $capability['selectors'][0]['id'];
    }
}
sort($declared);
sort($selected);
assert($declared === $selected);

// Uma cópia pequena isola os testes de deriva sem modificar fontes do projeto.
$temporary = sys_get_temp_dir() . '/ninfa-catalog-' . bin2hex(random_bytes(8));
mkdir($temporary, 0700);
try {
    $paths = ['security/web-tools.lock.json'];
    foreach ($document['artifacts'] as $artifact) {
        if ($artifact['origin'] === 'repository') {
            $paths[] = $artifact['path'];
        }
    }
    foreach ($paths as $path) {
        if (!is_dir(dirname($temporary . '/' . $path))) {
            mkdir(dirname($temporary . '/' . $path), 0700, true);
        }
        copy($root . '/' . $path, $temporary . '/' . $path);
    }
    $write = static function (array $data) use ($temporary): void {
        file_put_contents($temporary . '/security/capabilities.v1.json', json_encode($data, JSON_THROW_ON_ERROR));
    };
    $reject = static function (array $data) use ($temporary, $write): void {
        $write($data);
        try {
            CapabilityCatalog::load($temporary);
        } catch (UnexpectedValueException) {
            return;
        }
        throw new RuntimeException('Catálogo inválido foi aceito.');
    };
    $write($document);
    assert(CapabilityCatalog::load($temporary)->jsonSerialize() === $document);

    $bad = $document;
    $bad['schema_version'] = 2;
    $reject($bad);
    $bad = $document;
    $bad['capabilities'][] = $bad['capabilities'][0];
    $reject($bad);
    $bad['capabilities'][count($bad['capabilities']) - 1]['id'] = 'duplicate-native-selector';
    $reject($bad);
    $bad = $document;
    $bad['capabilities'][0]['selectors'][0]['id'] = 'invented-rule';
    $reject($bad);
    $bad = $document;
    $bad['capabilities'][0]['owner'] = 'nuclei';
    $bad['capabilities'][0]['selectors'][0]['kind'] = 'nuclei-template';
    $reject($bad);
    $bad = $document;
    $bad['capabilities'][0]['conditions'] = [];
    $reject($bad);
    $bad = $document;
    $bad['capabilities'][0]['alternative']['capability'] = 'psalm.TaintedHtml';
    $reject($bad);
    $bad = $document;
    $bad['relationships'][0]['suppress'] = true;
    $reject($bad);
    $bad = $document;
    $bad['relationships'][0]['status'] = 'equivalent';
    $reject($bad);
    $bad = $document;
    $bad['relationships'][0]['right'] = 'absent';
    $reject($bad);
    $bad = $document;
    $reverse = $bad['relationships'][0];
    [$reverse['left'], $reverse['right']] = [$reverse['right'], $reverse['left']];
    $bad['relationships'][] = $reverse;
    $reject($bad);
    $bad = $document;
    $bad['tools']['zap']['version'] = 'unreviewed';
    $reject($bad);
    $bad = $document;
    unset($bad['tools']['zap']);
    $reject($bad);
    $bad = $document;
    $bad['policy']['unlisted_rules'] = 'skip';
    $reject($bad);
    $bad = $document;
    $bad['policy']['equivalence'] = 'Automatically suppress same-CWE tests';
    $reject($bad);
    $bad = $document;
    $key = 'nuclei-templates:http/misconfiguration/http-missing-security-headers.yaml';
    $bad['artifacts'][$key]['sha256'] = str_repeat('0', 64);
    $reject($bad);
    $bad = $document;
    $bad['artifacts']['security/semgrep/common.yml']['path'] = '../outside.yml';
    $reject($bad);

    file_put_contents($temporary . '/security/semgrep/common.yml', "\n# drift\n", FILE_APPEND);
    $reject($document);
    copy($root . '/security/semgrep/common.yml', $temporary . '/security/semgrep/common.yml');
    file_put_contents($temporary . '/security/web-tools.lock.json', "\n", FILE_APPEND);
    $reject($document);
} finally {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temporary, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($temporary);
}

echo "[OK] SEC-04.01: 52 capacidades, seis fontes, ownership, deriva e bloqueio de supressão validados.\n";
