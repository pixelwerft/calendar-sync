<?php

// Unit tests: they need the plugin's classes, Guzzle and sabre/vobject, but no installed Craft site.
// Set COMPOSER_AUTOLOAD to another autoloader (e.g. a Craft project's vendor/autoload.php) if `composer install` was not run here.
$autoload = getenv('COMPOSER_AUTOLOAD') ?: dirname(__DIR__) . '/vendor/autoload.php';
require $autoload;

$vendor = dirname($autoload);
if (!class_exists('Yii', false)) {
    require $vendor . '/yiisoft/yii2/Yii.php';
}
if (!class_exists('Craft', false)) {
    require $vendor . '/craftcms/cms/src/Craft.php';
}

// With a foreign autoloader the classes of this folder are unknown: load them from here.
spl_autoload_register(static function(string $class): void {
    $prefix = 'pixelwerft\\calendarsync\\tests\\';
    if (str_starts_with($class, $prefix)) {
        $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});
