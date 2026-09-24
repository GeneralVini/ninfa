<?php

$sql = Yii::$app->request->get('sql');
// ruleid: ninfa.yii2.sql-injection.request-to-create-command
$db->createCommand($sql);

$name = Yii::$app->request->post('name');
// ruleid: ninfa.yii2.xss.request-to-output
echo $name;

// ok: ninfa.yii2.xss.request-to-output
echo Html::encode(Yii::$app->request->post('name'));

$target = Yii::$app->request->get('next');
// ruleid: ninfa.yii2.unsafe-redirect.request-target
Yii::$app->response->redirect($target);

$headerValue = Yii::$app->request->get('header');
// ruleid: ninfa.yii2.header-injection.request-to-header
Yii::$app->response->headers->set('X-Example', $headerValue);

$file = Yii::$app->request->get('file');
// ruleid: ninfa.yii2.path-traversal.request-to-send-file
Yii::$app->response->sendFile($file);

// ok: ninfa.yii2.unsafe-redirect.request-target
Yii::$app->response->redirect(['/site/index']);
