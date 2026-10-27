<?php

declare(strict_types=1);

/**
 * Runs the in-process benchmark suite and writes raw results as JSON.
 *
 *     php bench/run.php [--quick] [--label=name]
 *
 * Results go to bench/results/<date>-<label>.json and are printed as a
 * Markdown table. --quick uses fewer iterations for a smoke test.
 */

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use VeyroPHP\Bench\Benchmark;
use VeyroPHP\Bench\Fixtures\Controller;
use VeyroPHP\Bench\Fixtures\PassThrough;
use VeyroPHP\Container\Container;
use VeyroPHP\Database\Connection\ConnectionConfig;
use VeyroPHP\Database\DatabaseManager;
use VeyroPHP\Http\HttpErrorResponder;
use VeyroPHP\Http\HttpKernel;
use VeyroPHP\Http\ResponseFactory;
use VeyroPHP\Routing\HandlerInvoker;
use VeyroPHP\Routing\Router;
use VeyroPHP\View\View;

require dirname(__DIR__) . '/vendor/autoload.php';

$options = getopt('', ['quick', 'label::']);
$quick = isset($options['quick']);
$label = is_string($options['label'] ?? null) ? preg_replace('/[^A-Za-z0-9_-]/', '', $options['label']) : 'local';
$scale = $quick ? 0.05 : 1.0;
$n = static fn(int $iterations): int => max(50, (int) ($iterations * $scale));

$results = [];

// Container: autowire a controller with a three-level dependency graph (transient).
$container = new Container();
$results[] = Benchmark::run('container.autowire_3_levels', static fn() => $container->get(Controller::class), $n(20000));

// Router: 100 static and 100 dynamic routes.
$router = new Router();
for ($i = 0; $i < 100; $i++) {
    $router->get("/static/page-{$i}", static fn(): string => 'ok');
    $router->get("/dynamic/{$i}/{id:\\d+}", static fn(): string => 'ok');
}
$router->match('GET', '/'); // compile once, outside the measurement
$results[] = Benchmark::run('router.match_static_of_200', static fn() => $router->match('GET', '/static/page-99'), $n(50000));
$results[] = Benchmark::run('router.match_dynamic_last_of_100', static fn() => $router->match('GET', '/dynamic/99/12345'), $n(20000));

// HTTP kernel: full request handling in-process (request object creation included).
$psr17 = new Psr17Factory();
$responses = new ResponseFactory($psr17, $psr17);
/**
 * @param list<Psr\Http\Server\MiddlewareInterface> $middleware
 */
function benchKernel(ResponseFactory $responses, array $middleware): HttpKernel
{
    $container = new Container();
    $router = new Router();
    $router->get('/', static fn(): string => 'Hello');
    $router->get('/json', static fn(): array => array_map(static fn(int $i): array => ['id' => $i, 'name' => 'Item ' . $i], range(1, 20)));

    return new HttpKernel($router, $container, new HandlerInvoker($container, $responses), new HttpErrorResponder($responses), $responses, $middleware);
}
$plain = benchKernel($responses, []);
$withMiddleware = benchKernel($responses, [new PassThrough(), new PassThrough(), new PassThrough()]);
$results[] = Benchmark::run('http.kernel_minimal_route', static fn() => $plain->handle(new ServerRequest('GET', '/')), $n(20000));
$results[] = Benchmark::run('http.kernel_3_middleware', static fn() => $withMiddleware->handle(new ServerRequest('GET', '/')), $n(20000));
$results[] = Benchmark::run('http.kernel_json_20_items', static fn() => $plain->handle(new ServerRequest('GET', '/json')), $n(20000));

// PSR-7 cost in isolation: request with headers, response with body.
$results[] = Benchmark::run('psr7.request_and_response', static function () use ($psr17): void {
    $request = (new ServerRequest('GET', '/users?page=2'))
        ->withHeader('Accept', 'text/html')
        ->withHeader('X-Request-Id', 'abc')
        ->withAttribute('id', '5');
    $psr17->createResponse(200)->withHeader('Content-Type', 'text/html')->withBody($psr17->createStream('Hello'));
    unset($request);
}, $n(50000));

// View: a template with 10 escaped values.
$viewDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'veyrophp-bench-views';
@mkdir($viewDir);
file_put_contents($viewDir . '/page.php', '<ul><?php foreach ($items as $item): ?><li><?= $e($item) ?></li><?php endforeach ?></ul>');
$view = new View($viewDir);
$items = array_map(static fn(int $i): string => "<item {$i}> & more", range(1, 10));
$results[] = Benchmark::run('view.render_10_escaped_values', static fn() => $view->render('page', ['items' => $items]), $n(20000));

// Database: SQLite in memory, 1,000 rows. Raw PDO runs show the builder's overhead.
$db = (new DatabaseManager(['m' => new ConnectionConfig('m', 'sqlite', ':memory:')], 'm'))->connection();
$db->statement('create table users (id integer primary key, name text not null, email text not null)');
$db->transaction(static function () use ($db): void {
    for ($i = 1; $i <= 1000; $i++) {
        $db->table('users')->insert(['name' => "User {$i}", 'email' => "user{$i}@example.test"]);
    }
});
$pdo = $db->pdo();
$select = $pdo->prepare('select * from users where id = ? limit 1');
$insert = $pdo->prepare('insert into users (name, email) values (?, ?)');

$results[] = Benchmark::run('db.read_by_pk_builder', static fn() => $db->table('users')->where('id', random_int(1, 1000))->first(), $n(20000));
$results[] = Benchmark::run('db.read_by_pk_raw_pdo_reused_statement', static function () use ($select): void {
    $select->execute([random_int(1, 1000)]);
    $select->fetch();
    $select->closeCursor();
}, $n(20000));
// Fair comparison with the builder, which prepares on every call.
$results[] = Benchmark::run('db.read_by_pk_raw_pdo_prepare_each', static function () use ($pdo): void {
    $statement = $pdo->prepare('select * from "users" where "id" = ? limit 1');
    $statement->execute([random_int(1, 1000)]);
    $statement->fetch();
    $statement->closeCursor();
}, $n(20000));
// SQL compilation only, no database work.
$results[] = Benchmark::run('db.builder_compile_only', static fn() => $db->table('users')->where('id', 5)->limit(1)->toSql(), $n(20000));
$results[] = Benchmark::run('db.insert_builder', static fn() => $db->table('users')->insert(['name' => 'New', 'email' => 'new@example.test']), $n(10000));
$results[] = Benchmark::run('db.insert_raw_pdo_reused_statement', static fn() => $insert->execute(['New', 'new@example.test']), $n(10000));

@unlink($viewDir . '/page.php');
@rmdir($viewDir);

// Output
$environment = [
    'date' => date('c'),
    'php' => PHP_VERSION,
    'sapi' => PHP_SAPI,
    'os' => php_uname('s') . ' ' . php_uname('r') . ' ' . php_uname('m'),
    'opcache_cli' => function_exists('opcache_get_status') && (bool) ini_get('opcache.enable_cli'),
    'jit' => (string) ini_get('opcache.jit'),
    'xdebug' => extension_loaded('xdebug'),
    'quick' => $quick,
];

echo "\n| Case | ops/sec (median) | min | max | p50 µs | p95 µs | p99 µs |\n|---|---:|---:|---:|---:|---:|---:|\n";
foreach ($results as $r) {
    printf("| %s | %s | %s | %s | %s | %s | %s |\n", $r['name'], number_format($r['ops_per_sec_median']), number_format($r['ops_per_sec_min']), number_format($r['ops_per_sec_max']), $r['p50_us'], $r['p95_us'], $r['p99_us']);
}
printf("\nPHP %s, %s, opcache(cli)=%s, xdebug=%s, peak memory %.1f MB\n", $environment['php'], $environment['os'], var_export($environment['opcache_cli'], true), var_export($environment['xdebug'], true), memory_get_peak_usage(true) / 1048576);

$dir = __DIR__ . '/results';
if (!is_dir($dir)) {
    mkdir($dir, 0775, true);
}
$file = sprintf('%s/%s-%s%s.json', $dir, date('Y-m-d'), $label, $quick ? '-quick' : '');
file_put_contents($file, json_encode(['environment' => $environment, 'results' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
echo "Raw results: {$file}\n";
