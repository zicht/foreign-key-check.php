<?php
/**
 * @author Gerard van Helden <gerard@zicht.nl>
 * @copyright Zicht Online <http://zicht.nl>
 */

use Zicht\Sql\ForeignKeyCheck\App;

$autoloaders = [__DIR__ . '/../vendor/autoload.php', __DIR__ . '/../../../autoload.php'];

foreach ($autoloaders as $autoloader) {
    if (is_readable($autoloader)) {
        require_once $autoloader;
    }
}

(new App())->run($_SERVER['argv']);
