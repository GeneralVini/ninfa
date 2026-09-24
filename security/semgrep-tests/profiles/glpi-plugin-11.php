<?php

$id = $_GET['id'];
// ruleid: ninfa.glpi11.sql-injection.request-to-query,ninfa.glpi11.sql-injection.concatenated-query
$DB->query('SELECT * FROM glpi_users WHERE id = ' . $id);

$target = $_GET['next'];
// ruleid: ninfa.glpi11.unsafe-redirect.request-target,ninfa.glpi11.unsafe-redirect.dynamic-target
Html::redirect($target);

// ruleid: ninfa.glpi11.unsafe-redirect.dynamic-target
Html::redirect('/front/central.php');

$url = $_POST['url'];
// ruleid: ninfa.glpi11.ssrf.request-to-url-content
Toolbox::getURLContent($url);

$file = $_REQUEST['file'];
// ruleid: ninfa.glpi11.file-access.request-to-file-content
Toolbox::getFileContent($file);

// ok: ninfa.glpi11.ssrf.request-to-url-content
Toolbox::getURLContent('https://intranet.example.invalid/health');
