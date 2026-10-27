<?php

declare(strict_types=1);

namespace VeyroPHP\Http\Health;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use VeyroPHP\Container\Container;
use VeyroPHP\Http\Maintenance\MaintenanceMode;
use VeyroPHP\Http\ResponseFactory;

/**
 * Route handler for a health check URL:
 *
 *     $router->get('/up', HealthEndpoint::class)->name('health');
 *
 * 200 {"status":"ok","checks":{"database":"ok",...}} or 503 with "fail". Error
 * messages are never sent (see HealthCheck). During maintenance the body adds
 * "maintenance": true but the status stays 200: the application is healthy, and a
 * load balancer that removed every server would keep the site down after "up".
 */
final class HealthEndpoint
{
    public function __construct(
        private readonly HealthCheck $health,
        private readonly ResponseFactory $responses,
        private readonly ContainerInterface $container,
    ) {}

    public function __invoke(): ResponseInterface
    {
        $report = $this->health->run();
        $body = ['status' => $report->healthy() ? 'ok' : 'fail', 'checks' => []];

        foreach ($report->results() as $name => $result) {
            $body['checks'][$name] = $result['ok'] ? 'ok' : 'fail';
        }

        $bound = $this->container instanceof Container ? $this->container->bound(MaintenanceMode::class) : $this->container->has(MaintenanceMode::class);
        $mode = $bound ? $this->container->get(MaintenanceMode::class) : null;

        if ($mode instanceof MaintenanceMode && $mode->isDown()) {
            $body['maintenance'] = true;
        }

        return $this->responses->json($body, $report->healthy() ? 200 : 503, ['Cache-Control' => 'no-store']);
    }
}
