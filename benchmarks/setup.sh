#!/bin/sh
# The application the benchmarks serve, in benchmarks/app: a copy of the test application
# (tests/create-app.sh), set up as Spiral's README asks for production, with spiral/sapi-bridge
# and a public/index.php so that PHP-FPM can serve it too. RoadRunner runs the skeleton's own
# app.php.
set -eu
cd "$(dirname "$0")"
rm -rf app
cp -r ../tests/Fixtures/app app
cd app
rm -rf runtime/cache runtime/session/* runtime/logs/*

# The adapter from this checkout, one directory less deep
php -r '$c = json_decode(file_get_contents("composer.json"), true); $c["autoload"]["psr-4"]["Swerve\\Spiral\\"] = "../../src/"; file_put_contents("composer.json", json_encode($c, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");'
composer require --no-interaction --no-progress --no-scripts spiral/sapi-bridge
grep -q SapiBootloader app/src/Application/Kernel.php \
    || sed -i 's/RoadRunnerBridge\\HttpBootloader::class,/&\n            \\Spiral\\Sapi\\Bootloader\\SapiBootloader::class,/' app/src/Application/Kernel.php
composer dump-autoload --optimize

# Production, as the skeleton's .env says to set it
sed -i 's/^APP_ENV=.*/APP_ENV=prod/; s/^DEBUG=.*/DEBUG=false/; s/^VERBOSITY_LEVEL=.*/VERBOSITY_LEVEL=basic/;
        s/^MONOLOG_DEFAULT_LEVEL=.*/MONOLOG_DEFAULT_LEVEL=ERROR/; s/^TOKENIZER_CACHE_TARGETS=.*/TOKENIZER_CACHE_TARGETS=true/;
        s/^VIEW_CACHE=.*/VIEW_CACHE=true/; s/^CYCLE_SCHEMA_CACHE=.*/CYCLE_SCHEMA_CACHE=true/' .env

# PHP-FPM's front controller: app.php without its CLI settings, serving through spiral/sapi-bridge
cat > public/index.php <<'PHP'
<?php

use App\Application\Exception\Handler;
use App\Application\Kernel;
use Spiral\Core\Container;
use Spiral\Core\Options;

mb_internal_encoding('UTF-8');
require dirname(__DIR__) . '/vendor/autoload.php';

$options                           = new Options();
$options->allowSingletonsRebinding = false;
$options->validateArguments        = false;
Kernel::create(directories: ['root' => dirname(__DIR__)], exceptionHandler: Handler::class, container: new Container(options: $options))
    ->run()
    ?->serve();
PHP
