<?php

declare(strict_types=1);

namespace VeyroPHP\Config;

use VeyroPHP\Application\Application;
use VeyroPHP\Application\ServiceProvider;
use VeyroPHP\Config\Env\Env;
use VeyroPHP\Config\Env\EnvLoader;

/**
 * Loads "<base>/.env" and "<base>/config" and registers Env and Config as instances.
 *
 * A missing .env file or config directory is allowed and gives empty values,
 * so small applications and tests need neither.
 *
 * When "bootstrap/cache/config.php" exists (php veyro config:cache), it is
 * used instead and neither .env nor config/ is read; Env then holds only the
 * real process environment. Set the environment variable
 * VEYROPHP_CONFIG_CACHE=0 to ignore the cache (the starter app's tests do).
 */
final class ConfigServiceProvider implements ServiceProvider
{
    /**
     * @param bool|null $useCache null: use the cache unless VEYROPHP_CONFIG_CACHE=0
     */
    public function __construct(
        private readonly EnvLoader $envLoader = new EnvLoader(),
        private readonly ?bool $useCache = null,
    ) {}

    public function register(Application $app): void
    {
        $cached = $this->usesCache() ? ConfigCache::read($app->basePath(ConfigCache::RELATIVE_PATH)) : null;

        if ($cached !== null) {
            $env = $this->envLoader->load('');
            $config = new Config($cached);
        } else {
            $env = $this->envLoader->load($app->basePath('.env'));
            $config = self::load($app, $env);
        }

        $app->container()->instance(Env::class, $env);
        $app->container()->instance(Config::class, $config);
    }

    /**
     * Reads .env and config/ directly, ignoring any cache (used by config:cache).
     */
    public static function fresh(Application $app, EnvLoader $envLoader = new EnvLoader()): Config
    {
        return self::load($app, $envLoader->load($app->basePath('.env')));
    }

    private static function load(Application $app, Env $env): Config
    {
        $configDirectory = $app->basePath('config');

        return is_dir($configDirectory) ? (new ConfigLoader())->load($configDirectory, $env) : new Config();
    }

    private function usesCache(): bool
    {
        if ($this->useCache !== null) {
            return $this->useCache;
        }

        $setting = getenv('VEYROPHP_CONFIG_CACHE');

        return !in_array(is_string($setting) ? strtolower($setting) : '', ['0', 'false', 'off', 'no'], true);
    }
}
