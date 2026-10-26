<?php

declare(strict_types=1);

// Shopware-Autoloader laden, falls in einer Shopware-Installation eingebettet
$shopwareAutoloader = dirname(__DIR__, 4) . '/vendor/autoload.php';
if (file_exists($shopwareAutoloader)) {
    require_once $shopwareAutoloader;
}

// Plugin-eigener Autoloader (Standalone-Tests ohne Shopware-Context)
$localAutoloader = dirname(__DIR__) . '/vendor/autoload.php';
if (file_exists($localAutoloader)) {
    require_once $localAutoloader;
}

spl_autoload_register(static function (string $class): void {
    $prefixes = [
        'Ruhrcoder\\RcMediaToolkit\\Tests\\' => __DIR__ . '/',
        'Ruhrcoder\\RcMediaToolkit\\' => dirname(__DIR__) . '/src/',
    ];

    foreach ($prefixes as $prefix => $baseDir) {
        $len = strlen($prefix);
        if (strncmp($class, $prefix, $len) !== 0) {
            continue;
        }

        $relativeClass = substr($class, $len);
        $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

        if (file_exists($file)) {
            require_once $file;
            return;
        }
    }
});
