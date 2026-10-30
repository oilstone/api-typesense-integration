<?php

namespace Oilstone\ApiTypesenseIntegration;

use GuzzleHttp\Client as GuzzleClient;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;
use Laravel\Scout\Builder as ScoutBuilder;
use Laravel\Scout\EngineManager;
use Laravel\Scout\Scout;
use Oilstone\ApiTypesenseIntegration\Engines\TypesenseEngine;
use Oilstone\ApiTypesenseIntegration\Integrations\SystemStatus\TypesenseMonitor;
use Oilstone\ApiTypesenseIntegration\Jobs\MakeSearchable;
use Oilstone\ApiTypesenseIntegration\Jobs\RemoveFromSearch;
use Oilstone\RsqlParser\Operators;
use Oilstone\RsqlParser\Operators\Operator;
use Oilstone\SystemStatus\SystemStatus;
use Typesense\Client;

/**
 * Class TypesenseServiceProvider.
 *
 * @date    4/5/20
 *
 * @author  Abdullah Al-Faqeir <abdullah@devloops.net>
 */
class TypesenseServiceProvider extends ServiceProvider
{
    /**
     * All of the container bindings that should be registered.
     *
     * @var array
     */
    public $bindings = [
        ScoutBuilder::class => Builder::class,
    ];

    /**
     * @throws \ReflectionException
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     */
    public function boot(): void
    {
        // The manager rebinds extend() callbacks to itself, so the client factory is passed in
        $makeClient = $this->makeClient(...);

        $this->app[EngineManager::class]->extend('typesense', static function ($app) use ($makeClient) {
            return new TypesenseEngine(new Typesense($makeClient()));
        });

        if (class_exists(SystemStatus::class)) {
            $this->callAfterResolving(SystemStatus::class, static function (SystemStatus $status) {
                $status->extend('typesense', TypesenseMonitor::class);
            });
        }
    }

    /**
     * Register singletons and aliases.
     */
    public function register(): void
    {
        $this->app->singleton(Typesense::class, function () {
            return new Typesense($this->makeClient());
        });

        $this->app->alias(Typesense::class, 'typesense');

        Scout::makeSearchableUsing(MakeSearchable::class);
        Scout::removeFromSearchUsing(RemoveFromSearch::class);

        Operators::custom(new class extends Operator {
            protected $uri = '=has=';
            protected $sql = 'has';
        });

        Operators::custom(new class extends Operator {
            protected $uri = '=contains=';
            protected $sql = 'contains';
        });

        Operators::custom(new class extends Operator {
            protected $uri = '=near=';
            protected $sql = 'near';
            protected $expectsArray = true;
        });
    }

    /**
     * Build a client from scout.typesense. typesense-php no longer applies any timeouts, so when
     * Guzzle is installed and no client is configured, it is given one with connection_timeout_seconds
     * as its connect timeout (default 1, as typesense-php used to apply) and request_timeout_seconds
     * as its request timeout (no limit by default, so large imports aren't cut off).
     *
     * @throws \Typesense\Exceptions\ConfigError
     *
     * @return \Typesense\Client
     */
    protected function makeClient(): Client
    {
        $config = Config::get('scout.typesense');

        if (!isset($config['client']) && class_exists(GuzzleClient::class)) {
            $config['client'] = new GuzzleClient(array_filter([
                'connect_timeout' => $config['connection_timeout_seconds'] ?? 1,
                'timeout'         => $config['request_timeout_seconds'] ?? null,
            ], static fn ($value) => $value !== null));
        }

        return new Client($config);
    }
}
