<?php

declare(strict_types=1);

namespace VeyroPHP\Tests\Integration\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use VeyroPHP\Application\Application;
use VeyroPHP\Auth\Rbac\RequirePermission;
use VeyroPHP\Config\Config;
use VeyroPHP\Console\Console;
use VeyroPHP\Console\ConsoleServiceProvider;
use VeyroPHP\Console\Make\Generator;
use VeyroPHP\Console\Make\MakeControllerCommand;
use VeyroPHP\Console\Make\MakeEventCommand;
use VeyroPHP\Console\Make\MakeFactoryCommand;
use VeyroPHP\Console\Make\MakeJobCommand;
use VeyroPHP\Console\Make\MakeListenerCommand;
use VeyroPHP\Console\Make\MakeMailCommand;
use VeyroPHP\Console\Make\MakeMigrationCommand;
use VeyroPHP\Console\Make\MakeModelCommand;
use VeyroPHP\Console\Make\MakeNotificationCommand;
use VeyroPHP\Console\Make\MakePolicyCommand;
use VeyroPHP\Console\Make\MakeRequestCommand;
use VeyroPHP\Console\Make\MakeSeederCommand;
use VeyroPHP\Console\Output;
use VeyroPHP\Database\DatabaseServiceProvider;
use VeyroPHP\Database\Migration\Migrator;
use VeyroPHP\Database\Schema\Schema;
use VeyroPHP\Http\Console\ServeCommand;
use VeyroPHP\Routing\Console\RouteListCommand;
use VeyroPHP\Routing\Router;
use VeyroPHP\Security\ThrottleRequests;

#[CoversClass(RouteListCommand::class)]
#[CoversClass(Generator::class)]
#[CoversClass(MakeModelCommand::class)]
#[CoversClass(MakeNotificationCommand::class)]
#[CoversClass(MakeControllerCommand::class)]
#[CoversClass(MakeMigrationCommand::class)]
#[CoversClass(MakeEventCommand::class)]
#[CoversClass(MakeFactoryCommand::class)]
#[CoversClass(MakeSeederCommand::class)]
#[CoversClass(MakeJobCommand::class)]
#[CoversClass(MakeListenerCommand::class)]
#[CoversClass(MakeMailCommand::class)]
#[CoversClass(MakePolicyCommand::class)]
#[CoversClass(MakeRequestCommand::class)]
#[CoversClass(ServeCommand::class)]
final class DeveloperCommandsTest extends TestCase
{
    private string $root;

    private Application $app;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'veyrophp-dev-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/public', 0777, true);
        file_put_contents($this->root . '/public/index.php', '<?php');

        $this->app = new Application($this->root);
        $this->app->container()->instance(Config::class, new Config([
            'app' => ['env' => 'local'],
            'database' => ['default' => 'memory', 'connections' => ['memory' => ['driver' => 'sqlite', 'database' => ':memory:']]],
        ]));
        $this->app->register(new DatabaseServiceProvider());
        $this->app->register(new ConsoleServiceProvider());
        // Normally registered by HttpServiceProvider.
        $this->app->container()->singleton(Router::class);
        $this->app->boot();
        // A clock so migration file names are predictable.
        $this->app->container()->instance(
            MakeMigrationCommand::class,
            new MakeMigrationCommand(new Generator($this->app), $this->app->container()->make(Config::class), static fn(): int => 1_793_000_000),
        );
    }

    protected function tearDown(): void
    {
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            \assert($item instanceof \SplFileInfo);
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->root);
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{int, string, string}
     */
    private function exec(array $arguments): array
    {
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        self::assertIsResource($out);
        self::assertIsResource($err);

        $code = $this->app->container()->make(Console::class)->run($arguments, new Output($out, $err));
        rewind($out);
        rewind($err);

        return [$code, str_replace(PHP_EOL, "\n", (string) stream_get_contents($out)), (string) stream_get_contents($err)];
    }

    private function file(string $relative): string
    {
        $path = $this->root . '/' . $relative;
        self::assertFileExists($path);
        $code = (string) file_get_contents($path);

        // Throws ParseError if the generated code is not valid PHP.
        self::assertNotSame([], token_get_all($code, TOKEN_PARSE));

        return $code;
    }

    public function testBuiltInCommandsAreListed(): void
    {
        self::assertSame(
            ['cache:prune', 'config:cache', 'config:clear', 'db:seed', 'down', 'health', 'key:generate','make:controller', 'make:event', 'make:factory', 'make:job', 'make:listener', 'make:mail', 'make:middleware', 'make:migration', 'make:model', 'make:notification', 'make:policy', 'make:request', 'make:seeder', 'migrate', 'migrate:rollback', 'migrate:status', 'package:discover', 'port:analyze', 'port:convert', 'route:list', 'security:audit', 'serve', 'up', 'vendor:publish', 'view:lint'],
            array_keys($this->app->container()->make(Console::class)->commands()),
        );
    }

    public function testViewLint(): void
    {
        mkdir($this->root . '/resources/views/partials', 0777, true);
        file_put_contents($this->root . '/resources/views/ok.php', '<h1><?= $e($title) ?></h1><?= $view->render(\'partials.nav\') ?>');
        file_put_contents($this->root . '/resources/views/partials/nav.php', '<a href="<?= $view->url($home) ?>">home</a>');

        [$code, $out] = $this->exec(['view:lint']);
        self::assertSame(0, $code, $out);
        self::assertStringContainsString('2 template(s) checked: 0 error(s), 0 warning(s).', $out);

        file_put_contents($this->root . '/resources/views/partials/nav.php', "<a href=\"<?= \$e(\$home) ?>\">home</a>\n<p><?= \$name ?></p>");
        [$code, $out, $err] = $this->exec(['view:lint']);
        self::assertSame(1, $code);
        self::assertStringContainsString('partials/nav.php:2  unescaped output: $name', $err);
        self::assertStringContainsString('partials/nav.php:1  $e() in a URL attribute: $e($home)', $out . $err);

        file_put_contents($this->root . '/resources/views/partials/nav.php', '<a href="<?= $e($home) ?>">home</a>');
        self::assertSame(0, $this->exec(['view:lint'])[0], 'a warning alone does not fail');
        self::assertSame(1, $this->exec(['view:lint', '--strict'])[0], '--strict fails on warnings');

        self::assertSame(2, $this->exec(['view:lint', '--path=' . $this->root . '/missing'])[0]);
    }

    public function testRouteList(): void
    {
        $router = $this->app->container()->make(Router::class);
        $router->get('/', static fn(): string => 'home')->name('home');
        $router->post('/login', static fn(ServerRequestInterface $r): string => 'x')->middleware(ThrottleRequests::limit(5));
        $router->get('/admin/users', [self::class, 'handler'])->middleware(RequirePermission::for('users.view'))->name('admin.users');

        [$code, $out] = $this->exec(['route:list']);
        self::assertSame(0, $code);
        self::assertMatchesRegularExpression('/^Method\s+Path\s+Name\s+Handler\s+Middleware\n/', $out);
        self::assertMatchesRegularExpression('#GET\s+/\s+home\s+Closure \(.*DeveloperCommandsTest\.php:\d+\)#', $out);
        self::assertMatchesRegularExpression('#POST\s+/login\s+Closure .*ThrottleRequests:5,1#', $out);
        self::assertMatchesRegularExpression('#/admin/users\s+admin\.users\s+' . preg_quote(self::class, '#') . '::handler\s+RequirePermission:users\.view#', $out);

        [, $out] = $this->exec(['route:list', '--method=post']);
        self::assertStringContainsString('/login', $out);
        self::assertStringNotContainsString('/admin', $out);

        [, $out] = $this->exec(['route:list', '--path=/admin']);
        self::assertStringContainsString('/admin/users', $out);
        self::assertStringNotContainsString('/login', $out);

        self::assertSame("No routes match.\n", $this->exec(['route:list', '--path=/nothing'])[1]);
    }

    public static function handler(): string
    {
        return 'users';
    }

    public function testRouteListWithoutRoutes(): void
    {
        self::assertSame([0, "No routes are registered.\n", ''], $this->exec(['route:list']));
    }

    public function testMakeModelWithMigrationCreatesWorkingFiles(): void
    {
        [$code, $out] = $this->exec(['make:model', 'BlogPost', '--migration']);

        self::assertSame(0, $code);
        self::assertSame("Created app/Models/BlogPost.php\nCreated database/migrations/2026_10_26_073320_create_blog_posts_table.php\n", $out);

        $model = $this->file('app/Models/BlogPost.php');
        self::assertStringContainsString('namespace App\\Models;', $model);
        self::assertStringContainsString('final class BlogPost extends Model', $model);
        self::assertStringContainsString("protected const TABLE = 'blog_posts';", $model);

        $this->file('database/migrations/2026_10_26_073320_create_blog_posts_table.php');

        // The generated migration runs and creates the table.
        $migrator = new Migrator($this->app->container()->make(\VeyroPHP\Database\Connection\Connection::class), $this->root . '/database/migrations');
        self::assertSame(['2026_10_26_073320_create_blog_posts_table'], $migrator->run());
        $schema = $this->app->container()->make(Schema::class);
        self::assertTrue($schema->hasTable('blog_posts'));

        // The generated model and migration fit together: the ORM can insert a row.
        // (The first model stub had no PUBLIC_ID, so inserts failed on the NOT NULL public_id column.)
        require $this->root . '/app/Models/BlogPost.php';
        $class = 'App\\Models\\BlogPost';
        self::assertTrue(is_subclass_of($class, \VeyroPHP\Orm\Model::class));
        $post = $this->app->container()->make(\VeyroPHP\Orm\Orm::class)->create($class, []);
        $publicId = $post->get('public_id');
        self::assertIsString($publicId);
        self::assertTrue(\VeyroPHP\Support\Ulid::isValid($publicId));

        self::assertSame(['2026_10_26_073320_create_blog_posts_table'], $migrator->rollback());
        self::assertFalse($schema->hasTable('blog_posts'));
    }

    public function testMakeModelInSubNamespaceWithTable(): void
    {
        self::assertSame(0, $this->exec(['make:model', 'Shop/Category', '--table=shop_categories'])[0]);

        $code = $this->file('app/Models/Shop/Category.php');
        self::assertStringContainsString('namespace App\\Models\\Shop;', $code);
        self::assertStringContainsString("TABLE = 'shop_categories'", $code);
    }

    public function testMakeController(): void
    {
        [$code, $out] = $this->exec(['make:controller', 'Admin\\UserController']);

        self::assertSame(0, $code);
        self::assertSame("Created app/Http/Controllers/Admin/UserController.php\n", $out);

        $code = $this->file('app/Http/Controllers/Admin/UserController.php');
        self::assertStringContainsString('namespace App\\Http\\Controllers\\Admin;', $code);
        self::assertStringContainsString("[\\App\\Http\\Controllers\\Admin\\UserController::class, 'index']", $code);
    }

    public function testMakeEventAndListenerWorkTogether(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $event = 'Orders/OrderShipped' . $suffix;
        $listener = 'NotifyCustomer' . $suffix;

        self::assertSame([0, "Created app/Events/Orders/OrderShipped{$suffix}.php\n"], array_slice($this->exec(['make:event', $event]), 0, 2));
        self::assertSame(
            [0, "Created app/Listeners/NotifyCustomer{$suffix}.php\n"],
            array_slice($this->exec(['make:listener', $listener, '--event=App\Events\Orders\OrderShipped' . $suffix]), 0, 2),
        );

        $listenerCode = $this->file("app/Listeners/NotifyCustomer{$suffix}.php");
        self::assertStringContainsString("use App\Events\Orders\OrderShipped{$suffix};", $listenerCode);
        self::assertStringContainsString("public function handle(OrderShipped{$suffix} \$event): void", $listenerCode);
        self::assertStringContainsString("OrderShipped{$suffix}::class => [\App\Listeners\NotifyCustomer{$suffix}::class]", $listenerCode);

        // The generated classes load and the listener is called by the dispatcher.
        require $this->root . "/app/Events/Orders/OrderShipped{$suffix}.php";
        require $this->root . "/app/Listeners/NotifyCustomer{$suffix}.php";
        $eventClass = "App\Events\Orders\OrderShipped{$suffix}";
        $listenerClass = "App\Listeners\NotifyCustomer{$suffix}";
        self::assertTrue(class_exists($eventClass) && class_exists($listenerClass));
        $events = new \VeyroPHP\Events\EventDispatcher($this->app->container());
        $events->listen($eventClass, $listenerClass);
        $sent = new $eventClass();
        self::assertSame($sent, $events->dispatch($sent));

        // A short event name means App\Events\..., no --event gives "object".
        $this->exec(['make:listener', 'Audit' . $suffix, '--event=UserRegistered']);
        self::assertStringContainsString('use App\Events\UserRegistered;', $this->file("app/Listeners/Audit{$suffix}.php"));
        $this->exec(['make:listener', 'Any' . $suffix]);
        self::assertStringContainsString('public function handle(object $event): void', $this->file("app/Listeners/Any{$suffix}.php"));

        [$code, , $err] = $this->exec(['make:listener', 'Bad' . $suffix, '--event=App\Events\1Bad']);
        self::assertSame(2, $code);
        self::assertStringContainsString('Invalid event class name', $err);
        self::assertFileDoesNotExist($this->root . "/app/Listeners/Bad{$suffix}.php");
    }

    public function testMakeMiddlewareCreatesAWorkingPsr15Middleware(): void
    {
        $suffix = bin2hex(random_bytes(3));

        self::assertSame([0, "Created app/Http/Middleware/Admin/RequireTwoFactor{$suffix}.php\n"], array_slice($this->exec(['make:middleware', 'Admin/RequireTwoFactor' . $suffix]), 0, 2));
        $code = $this->file("app/Http/Middleware/Admin/RequireTwoFactor{$suffix}.php");
        self::assertStringContainsString('namespace App\Http\Middleware\Admin;', $code);
        self::assertStringContainsString("final class RequireTwoFactor{$suffix} implements MiddlewareInterface", $code);

        // The generated middleware passes the request on and returns the handler's response.
        require $this->root . "/app/Http/Middleware/Admin/RequireTwoFactor{$suffix}.php";
        $class = "App\Http\Middleware\Admin\RequireTwoFactor{$suffix}";
        $middleware = new $class();
        self::assertInstanceOf(\Psr\Http\Server\MiddlewareInterface::class, $middleware);
        $factory = new \Nyholm\Psr7\Factory\Psr17Factory();
        $handler = new class ($factory) implements \Psr\Http\Server\RequestHandlerInterface {
            public function __construct(private readonly \Nyholm\Psr7\Factory\Psr17Factory $factory) {}

            public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                return $this->factory->createResponse(204);
            }
        };
        self::assertSame(204, $middleware->process($factory->createServerRequest('GET', '/x'), $handler)->getStatusCode());
    }

    public function testMakeMailCreatesAWorkingMailableAndView(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $class = 'Orders/OrderShipped' . $suffix;
        [$subNamespace, $short] = Generator::className($class);
        $snake = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $short));

        [$code, $out] = $this->exec(['make:mail', $class]);

        self::assertSame(0, $code);
        self::assertSame("Created app/Mail/Orders/{$short}.php\nCreated resources/views/mail/orders/{$snake}.php\n", $out);
        self::assertStringContainsString("->view('mail.orders.{$snake}'", $this->file("app/Mail/Orders/{$short}.php"));
        $this->file("resources/views/mail/orders/{$snake}.php");

        // The generated class and view send a real message.
        require $this->root . "/app/Mail/Orders/{$short}.php";
        $fqcn = 'App\\Mail\\' . $subNamespace . '\\' . $short;
        $mailable = new $fqcn('asha@example.com');
        self::assertInstanceOf(\VeyroPHP\Mail\Mailable::class, $mailable);
        $mailer = new \VeyroPHP\Mail\Mailer(
            static fn(): \VeyroPHP\Mail\Transport\ArrayTransport => new \VeyroPHP\Mail\Transport\ArrayTransport(),
            'array',
            new \VeyroPHP\Mail\Address('shop@example.com'),
            new \VeyroPHP\View\View($this->root . '/resources/views'),
        );
        $sent = $mailer->send($mailable);
        self::assertStringContainsString('Hello asha@example.com,', (string) $sent->message->getHtml());
        self::assertSame(['asha@example.com'], $sent->recipients);

        // Never overwrites, and creates nothing when either file exists.
        [$code, , $err] = $this->exec(['make:mail', $class]);
        self::assertSame(1, $code);
        self::assertStringContainsString('already exists; nothing was created', $err);
    }

    public function testMakeJobCreatesAQueueableJob(): void
    {
        $short = 'SendInvoice' . bin2hex(random_bytes(3));

        self::assertSame([0, "Created app/Jobs/Billing/{$short}.php\n"], array_slice($this->exec(['make:job', 'Billing/' . $short]), 0, 2));

        require $this->root . "/app/Jobs/Billing/{$short}.php";
        $class = 'App\\Jobs\\Billing\\' . $short;
        $job = new $class(42);
        self::assertInstanceOf(\VeyroPHP\Queue\Job::class, $job);

        // The generated job queues and runs.
        $driver = new \VeyroPHP\Queue\Driver\ArrayDriver();
        $payload = new \VeyroPHP\Queue\Payload();
        $queue = new \VeyroPHP\Queue\Queue(static fn(): \VeyroPHP\Queue\Driver\ArrayDriver => $driver, 'array', $payload);
        $queue->push($job);
        self::assertSame(3, $driver->jobs()[0]['tries']);
        self::assertSame([10, 60, 300], $driver->jobs()[0]['backoff']);

        $container = new \VeyroPHP\Container\Container();
        $container->instance(\Psr\Log\LoggerInterface::class, new \Psr\Log\NullLogger());
        $worker = new \VeyroPHP\Queue\Worker($queue, new \VeyroPHP\Queue\JobRunner($container, $payload));
        self::assertTrue($worker->runNextJob('array', ['default']));
        self::assertSame(0, $driver->size('default'), 'ran and was deleted');
    }

    public function testMakeFactoryAndSeederGenerateWorkingCode(): void
    {
        $suffix = bin2hex(random_bytes(3));

        // A model to build, written by make:model so the test uses generated code end to end.
        $this->exec(['make:model', 'Gadget' . $suffix, '--migration']);
        [$code, $out] = $this->exec(['make:factory', 'Gadget' . $suffix . 'Factory']);
        self::assertSame([0, "Created database/factories/Gadget{$suffix}Factory.php\n"], [$code, $out]);
        self::assertStringContainsString("use App\\Models\\Gadget{$suffix};", $this->file("database/factories/Gadget{$suffix}Factory.php"));
        self::assertSame(0, $this->exec(['make:seeder', 'Gadget' . $suffix . 'Seeder'])[0]);
        $this->file("database/seeders/Gadget{$suffix}Seeder.php");

        $migrator = new Migrator($this->app->container()->make(\VeyroPHP\Database\Connection\Connection::class), $this->root . '/database/migrations');
        $migrator->run();
        require $this->root . "/app/Models/Gadget{$suffix}.php";
        require $this->root . "/database/factories/Gadget{$suffix}Factory.php";
        require $this->root . "/database/seeders/Gadget{$suffix}Seeder.php";

        $factoryClass = 'Database\\Factories\\Gadget' . $suffix . 'Factory';
        $factory = $this->app->container()->get($factoryClass);
        self::assertInstanceOf(\VeyroPHP\Orm\Factory\Factory::class, $factory);
        self::assertCount(2, $factory->count(2)->create(), 'the generated factory creates rows');

        $seederClass = 'Database\\Seeders\\Gadget' . $suffix . 'Seeder';
        self::assertSame(0, $this->exec(['db:seed', '--class=' . $seederClass])[0]);

        self::assertSame(2, $this->exec(['make:factory', 'X' . $suffix . 'Factory', '--model=Bad Name'])[0]);
    }

    public function testMakeNotification(): void
    {
        $short = 'InvoicePaid' . bin2hex(random_bytes(3));

        self::assertSame([0, "Created app/Notifications/{$short}.php\n"], array_slice($this->exec(['make:notification', $short]), 0, 2));

        require $this->root . "/app/Notifications/{$short}.php";
        $class = 'App\\Notifications\\' . $short;
        $notification = new $class();
        self::assertInstanceOf(\VeyroPHP\Notifications\Notification::class, $notification);
        $user = new \VeyroPHP\Tests\Fixtures\Auth\AuthUser();
        self::assertSame(['mail', 'database'], $notification->via($user));
        $mail = $notification->toMail($user);
        self::assertInstanceOf(\VeyroPHP\Mail\Message::class, $mail);
        self::assertSame($short, $mail->getSubject());
        self::assertSame(['text' => $short], $notification->toDatabase($user));
    }

    public function testMakeMigrationWithoutTable(): void
    {
        [$code, $out] = $this->exec(['make:migration', 'add_slug_to_posts']);

        self::assertSame(0, $code);
        self::assertSame("Created database/migrations/2026_10_26_073320_add_slug_to_posts.php\n", $out);
        self::assertStringContainsString("// \$schema->table('posts'", $this->file('database/migrations/2026_10_26_073320_add_slug_to_posts.php'));
    }

    /**
     * CORE-11: a file that cannot be created is reported as such, not as "already exists".
     */
    public function testAFileThatCannotBeCreatedIsNotReportedAsExisting(): void
    {
        $locked = $this->root . '/locked';
        mkdir($locked);
        chmod($locked, 0o555);

        // Probe by creating a file: Windows reports the directory as not writable but still
        // allows new files in it, and root on Unix ignores the mode.
        if (@file_put_contents($locked . '/probe', '') !== false) {
            @unlink($locked . '/probe');
            chmod($locked, 0o777);
            self::markTestSkipped('This system lets the process create files in a read-only directory (Windows, or root on Unix).');
        }

        try {
            (new Generator($this->app))->write('locked/Thing.php', '<?php');
            self::fail('the unwritable file was reported as written');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('Could not create locked/Thing.php', $e->getMessage());
            self::assertStringNotContainsString('already exists', $e->getMessage());
        } finally {
            chmod($locked, 0o777);
        }

        self::assertFileDoesNotExist($locked . '/Thing.php');
    }

    public function testGeneratorsNeverOverwrite(): void
    {
        $this->exec(['make:controller', 'PostController']);
        file_put_contents($this->root . '/app/Http/Controllers/PostController.php', '<?php // edited by hand');

        [$code, $out, $err] = $this->exec(['make:controller', 'PostController']);
        self::assertSame(2, $code);
        self::assertSame('', $out);
        self::assertStringContainsString('app/Http/Controllers/PostController.php already exists; nothing was written.', $err);
        self::assertSame('<?php // edited by hand', file_get_contents($this->root . '/app/Http/Controllers/PostController.php'));

        $this->exec(['make:migration', 'create_tags_table']);
        [$code, , $err] = $this->exec(['make:migration', 'create_tags_table']);
        self::assertSame(2, $code);
        self::assertStringContainsString('A migration named create_tags_table already exists', $err);

        // A name that only ends with an existing one is a different migration.
        self::assertSame(0, $this->exec(['make:migration', 'recreate_tags_table'])[0]);
    }

    public function testInvalidNamesAreRejected(): void
    {
        $cases = [
            ['make:model', 'post'],
            ['make:model', '../Evil'],
            ['make:model', 'Class'],
            ['make:model', 'Post', '--table=Bad Table'],
            ['make:controller', 'Admin//X'],
            ['make:controller', 'Admin/'],
            ['make:migration', 'Create-Posts'],
            ['make:migration', '../x'],
        ];

        foreach ($cases as $arguments) {
            [$code, , $err] = $this->exec($arguments);
            self::assertSame(2, $code, implode(' ', $arguments) . ': ' . $err);
        }

        self::assertDirectoryDoesNotExist($this->root . '/app');
        self::assertDirectoryDoesNotExist($this->root . '/database');
    }

    public function testTableNames(): void
    {
        self::assertSame('posts', Generator::tableName('Post'));
        self::assertSame('blog_posts', Generator::tableName('BlogPost'));
        self::assertSame('categories', Generator::tableName('Category'));
        self::assertSame('days', Generator::tableName('Day'));
        self::assertSame('boxes', Generator::tableName('Box'));
        self::assertSame('addresses', Generator::tableName('Address'));
    }

    public function testMakeRequestCreatesAWorkingFormRequest(): void
    {
        [$code, $out] = $this->exec(['make:request', 'Admin/StoreEnquiry']);
        self::assertSame(0, $code, $out);
        $source = $this->file('app/Http/Requests/Admin/StoreEnquiry.php');
        self::assertStringContainsString('namespace App\Http\Requests\Admin;', $source);

        require $this->root . '/app/Http/Requests/Admin/StoreEnquiry.php';
        $class = 'App\Http\Requests\Admin\StoreEnquiry';
        self::assertTrue(is_subclass_of($class, \VeyroPHP\Validation\FormRequest::class));

        $form = $class::fromRequest((new \Nyholm\Psr7\ServerRequest('POST', '/'))->withParsedBody(['name' => 'Asha', 'x' => 1]), $this->app->container());
        self::assertSame(['name' => 'Asha'], $form->validated());
    }

    public function testMakePolicy(): void
    {
        [$code, $out] = $this->exec(['make:policy', 'PostPolicy']);
        self::assertSame(0, $code, $out);
        $policy = $this->file('app/Policies/PostPolicy.php');
        self::assertStringContainsString('namespace App\Policies;', $policy);
        self::assertStringContainsString('use App\Models\Post;', $policy);
        self::assertStringContainsString('public function view(?\App\Models\User $user, Post $post): bool', $policy);
        self::assertStringContainsString('public function update(\App\Models\User $user, Post $post): bool', $policy);

        [$code] = $this->exec(['make:policy', 'Admin/InvoicePolicy', '--model=Billing\\Invoice']);
        self::assertSame(0, $code);
        $invoice = $this->file('app/Policies/Admin/InvoicePolicy.php');
        self::assertStringContainsString('namespace App\Policies\Admin;', $invoice);
        self::assertStringContainsString('use Billing\Invoice;', $invoice);

        [$code, , $error] = $this->exec(['make:policy', 'BadPolicy', '--model=lower\\case']);
        self::assertNotSame(0, $code);
        self::assertStringContainsString('Invalid model class name', $error);
    }

    public function testTargetsBelowTheBaseNamespaceAndDirectory(): void
    {
        self::assertSame(['App\\Jobs', 'SendMail', 'app/Jobs/SendMail.php', ''], Generator::target('SendMail', 'App\\Jobs', 'app/Jobs'));
        self::assertSame(
            ['App\\Http\\Controllers\\Admin\\Shop', 'PostController', 'app/Http/Controllers/Admin/Shop/PostController.php', 'Admin/Shop/'],
            Generator::target('Admin\\Shop/PostController', 'App\\Http\\Controllers', 'app/Http/Controllers'),
        );
    }

    public function testServeBuildsTheServerCommand(): void
    {
        $commands = [];
        $serve = new ServeCommand($this->app, static function (array $command) use (&$commands): int {
            $commands[] = $command;

            return 0;
        });
        $this->app->container()->instance(ServeCommand::class, $serve);

        [$code, $out] = $this->exec(['serve', '--port=8080']);
        self::assertSame(0, $code);
        self::assertStringContainsString('Development server: http://127.0.0.1:8080', $out);
        self::assertSame([PHP_BINARY, '-S', '127.0.0.1:8080', '-t', $this->app->basePath('public'), ServeCommand::routerScript()], $commands[0]);
        self::assertFileExists(ServeCommand::routerScript());

        [, , $err] = $this->exec(['serve', '--host=0.0.0.0']);
        self::assertStringContainsString('other computers can reach', $err);

        $this->exec(['serve', '--host=::1', '--port=9000']);
        self::assertSame('[::1]:9000', $commands[2][2]);

        foreach ([['serve', '--host=bad host;rm'], ['serve', '--port=70000'], ['serve', '--port=0'], ['serve', '--port=abc']] as $arguments) {
            self::assertSame(2, $this->exec($arguments)[0], implode(' ', $arguments));
        }
        self::assertCount(3, $commands);

        unlink($this->root . '/public/index.php');
        [$code, , $err] = $this->exec(['serve']);
        self::assertSame(1, $code);
        self::assertStringContainsString('public/index.php was not found', $err);
    }
}
