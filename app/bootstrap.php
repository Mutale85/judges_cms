<?php

declare(strict_types=1);

/*
 * Shared bootstrap for the web front controller, the CLI and the test suite.
 */

define('JC_ROOT', __DIR__);

if (is_file(JC_ROOT . '/vendor/autoload.php')) {
    require JC_ROOT . '/vendor/autoload.php';
} else {
    spl_autoload_register(static function (string $class): void {
        if (!str_starts_with($class, 'JC\\')) {
            return;
        }
        $file = JC_ROOT . '/src/' . str_replace('\\', '/', substr($class, 3)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    });
}

require JC_ROOT . '/src/helpers.php';

JC\Core\Config::load(JC_ROOT);
date_default_timezone_set('UTC');
