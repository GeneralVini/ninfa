<?php
/** Limpeza independente de capturas: php scripts/cleanup-security-evidence.php /diretorio/da/store. */
declare(strict_types=1);
require_once dirname(__DIR__) . '/src/EvidenceStore.php';
if ($argc !== 2 || !is_dir($argv[1])) {
    fwrite(STDERR, "Informe uma store existente.\n");
    exit(1);
}
try {
    $store = new EvidenceStore($argv[1]);
    $store->cleanup();
    EvidenceStore::cleanupReports($argv[1]);
    echo "Retenção aplicada a envelopes e snapshots de segurança.\n";
} catch (Throwable $error) {
    fwrite(STDERR, EvidenceSanitizer::text($error->getMessage()) . PHP_EOL);
    exit(1);
}
