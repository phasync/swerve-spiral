<?php

namespace App\SwerveTest;

use Spiral\Auth\Middleware\AuthMiddleware;
use Spiral\Boot\Bootloader\Bootloader;
use Spiral\Bootloader\Auth\AuthBootloader;
use Spiral\Bootloader\Auth\HttpAuthBootloader;
use Spiral\Csrf\Middleware\CsrfFirewall;
use Spiral\Router\GroupRegistry;

/**
 * Spiral's authentication, with tokens in the session, for the 'web' routes; and a 'csrf' group
 * of routes that also need a CSRF token. The skeleton has neither.
 */
final class TestBootloader extends Bootloader
{
    protected const DEPENDENCIES = [HttpAuthBootloader::class];

    public function init(AuthBootloader $auth): void
    {
        $auth->addActorProvider(TestActorProvider::class);
    }

    public function boot(GroupRegistry $groups): void
    {
        $groups->getGroup('web')->addMiddleware(AuthMiddleware::class);
        $groups->getGroup('csrf')
            ->addMiddleware('middleware:web')
            ->addMiddleware(AuthMiddleware::class)
            ->addMiddleware(CsrfFirewall::class);
    }
}
