<?php
/**
 * @author Gerard van Helden <gerard@zicht.nl>
 * @copyright Zicht Online <http://zicht.nl>
 */

use Zicht\Sql\ForeignKeyCheck\App;

require_once __DIR__ . '/../vendor/autoload.php';

(new App())->run($_SERVER['argv']);
