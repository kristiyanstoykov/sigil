<?php

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

// The app container exports APP_ENV=dev, which would otherwise win over
// phpunit.dist.xml. Tests always run in the test environment.
$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';

(new Dotenv())->bootEnv(dirname(__DIR__).'/.env');

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}
