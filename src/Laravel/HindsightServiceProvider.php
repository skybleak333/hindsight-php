<?php

declare(strict_types=1);

namespace Fedoseev\Hindsight\Laravel;

use Fedoseev\Hindsight\Hindsight;
use Fedoseev\Hindsight\Laravel\Console\StatusCommand;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Psr\Http\Client\ClientInterface;
use Psr\Log\LoggerInterface;

final class HindsightServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/hindsight.php', 'hindsight');

        $this->app->singleton(Hindsight::class, static function (Application $app): Hindsight {
            /** @var array{url?: string, api_key?: string|null, timeout?: float|int|string, headers?: array<string, string>} $config */
            $config = $app->make(Repository::class)->get('hindsight', []);

            // Bind a PSR-18 client as "hindsight.http" to add retries, a proxy or logging.
            $httpClient = $app->bound('hindsight.http') ? $app->make('hindsight.http') : null;

            return new Hindsight(
                baseUrl: $config['url'] ?? 'http://localhost:8888',
                apiKey: ($config['api_key'] ?? null) ?: null,
                httpClient: $httpClient instanceof ClientInterface ? $httpClient : null,
                timeout: (float) ($config['timeout'] ?? 120),
                headers: $config['headers'] ?? [],
                logger: $app->make(LoggerInterface::class),
            );
        });

        $this->app->alias(Hindsight::class, 'hindsight');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../../config/hindsight.php' => $this->app->configPath('hindsight.php'),
            ], 'hindsight-config');

            $this->commands([StatusCommand::class]);
        }
    }
}
