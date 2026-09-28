#!/bin/sh
# Create the Spiral test application in tests/Fixtures/app, for Spiral version $1 (3): the
# framework's own skeleton (spiral/app), with this package autoloaded from the checkout and the
# test routes of tests/Fixtures/src added. Idempotent.
#
# SWERVE_PATH=/path/to/swerve installs phasync/swerve from a local checkout instead of Packagist,
# as the version of its latest tag.
set -eu
cd "$(dirname "$0")/Fixtures"

if [ ! -f app/app.php ]; then
    rm -rf app
    # spiral/app 3.9 needs PHP 8.4; 3.8 is the same skeleton for PHP 8.1 and up
    if php -r 'exit(PHP_VERSION_ID >= 80400 ? 0 : 1);'; then skeleton="^$1.9"; else skeleton="~$1.8.1"; fi
    composer create-project --no-interaction --no-progress "spiral/app:$skeleton" app
fi

cd app
composer config minimum-stability dev
composer config prefer-stable true
# The adapter from the checkout, by its autoload rule: a path repository's symlink would make the
# tests directory contain itself
php -r '$c = json_decode(file_get_contents("composer.json"), true); $c["autoload"]["psr-4"]["Swerve\\Spiral\\"] = "../../../src/"; file_put_contents("composer.json", json_encode($c, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");'
if [ -n "${SWERVE_PATH:-}" ]; then
    version=$(git -C "$SWERVE_PATH" describe --tags --abbrev=0)
    composer config repositories.swerve "{\"type\": \"path\", \"url\": \"$SWERVE_PATH\", \"options\": {\"symlink\": false, \"versions\": {\"phasync/swerve\": \"$version\"}}}"
fi
composer require --no-interaction --no-progress 'phasync/swerve:^0.1.0-alpha15'

# The test routes, and Spiral's authentication (tokens in the session) for them
mkdir -p app/src/SwerveTest
cp ../src/*.php app/src/SwerveTest/
grep -q 'SwerveTest' app/src/Application/Kernel.php \
    || sed -i 's/Bootloader\\AppBootloader::class,/&\n            \\App\\SwerveTest\\TestBootloader::class,/' app/src/Application/Kernel.php
grep -q 'SwerveTest' app/src/Application/Kernel.php

cat > swerve.php <<'PHP'
<?php

require __DIR__ . '/vendor/autoload.php';

return new Swerve\Spiral\Handler(__DIR__);
PHP
