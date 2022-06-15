<?php

namespace Oilstone\ApiTypesenseIntegration;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;
use Laravel\Scout\Builder as ScoutBuilder;
use Laravel\Scout\EngineManager;
use Laravel\Scout\Scout;
use Oilstone\ApiTypesenseIntegration\Engines\TypesenseEngine;
use Oilstone\ApiTypesenseIntegration\Jobs\MakeSearchable;
use Oilstone\ApiTypesenseIntegration\Jobs\RemoveFromSearch;
use Oilstone\RsqlParser\Operators;
use Oilstone\RsqlParser\Operators\Operator;
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
        $this->app[EngineManager::class]->extend('typesense', static function ($app) {
            $client = new Client(Config::get('scout.typesense'));

            return new TypesenseEngine(new Typesense($client));
        });
    }

    /**
     * Register singletons and aliases.
     */
    public function register(): void
    {
        $this->app->singleton(Typesense::class, static function () {
            $client = new Client(Config::get('scout.typesense'));

            return new Typesense($client);
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
    }
}
