<?php

declare(strict_types=1);

namespace VeyroPHP\Console;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use VeyroPHP\Application\Application;
use VeyroPHP\Application\ServiceProvider;
use VeyroPHP\Cache\Console\PruneCommand;
use VeyroPHP\Config\Config;
use VeyroPHP\Config\Console\CacheCommand as ConfigCacheCommand;
use VeyroPHP\Config\Console\ClearCommand as ConfigClearCommand;
use VeyroPHP\Console\Make\MakeControllerCommand;
use VeyroPHP\Console\Make\MakeEventCommand;
use VeyroPHP\Console\Make\MakeFactoryCommand;
use VeyroPHP\Console\Make\MakeJobCommand;
use VeyroPHP\Console\Make\MakeListenerCommand;
use VeyroPHP\Console\Make\MakeMailCommand;
use VeyroPHP\Console\Make\MakeMiddlewareCommand;
use VeyroPHP\Console\Make\MakeMigrationCommand;
use VeyroPHP\Console\Make\MakeModelCommand;
use VeyroPHP\Console\Make\MakeNotificationCommand;
use VeyroPHP\Console\Make\MakePolicyCommand;
use VeyroPHP\Console\Make\MakeRequestCommand;
use VeyroPHP\Console\Make\MakeSeederCommand;
use VeyroPHP\Container\Container;
use VeyroPHP\Database\Console\MigrateCommand;
use VeyroPHP\Database\Console\RollbackCommand;
use VeyroPHP\Database\Console\SeedCommand;
use VeyroPHP\Database\Console\StatusCommand;
use VeyroPHP\Encryption\Console\KeyGenerateCommand;
use VeyroPHP\Http\Console\DownCommand;
use VeyroPHP\Http\Console\HealthCommand;
use VeyroPHP\Http\Console\ServeCommand;
use VeyroPHP\Http\Console\UpCommand;
use VeyroPHP\Package\Console\DiscoverCommand;
use VeyroPHP\Package\Console\PublishCommand;
use VeyroPHP\Port\Console\PortAnalyzeCommand;
use VeyroPHP\Port\Console\PortConvertCommand;
use VeyroPHP\Routing\Console\RouteListCommand;
use VeyroPHP\Security\Console\AuditCommand;
use VeyroPHP\View\Console\LintCommand;

/**
 * Registers the Console with the framework's built-in commands plus the given
 * application commands, those listed in config "console.commands" and those
 * that service providers (packages) added to the CommandRegistry.
 *
 * Built-in commands are always listed; their dependencies (for example the
 * database for migrate) are only needed when they run. Optional modules'
 * commands, such as RbacSeedCommand, are added by the application.
 */
final class ConsoleServiceProvider implements ServiceProvider
{
    /** @var list<class-string<Command>> */
    public const BUILT_IN = [
        MigrateCommand::class,
        RollbackCommand::class,
        StatusCommand::class,
        SeedCommand::class,
        RouteListCommand::class,
        PruneCommand::class,
        ConfigCacheCommand::class,
        ConfigClearCommand::class,
        KeyGenerateCommand::class,
        MakeModelCommand::class,
        MakeControllerCommand::class,
        MakeMigrationCommand::class,
        MakeEventCommand::class,
        MakeJobCommand::class,
        MakeListenerCommand::class,
        MakeMiddlewareCommand::class,
        MakeMailCommand::class,
        MakeFactoryCommand::class,
        MakePolicyCommand::class,
        MakeRequestCommand::class,
        MakeSeederCommand::class,
        MakeNotificationCommand::class,
        ServeCommand::class,
        DownCommand::class,
        UpCommand::class,
        HealthCommand::class,
        DiscoverCommand::class,
        PublishCommand::class,
        AuditCommand::class,
        PortAnalyzeCommand::class,
        PortConvertCommand::class,
        LintCommand::class,
    ];

    /**
     * @param list<class-string<Command>> $commands application commands
     */
    public function __construct(
        private readonly array $commands = [],
        private readonly string $binary = 'veyro',
    ) {}

    public function register(Application $app): void
    {
        $commands = $this->commands;
        $binary = $this->binary;

        $app->container()->singleton(Console::class, static function (Container $c) use ($app, $commands, $binary): Console {
            $config = $c->bound(Config::class) ? $c->make(Config::class) : new Config([]);
            $fromConfig = [];

            foreach ((array) $config->get('console.commands', []) as $class) {
                if (is_string($class)) {
                    /** @var class-string<Command> $class validated by Console */
                    $fromConfig[] = $class;
                }
            }

            return new Console(
                $c,
                array_values(array_unique([...self::BUILT_IN, ...$commands, ...$fromConfig, ...($c->bound(CommandRegistry::class) ? $c->make(CommandRegistry::class)->all() : [])])),
                $binary,
                $app->version(),
                $c->bound(LoggerInterface::class) ? $c->make(LoggerInterface::class) : new NullLogger(),
                $config->get('app.debug', false) === true,
            );
        });
    }
}
