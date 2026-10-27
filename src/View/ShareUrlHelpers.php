<?php

declare(strict_types=1);

namespace VeyroPHP\View;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use VeyroPHP\Application\Application;
use VeyroPHP\Config\Config;
use VeyroPHP\Http\HttpKernel;
use VeyroPHP\Routing\Router;

/**
 * Shares URL helpers with every view of the request:
 *
 * - $urls: route(), to(), current(), base(), absolute() (from config app.url) and
 *   asset() (versioned from the file in public/); see Urls
 * - $assetBase: the base path of files in public/, e.g. "<?= $e($assetBase) ?>/favicon.ico".
 *   It never contains "/index.php", which would hand the file to PHP (HTTP-3).
 *
 * Both bases come from the request attributes HttpKernel sets once per request.
 */
final class ShareUrlHelpers implements MiddlewareInterface
{
    public function __construct(private readonly ContainerInterface $container) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $kernel = $this->container->get(HttpKernel::class);
        $view = $this->container->get(View::class);
        $router = $this->container->get(Router::class);
        \assert($kernel instanceof HttpKernel && $view instanceof View && $router instanceof Router);

        $base = $request->getAttribute(HttpKernel::BASE_ATTRIBUTE);
        $base = is_string($base) ? $base : $kernel->basePath($request); // a request that did not come through handle()
        $publicBase = $request->getAttribute(HttpKernel::PUBLIC_BASE_ATTRIBUTE);
        $publicBase = is_string($publicBase) ? $publicBase : $base;
        $config = $this->container->has(Config::class) ? $this->container->get(Config::class) : null;
        $app = $this->container->has(Application::class) ? $this->container->get(Application::class) : null;
        $appUrl = $config instanceof Config && $config->has('app.url') ? $config->get('app.url') : null;

        $view->share('assetBase', $publicBase);
        $view->share('urls', new Urls(
            $router,
            $base,
            $request,
            is_string($appUrl) ? $appUrl : null,
            $app instanceof Application ? $app->basePath('public') : null,
            $publicBase,
        ));

        return $handler->handle($request);
    }
}
