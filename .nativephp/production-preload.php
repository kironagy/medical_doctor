<?php

/**
 * NativePHP production preload script.
 *
 * Preloads the Laravel application class map so the native binary
 * has all classes available at boot time, reducing first-request latency.
 *
 * This file is referenced by native-build-production.sh.
 */

$projectRoot = dirname(__DIR__);

// Preload Composer autoloader — this warms the class map
require $projectRoot . '/vendor/autoload.php';

// Load the Laravel application bootstrap (but don't boot — we only want
// the classmap/configuration to be preloaded into OPCache)
require $projectRoot . '/bootstrap/app.php';

echo "Production preload complete.\n";
