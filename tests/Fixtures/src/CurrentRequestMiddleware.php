<?php

namespace App\SwerveTest;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The request as the 'http' scope gives it (as SessionScope and the other scoped services find
 * it), in X-Current-V: its ?v=. With ?wait=1, read after a wait, as after a query.
 */
final class CurrentRequestMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly ContainerInterface $container)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ('1' === ($request->getQueryParams()['wait'] ?? null)) {
            \extension_loaded('phasync') ? \usleep(200_000) : \phasync::sleep(0.2);
        }
        $current  = $this->container->get(ServerRequestInterface::class)->getQueryParams()['v'] ?? '';
        $response = $handler->handle($request);

        return $response->withHeader('X-Current-V', $current);
    }
}
