<?php

declare(strict_types=1);

/**
 * Autoload shim for facebook/capi-param-builder-php (<= 1.3.1, still broken on
 * upstream main as of 2026-06).
 *
 * facebook/php-business-sdk 25.x imports FacebookAds\PII_DATA_TYPE,
 * FacebookAds\PIIUtils, and friends from the param-builder package, but that
 * package's PSR-4 mapping ("FacebookAds\\" => "php/capi-param-builder/src/")
 * cannot resolve them: the classes are defined in files like
 * src/model/Constants.php (several classes per file) and
 * src/piiUtil/PIIUtils.php. First CAPI send then fatals with
 * "Class 'FacebookAds\PII_DATA_TYPE' not found".
 *
 * This registers a fallback autoloader AFTER Composer's: it only fires for
 * FacebookAds\* classes Composer failed to resolve, and then require_once's
 * every PHP file under the builder's src/ exactly once. Classes loaded here
 * are defined before Composer would ever include their files, so there is no
 * double-declaration risk, and the shim is a natural no-op once upstream
 * ships a fixed autoload (Composer will then resolve the classes first).
 */
spl_autoload_register(static function (string $class): void {
    static $done = false;
    if ($done || !str_starts_with($class, 'FacebookAds\\')) {
        return;
    }
    $done = true;

    if (!class_exists(\Composer\InstalledVersions::class, false)
        || !\Composer\InstalledVersions::isInstalled('facebook/capi-param-builder-php')
    ) {
        return;
    }
    $src = \Composer\InstalledVersions::getInstallPath('facebook/capi-param-builder-php')
        . '/php/capi-param-builder/src';
    if (!is_dir($src)) {
        return;
    }

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
            require_once $file->getPathname();
        }
    }
});
