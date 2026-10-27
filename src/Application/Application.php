<?php

declare(strict_types=1);

namespace VeyroPHP\Application;

use Closure;
use VeyroPHP\Application\Exception\ApplicationException;
use VeyroPHP\Container\Container;
use VeyroPHP\Exception\InvalidPathException;

/**
 * Root object of a VeyroPHP application.
 *
 * Lifecycle: create the application, register service providers, then call
 * boot() once. Providers are registered and booted in the order they were added.
 */
final class Application
{
    public const VERSION = '0.1.0-dev';

    private readonly string $basePath;

    private readonly Container $container;

    /** @var array<class-string<ServiceProvider>, ServiceProvider> */
    private array $providers = [];

    /** @var list<Closure(Application): void> */
    private array $beforeBoot = [];

    /** @var list<Closure(Application): void> */
    private array $afterBoot = [];

    private bool $booted = false;

    /**
     * @throws InvalidPathException when the directory does not exist
     */
    public function __construct(string $basePath, ?Container $container = null)
    {
        if ($basePath === '') {
            throw InvalidPathException::emptyBasePath();
        }

        if (str_contains($basePath, "\0")) {
            throw InvalidPathException::containsNullByte();
        }

        $resolved = realpath($basePath);

        if ($resolved === false || !is_dir($resolved)) {
            throw InvalidPathException::baseDirectoryNotFound($basePath);
        }

        $this->basePath = rtrim($resolved, '/\\');
        $this->container = $container ?? new Container();
        $this->container->instance(self::class, $this);
    }

    public function version(): string
    {
        return self::VERSION;
    }

    public function container(): Container
    {
        return $this->container;
    }

    /**
     * Registers a provider and calls its register() method immediately.
     *
     * @throws ApplicationException when already booted or the provider class is already registered
     */
    public function register(ServiceProvider $provider): void
    {
        if ($this->booted) {
            throw ApplicationException::alreadyBooted('register ' . $provider::class);
        }

        if (isset($this->providers[$provider::class])) {
            throw ApplicationException::duplicateProvider($provider::class);
        }

        $this->providers[$provider::class] = $provider;
        $provider->register($this);
    }

    /**
     * @return list<ServiceProvider> in registration order
     */
    public function providers(): array
    {
        return array_values($this->providers);
    }

    /**
     * @param Closure(Application): void $callback
     *
     * @throws ApplicationException when already booted
     */
    public function beforeBoot(Closure $callback): void
    {
        if ($this->booted) {
            throw ApplicationException::alreadyBooted('add a beforeBoot callback');
        }

        $this->beforeBoot[] = $callback;
    }

    /**
     * @param Closure(Application): void $callback
     *
     * @throws ApplicationException when already booted
     */
    public function afterBoot(Closure $callback): void
    {
        if ($this->booted) {
            throw ApplicationException::alreadyBooted('add an afterBoot callback');
        }

        $this->afterBoot[] = $callback;
    }

    /**
     * Runs beforeBoot callbacks, boots providers, then runs afterBoot callbacks.
     *
     * If any step throws, the application stays unbooted and the exception propagates.
     *
     * @throws ApplicationException when called a second time
     */
    public function boot(): void
    {
        if ($this->booted) {
            throw ApplicationException::alreadyBooted('boot');
        }

        foreach ($this->beforeBoot as $callback) {
            $callback($this);
        }

        foreach ($this->providers as $provider) {
            if ($provider instanceof BootableProvider) {
                $provider->boot($this);
            }
        }

        $this->booted = true;

        foreach ($this->afterBoot as $callback) {
            $callback($this);
        }
    }

    public function isBooted(): bool
    {
        return $this->booted;
    }

    /**
     * Returns the base path, or a path inside it when $path is given.
     *
     * The relative path is normalized lexically: "." segments and repeated
     * separators are removed. Absolute paths and ".." segments are rejected so
     * the result can never point outside the base path. Symlinks inside the
     * base path are not resolved.
     *
     * @throws InvalidPathException when $path is absolute or tries to escape the base path
     */
    public function basePath(string $path = ''): string
    {
        if ($path === '') {
            return $this->basePath;
        }

        if (str_contains($path, "\0")) {
            throw InvalidPathException::containsNullByte();
        }

        if (self::isAbsolute($path)) {
            throw InvalidPathException::absoluteNotAllowed($path);
        }

        $segments = [];

        foreach (preg_split('#[/\\\\]+#', $path) ?: [] as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                throw InvalidPathException::traversalNotAllowed($path);
            }

            $segments[] = $segment;
        }

        if ($segments === []) {
            return $this->basePath;
        }

        return $this->basePath . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $segments);
    }

    private static function isAbsolute(string $path): bool
    {
        return $path[0] === '/'
            || $path[0] === '\\'
            || preg_match('#^[A-Za-z]:#', $path) === 1
            || str_contains($path, '://');
    }
}
