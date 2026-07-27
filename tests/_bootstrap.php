<?php

defined('YII_DEBUG') or define('YII_DEBUG', true);
defined('YII_ENV') or define('YII_ENV', 'test');

$root = dirname(__DIR__, 3);
require_once $root . '/vendor/autoload.php';

// Set Yii alias before Codeception initializes modules
\Yii::setAlias('@root', $root);
\Yii::setAlias('@webroot', $root . '/web');

require_once $root . '/config/test.php';
