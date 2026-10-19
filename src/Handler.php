<?php

namespace Swerve\Spiral;

use phasync\Util\Synchronized;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Spiral\Boot\AbstractKernel;
use Spiral\Boot\Environment\AppEnvironment;
use Spiral\Boot\FinalizerInterface;
use Spiral\Core\Container;
use Spiral\Core\ContainerScope;
use Spiral\Core\Options;
use Spiral\Core\Scope;
use Spiral\Exceptions\ExceptionHandler;
use Spiral\Exceptions\ExceptionHandlerInterface;
use Spiral\Exceptions\Verbosity;
use Spiral\Http\Http;
use Swerve\WorkerStoppingException;

/**
 * A Spiral application as a PSR-15 request handler, which the `spiral` adapter's entry
 * ({@see entry()}) serves through swerve-psr15's Bridge; a swerve.php run with `--adapter=swerve`
 * may do the same, for another kernel or exception handler:
 *
 *     return Swerve\Psr15\Bridge::wrap(new Swerve\Spiral\Handler(__DIR__));
 *
 * Once per worker, the kernel boots as the skeleton's app.php boots it, without serve(), and
 * Spiral's 'http' scope opens for the worker's life. Per request, as Spiral's RoadRunner
 * dispatcher serves one: Http::handle() in that scope, a 500 reported to Spiral's exception
 * handler for what escapes it, then the finalizers (the Cycle ORM heap, the loggers). One request
 * at a time per worker: overlapping requests would share the output buffers around every
 * controller and view, the native session, the ORM heap the finalizers clean, and the 'http'
 * scope's current request (docs/concurrency.md). What runs after handle() returned (a streamed
 * body, a WebSocket callback) runs outside that turn and the request's scope, beside the requests
 * that follow.
 */
final class Handler implements RequestHandlerInterface
{
    private readonly AbstractKernel $kernel;
    private readonly Container $container;
    private readonly \Fiber $scopeHolder;
    private readonly Container $scope;
    private readonly FinalizerInterface $finalizer;
    private readonly ExceptionHandlerInterface $errors;
    private readonly ResponseFactoryInterface $responses;
    private readonly bool $production;

    /** The WorkerStoppingException Spiral's error middleware reported for the request in flight */
    private ?WorkerStoppingException $stopping = null;

    /**
     * @param string                                  $root             the application's root directory, where composer.json is
     * @param class-string<AbstractKernel>            $kernel           the application's kernel, as in app.php
     * @param class-string<ExceptionHandlerInterface> $exceptionHandler its exception handler, as in app.php
     */
    public function __construct(
        string $root,
        string $kernel = 'App\Application\Kernel',
        string $exceptionHandler = 'App\Application\Exception\Handler',
    ) {
        // The application's autoloader (the vendor directory swerve runs from): swerve's worker loads only its own dependencies
        require_once $GLOBALS['_composer_autoload_path'] ?? "$root/vendor/autoload.php";

        // What is echoed outside a controller (a streamed body, a coroutine) still goes to swerve's
        // terminal, but past PHP's output layer: once that sent output, the headers count as sent
        // for the rest of the worker, and Spiral's session_set_save_handler() fails on every request
        \ob_start(static function (string $output): string {
            \fwrite(\STDOUT, $output);

            return '';
        }, 1);

        // As the skeleton's app.php
        \mb_internal_encoding('UTF-8');
        $options                           = new Options();
        $options->allowSingletonsRebinding = false;
        $options->validateArguments        = false;
        $this->container                   = new Container(options: $options);
        // Kept: the kernel runs the finalizers for termination when it is destroyed
        $this->kernel = $kernel::create(
            directories: ['root' => $root],
            exceptionHandler: $exceptionHandler,
            container: $this->container,
        )->run() ?? throw new \RuntimeException('The Spiral application did not boot; its exception handler reported why');

        // Spiral keeps the current container scope in a static, ContainerScope::$container, which its
        // runScope() swaps when its own Fiber suspends. phasync runs a wait inside such a Fiber in
        // place, other requests' coroutines with it (a WebSocket callback): they would see the
        // waiting request's scope, its session and request. So the static is each request's own,
        // swapped by the request's Scope
        $containers = new \WeakMap();
        $current    = \Closure::bind(static function (?ContainerInterface ...$set): ?ContainerInterface {
            $was                                      = ContainerScope::$container;
            [] !== $set && ContainerScope::$container = $set[0];

            return $was;
        }, null, ContainerScope::class);
        \phasync::onScopeChange(static function (\Scope $new, ?\Scope $old) use ($containers, $current): void {
            $was                               = $current($containers[$new] ?? null);
            null !== $old && $containers[$old] = $was;
        });

        // The 'http' scope, open for the worker's life as Spiral's RoadRunner dispatcher keeps it
        // while it serves: runScope() returns when its callback does, so the callback suspends a
        // fiber that is never resumed (ContainerScope passes a suspension on to the caller)
        $this->scopeHolder = new \Fiber(fn () => $this->container->runScope(
            new Scope('http', autowire: false),
            static fn (Container $scope) => \Fiber::suspend($scope),
        ));
        $this->scope = $this->scopeHolder->start();

        $this->finalizer  = $this->container->get(FinalizerInterface::class);
        $this->errors     = $this->container->get(ExceptionHandlerInterface::class);
        // A worker that stops ends its requests with a WorkerStoppingException, which Spiral's error
        // middleware turns into a 500: noted as it reports it, and thrown on after the request, for
        // swerve to answer (a 503 when nothing was sent yet)
        if ($this->errors instanceof ExceptionHandler) {
            $this->errors->addReporter(function (\Throwable $e): void {
                $e instanceof WorkerStoppingException && $this->stopping = $e;
            });
        }
        $this->responses  = $this->container->get(ResponseFactoryInterface::class);
        $this->production = $this->container->get(AppEnvironment::class)->isProduction();
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return Synchronized::run($this, fn (): ResponseInterface => $this->scope->invoke(
            function (Http $http) use ($request): ResponseInterface {
                try {
                    $response = $http->handle($request);
                    if (null !== $stopping = $this->stopping) {
                        $this->stopping = null;

                        throw $stopping;
                    }

                    return $response;
                } catch (WorkerStoppingException $e) {
                    throw $e;
                } catch (\Throwable $e) {
                    // As Spiral's RoadRunner dispatcher answers what its pipeline let through
                    $this->errors->report($e);
                    $response = $this->responses->createResponse(500);
                    $this->production or $response->getBody()->write($this->errors->render($e, Verbosity::VERBOSE));

                    return $response;
                } finally {
                    $this->finalizer->finalize(false);
                }
            },
        ));
    }
}
