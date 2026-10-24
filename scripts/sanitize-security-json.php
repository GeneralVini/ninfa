<?php
/** Sanitiza um documento estruturado pelo mesmo componente usado nos relatórios. */
declare(strict_types=1);
require_once dirname(__DIR__) . '/src/EvidenceSanitizer.php';
$value = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
echo json_encode(EvidenceSanitizer::value($value), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
