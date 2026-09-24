<?php

$query = $request->getQueryParams();
$sql = $query['sql'];
// ruleid: ninfa.yii3.sql-injection.request-to-create-command
$db->createCommand($sql);

// ruleid: ninfa.yii3.sql-injection.concatenated-command
$db->createCommand('SELECT * FROM users WHERE id = ' . $id);

$body = $request->getParsedBody();
$name = $body['name'];
// ruleid: ninfa.yii3.xss.request-to-output
echo $name;

// ok: ninfa.yii3.xss.request-to-output
echo Html::encode($name);

// ruleid: ninfa.yii3.html.encoding-disabled
$element->encode(false);

$headerValue = $request->getHeaderLine('X-Forwarded-Host');
// ruleid: ninfa.yii3.header-injection.request-to-header
$response->withHeader('X-Target', $headerValue);

$params = $request->getQueryParams();
$target = $params['next'];
// ruleid: ninfa.yii3.unsafe-redirect.request-to-location-header,ninfa.yii3.header-injection.request-to-header
$response->withHeader('Location', $target);

// ok: ninfa.yii3.unsafe-redirect.request-to-location-header
$response->withHeader('Location', '/dashboard');
