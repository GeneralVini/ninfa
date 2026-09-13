<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/SemanticHints.php';

$root = sys_get_temp_dir() . '/ninfa-semantic-hints-' . bin2hex(random_bytes(4));

try {
    mkdir($root . '/docs', 0775, true);
    file_put_contents(
        $root . '/README.md',
        "# Plugin GLPI\nUsa `PluginExampleTicket` e `plugin_init_example()` no GLPI 11.\n",
    );
    file_put_contents(
        $root . '/AGENTS.md',
        "Trate `TicketRepository` como servico principal e preserve `DBmysql`.\n",
    );
    file_put_contents(
        $root . '/docs/ARCH.md',
        "O fluxo passa por `TicketRepository` e `PluginExampleTicket`.\n",
    );

    $hints = SemanticHints::fromProject($root);

    assert(in_array('README.md', $hints->files(), true));
    assert(in_array('AGENTS.md', $hints->files(), true));
    assert(in_array('docs/ARCH.md', $hints->files(), true));
    assert(in_array('PluginExampleTicket', $hints->symbols(), true));
    assert(in_array('plugin_init_example', $hints->symbols(), true));
    assert(in_array('TicketRepository', $hints->symbols(), true));
    assert(in_array('DBmysql', $hints->symbols(), true));
    assert($hints->profileSignals()['glpi-plugin'] > 0);

    echo "[OK] README.md, AGENTS.md e docs enriquecem a semantica de simbolos.\n";
} finally {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    if (is_dir($root)) {
        rmdir($root);
    }
}
