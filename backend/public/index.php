<?php

declare(strict_types=1);

/*
 * The HTTP entry point.
 *
 * Absent until now, so the API had no way to receive a request at all.
 */

use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// Lets a deployment drop a file to take the app offline without a code change.
if (file_exists($maintenance = __DIR__ . '/../storage/framework/maintenance.php')) {
    require $maintenance;
}

require __DIR__ . '/../vendor/autoload.php';

/** @var Illuminate\Foundation\Application $app */
$app = require_once __DIR__ . '/../bootstrap/app.php';

$app->handleRequest(Request::capture());
