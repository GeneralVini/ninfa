<?php

$command = $_GET['command'];
// ruleid: ninfa.php.command-injection.request-to-shell,ninfa.php.command-injection.dangerous-primitive
exec($command);

// ruleid: ninfa.php.command-injection.dangerous-primitive
system('uptime');

$sql = 'SELECT * FROM users WHERE id = ' . $_GET['id'];
// ruleid: ninfa.php.sql-injection.request-to-query
$pdo->query($sql);

$name = $_POST['name'];
// ruleid: ninfa.php.xss.request-to-output
echo $name;

// ok: ninfa.php.xss.request-to-output
echo htmlspecialchars($_POST['name'], ENT_QUOTES, 'UTF-8');

$path = $_GET['path'];
// ruleid: ninfa.php.path-traversal.request-to-filesystem
file_get_contents($path);

$delete = $_POST['file'];
// ruleid: ninfa.php.path-traversal.request-to-filesystem,ninfa.php.file-access.dynamic-delete
unlink($delete);

$url = $_GET['url'];
// ruleid: ninfa.php.ssrf.request-to-curl
curl_init($url);

$target = $_GET['next'];
// ruleid: ninfa.php.unsafe-redirect.request-location,ninfa.php.header-injection.request-to-header
header('Location: ' . $target);

$header = $_POST['header'];
// ruleid: ninfa.php.header-injection.request-to-header
header($header);

$template = $_GET['template'];
// ruleid: ninfa.php.dynamic-include-require.request-path
require $template;

$root = dirname(__DIR__);
// ok: ninfa.php.dynamic-include-require.request-path
require_once $root . '/bootstrap.php';

// ruleid: ninfa.php.unsafe-deserialization.unserialize
unserialize($payload, ['allowed_classes' => false]);

// ruleid: ninfa.php.dangerous-eval-assert.eval
eval($code);

// ruleid: ninfa.php.cryptographic-misuse.weak-password-hash
md5($password);

// ok: ninfa.php.cryptographic-misuse.weak-password-hash
password_hash($password, PASSWORD_DEFAULT);
