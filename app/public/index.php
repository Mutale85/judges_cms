<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

$router = require JC_ROOT . '/routes.php';
$app = new JC\Core\App();
$app->handle(JC\Core\Request::fromGlobals(), $router)->send();
