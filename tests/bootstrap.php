<?php

use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Filesystem\Filesystem;

require dirname(__DIR__).'/vendor/autoload.php';

(new Dotenv())->bootEnv(dirname(__DIR__).'/.env');

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}

// The kernel reads the notes and the index from these two places. They must never be notes/ or a developer's index.
$sandbox = sys_get_temp_dir().'/synapse-tests-'.bin2hex(random_bytes(4));
(new Filesystem())->mkdir([$sandbox.'/notes/brain', $sandbox.'/notes/inbox']);
foreach (['BRAIN_DIR' => $sandbox.'/notes', 'BRAIN_DB' => $sandbox.'/brain.sqlite'] as $name => $value) {
    $_SERVER[$name] = $_ENV[$name] = $value;
    putenv($name.'='.$value);
}
register_shutdown_function(static fn () => (new Filesystem())->remove($sandbox));
