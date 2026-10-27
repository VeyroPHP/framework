<?php

declare(strict_types=1);

namespace VeyroPHP\Cache;

use Closure;
use VeyroPHP\Cache\Exception\InvalidCacheKeyException;
use VeyroPHP\Support\RedisConnector;

/**
 * Creates and caches named Cache instances from configuration.
 *
 *     [
 *         'default' => 'file',
 *         'prefix' => 'app',
 *         'default_ttl' => null,
 *         'allowed_classes' => false,      // classes unserialize() may restore (file/redis)
 *         'stores' => [
 *             'array' => ['driver' => 'array'],
 *             'file' => ['driver' => 'file', 'path' => '/abs/path/storage/cache'],
 *             'redis' => ['driver' => 'redis', 'host' => '127.0.0.1', 'port' => 6379, 'password' => null, 'database' => 0, 'timeout' => 1.0, 'read_timeout' => 5.0],
 *         ],
 *     ]
 */
final class CacheManager
{
    /** @var array<string, Cache> */
    private array $caches = [];

    /**
     * @param array<array-key, mixed> $config
     * @param Closure(string): string $resolvePath turns a relative file-store path into an absolute one
     */
    public function __construct(
        private readonly array $config,
        private readonly Closure $resolvePath,
    ) {}

    public function store(?string $name = null): Cache
    {
        $name ??= $this->defaultStore();

        return $this->caches[$name] ??= $this->create($name);
    }

    public function defaultStore(): string
    {
        $default = $this->config['default'] ?? null;

        if (!is_string($default) || $default === '') {
            throw InvalidCacheKeyException::invalidConfig('*', 'cache.default must name a store');
        }

        return $default;
    }

    private function create(string $name): Cache
    {
        $stores = $this->config['stores'] ?? [];
        $settings = is_array($stores) ? ($stores[$name] ?? null) : null;

        if (!is_array($settings)) {
            throw InvalidCacheKeyException::invalidConfig($name, 'the store is not configured');
        }

        $allowed = $this->config['allowed_classes'] ?? false;
        if (!is_bool($allowed) && !(is_array($allowed) && array_is_list($allowed) && array_filter($allowed, 'is_string') === $allowed)) {
            throw InvalidCacheKeyException::invalidConfig($name, 'allowed_classes must be a boolean or a list of class names');
        }
        /** @var list<class-string>|bool $allowed */

        $store = match ($settings['driver'] ?? null) {
            'array' => new ArrayStore(),
            'file' => new FileStore(($this->resolvePath)(self::string($settings, 'path', $name)), $allowed),
            'redis' => new RedisStore(RedisConnector::connect(
                $settings,
                static fn(string $problem): InvalidCacheKeyException => InvalidCacheKeyException::invalidConfig($name, $problem),
                null,
                1.0,
            ), $allowed),
            default => throw InvalidCacheKeyException::invalidConfig($name, 'driver must be "array", "file" or "redis"'),
        };

        $prefix = $this->config['prefix'] ?? '';
        $ttl = $this->config['default_ttl'] ?? null;

        if (!is_string($prefix) || ($ttl !== null && !is_int($ttl))) {
            throw InvalidCacheKeyException::invalidConfig($name, 'prefix must be a string and default_ttl an int or null');
        }

        return new Cache($store, $prefix, $ttl);
    }

    /**
     * @param array<array-key, mixed> $settings
     */
    private static function string(array $settings, string $key, string $name): string
    {
        $value = $settings[$key] ?? null;

        if (!is_string($value) || $value === '') {
            throw InvalidCacheKeyException::invalidConfig($name, sprintf('"%s" must be a non-empty string', $key));
        }

        return $value;
    }
}
