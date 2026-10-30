<?php

namespace Oilstone\ApiTypesenseIntegration\Integrations\SystemStatus;

use Illuminate\Contracts\Config\Repository;
use Oilstone\SystemStatus\Concerns\MakesHttpRequests;
use Oilstone\SystemStatus\Exceptions\InvalidMonitorConfiguration;
use Oilstone\SystemStatus\Monitors\Monitor;
use Oilstone\SystemStatus\Probe;
use Psr\Http\Client\ClientInterface;
use Throwable;
use Typesense\Client;
use Typesense\Exceptions\ObjectNotFound;
use Typesense\Exceptions\TypesenseClientError;

/**
 * Checks the health of every Typesense node in scout.typesense, each on its own and without
 * retries, so a down node shows up even while the SDK could fail over to another.
 *
 * Options:
 * - collections: collection or alias names that must exist, without the Scout prefix, which is
 *   added as it is for searchableAs()
 * - timeout, connect_timeout and options: see MakesHttpRequests
 */
class TypesenseMonitor extends Monitor
{
    use MakesHttpRequests;

    protected string $name = 'Typesense';

    protected array $thresholds = [500 => 'optimal', 2000 => 'acceptable'];

    /**
     * @param array $config
     * @param \Illuminate\Contracts\Config\Repository $settings
     */
    public function __construct(array $config, protected Repository $settings)
    {
        parent::__construct($config);
    }

    /**
     * @param \Oilstone\SystemStatus\Probe $probe
     */
    public function check(Probe $probe): void
    {
        $http = $this->sdkHttpClient();
        $nodes = $this->nodes();
        $healthy = null;
        $unhealthy = [];

        foreach ($nodes as $node) {
            try {
                $client = $this->makeClient($node, $http);
                $health = $client->getHealth()->retrieve();

                if (($health['ok'] ?? null) === true) {
                    $healthy ??= $client;

                    continue;
                }

                $reason = 'reported ' . ($health['resource_error'] ?? 'unhealthy');
            } catch (Throwable $exception) {
                $reason = $this->describeException($exception);
            }

            $unhealthy[] = sprintf('%s:%s: %s', $node['host'], $node['port'] ?? '', $reason);
        }

        $probe->meta('healthy_nodes', sprintf('%d/%d', count($nodes) - count($unhealthy), count($nodes)));

        if (!$healthy) {
            $this->fail('No node is healthy: ' . implode('; ', $unhealthy));
        }

        foreach ($unhealthy as $description) {
            $probe->degrade($description);
        }

        $this->checkCollections($probe, $healthy);
    }

    /**
     * Validate the Typesense settings, leaving out host-less nodes so that a missing host reports
     * as missing configuration rather than an SDK error.
     */
    protected function configure(): void
    {
        $missing = [];

        if ($this->blank($this->typesense()['api_key'] ?? null)) {
            $missing[] = 'scout.typesense.api_key';
        }

        if (!$this->nodes()) {
            $missing[] = 'scout.typesense.nodes';
        }

        if ($missing) {
            throw InvalidMonitorConfiguration::missing($this->name(), $missing);
        }

        $collections = $this->config['collections'] ?? [];

        if (!is_array($collections) || array_filter($collections, fn ($name) => !is_string($name) || $name === '')) {
            throw InvalidMonitorConfiguration::invalid($this->name(), 'collections must be a list of collection names');
        }
    }

    /**
     * @param \Oilstone\SystemStatus\Probe $probe
     * @param \Typesense\Client $client
     */
    protected function checkCollections(Probe $probe, Client $client): void
    {
        $prefix = (string) $this->settings->get('scout.prefix', '');
        $documents = [];

        foreach ($this->config['collections'] ?? [] as $collection) {
            $name = $prefix . $collection;

            try {
                $retrieved = $client->getCollections()[$name]->retrieve();
            } catch (ObjectNotFound $exception) {
                $this->fail("The {$name} collection does not exist", $exception);
            } catch (Throwable $exception) {
                $this->fail("Retrieving the {$name} collection failed: {$this->redactUrls($exception->getMessage())}", $exception);
            }

            $documents[$name] = $retrieved['num_documents'] ?? null;
            $probe->meta('documents', $documents);

            if ($documents[$name] === 0) {
                $probe->degrade("The {$name} collection has no documents");
            }
        }
    }

    /**
     * A client for one node, without retries or failover, using the monitor's timeouts.
     *
     * @param array $node
     * @param \Psr\Http\Client\ClientInterface $http
     *
     * @throws \Typesense\Exceptions\ConfigError
     *
     * @return \Typesense\Client
     */
    protected function makeClient(array $node, ClientInterface $http): Client
    {
        $typesense = $this->typesense();

        return new Client([
            'api_key' => $typesense['api_key'],
            'log_level' => $typesense['log_level'] ?? null,
            'nodes' => [$node],
            'num_retries' => 0,
            'randomize_nodes' => false,
            'client' => $http,
        ]);
    }

    /**
     * The configured nodes and nearest node, de-duplicated, leaving out any without a host.
     *
     * @return list<array>
     */
    protected function nodes(): array
    {
        $typesense = $this->typesense();
        $nodes = [];

        foreach ([...(array) ($typesense['nodes'] ?? []), $typesense['nearest_node'] ?? null] as $node) {
            if (!is_array($node) || $this->blank($node['host'] ?? null)) {
                continue;
            }

            $key = implode('|', [
                strtolower((string) ($node['protocol'] ?? '')),
                strtolower((string) $node['host']),
                (string) ($node['port'] ?? ''),
                (string) ($node['path'] ?? ''),
            ]);

            $nodes[$key] ??= $node;
        }

        return array_values($nodes);
    }

    /**
     * @return array
     */
    protected function typesense(): array
    {
        return (array) $this->settings->get('scout.typesense', []);
    }

    /**
     * The SDK reduces message-less errors (such as an unhealthy node's 503) to "API error.", so
     * its exceptions are named.
     *
     * @param \Throwable $exception
     *
     * @return string
     */
    protected function describeException(Throwable $exception): string
    {
        $message = $this->redactUrls($exception->getMessage());

        return $exception instanceof TypesenseClientError ? class_basename($exception) . ': ' . $message : $message;
    }
}
