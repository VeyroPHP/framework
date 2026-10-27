<?php

declare(strict_types=1);

namespace VeyroPHP\Filesystem;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use VeyroPHP\Application\Application;
use VeyroPHP\Application\ServiceProvider;
use VeyroPHP\Config\Config;
use VeyroPHP\Container\Container;

/**
 * Registers FilesystemManager, the default Disk and FileResponse.
 *
 * Config (config/filesystems.php):
 *
 *     'default' => 'local',
 *     'disks' => [
 *         'local' => ['driver' => 'local', 'root' => 'storage/app/private'],
 *         'public' => ['driver' => 'local', 'root' => 'storage/app/public', 'url' => 'https://example.com/storage'],
 *         's3' => ['driver' => 's3', 'key' => '...', 'secret' => '...', 'region' => 'ap-south-1', 'bucket' => '...',
 *                  'endpoint' => null, 'path_style' => false, 'prefix' => '', 'url' => null],
 *     ],
 *
 * The s3 driver needs a PSR-18 HTTP client bound as ClientInterface, or
 * guzzlehttp/guzzle installed.
 */
final class FilesystemServiceProvider implements ServiceProvider
{
    /** @var list<class-string<\VeyroPHP\Console\Command>> */
    public const COMMANDS = [Console\StorageLinkCommand::class];

    public function register(Application $app): void
    {
        $container = $app->container();

        $container->singleton(FilesystemManager::class, static function (Container $c) use ($app): FilesystemManager {
            $config = $c->make(Config::class);
            $default = $config->get('filesystems.default', 'local');

            return new FilesystemManager(
                static fn(string $name): Disk => self::disk($name, $config, $c, $app),
                is_string($default) ? $default : 'local',
            );
        });

        $container->bind(Disk::class, static fn(Container $c): Disk => $c->make(FilesystemManager::class)->disk());
        $container->bind(FileResponse::class, static fn(): FileResponse => new FileResponse(new Psr17Factory(), new Psr17Factory()));
    }

    private static function disk(string $name, Config $config, Container $c, Application $app): Disk
    {
        $settings = $config->get('filesystems.disks.' . $name);

        if ($settings === null && $name === 'local') {
            $settings = ['driver' => 'local', 'root' => 'storage/app/private'];
        }

        if (!is_array($settings)) {
            throw new FilesystemException(sprintf('No disk "%s" is configured in config/filesystems.php.', $name));
        }

        $string = static fn(string $key, ?string $default = null): ?string => is_string($settings[$key] ?? null) && $settings[$key] !== '' ? $settings[$key] : $default;

        return match ($settings['driver'] ?? null) {
            'local' => new LocalDisk($app->basePath((string) $string('root', 'storage/app/private')), $string('url')),
            's3' => self::s3($string, $settings, $c),
            default => throw new FilesystemException(sprintf('Disk "%s" has an unknown driver; use local or s3.', $name)),
        };
    }

    /**
     * @param \Closure(string, ?string=): ?string $string
     * @param array<mixed> $settings
     */
    private static function s3(\Closure $string, array $settings, Container $c): S3Disk
    {
        $key = $string('key');
        $secret = $string('secret');
        $bucket = $string('bucket');
        $region = (string) $string('region', 'us-east-1');

        if ($key === null || $secret === null || $bucket === null) {
            throw new FilesystemException('The s3 disk needs key, secret and bucket.');
        }

        if ($c->bound(ClientInterface::class)) {
            $client = $c->make(ClientInterface::class);
        } elseif (class_exists(\GuzzleHttp\Client::class)) {
            $client = new \GuzzleHttp\Client(['timeout' => 30, 'http_errors' => false]);
        } else {
            throw new FilesystemException('The s3 disk needs an HTTP client: composer require guzzlehttp/guzzle.');
        }

        $factory = new Psr17Factory();

        return new S3Disk(
            $client,
            $factory,
            $factory,
            new S3Signer($key, $secret, $region),
            $bucket,
            (string) $string('endpoint', 'https://s3.' . $region . '.amazonaws.com'),
            ($settings['path_style'] ?? false) === true,
            (string) $string('prefix', ''),
            $string('url'),
        );
    }
}
