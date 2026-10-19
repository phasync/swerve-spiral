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
