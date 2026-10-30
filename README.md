# Typesense API integration
Integration service classes to connect [garethhudson07/api](https://github.com/garethhudson07/api) resources with a Typesense index.

Forked from the [typesense/laravel-scout-typesense-driver](https://github.com/typesense/laravel-scout-typesense-driver) repository.

## Configuration

The Typesense client is built from `scout.typesense` in `config/scout.php`, which is passed to [typesense-php](https://github.com/typesense/typesense-php) as its configuration:

```php
'typesense' => [
    'api_key' => env('TYPESENSE_API_KEY'),
    'nodes' => [
        [
            'host' => env('TYPESENSE_HOST'),
            'port' => env('TYPESENSE_PORT', '443'),
            'path' => env('TYPESENSE_PATH', ''),
            'protocol' => env('TYPESENSE_PROTOCOL', 'https'),
        ],
    ],
    'num_retries' => 3,
    'retry_interval_seconds' => 1,
    'connection_timeout_seconds' => 1,
    'request_timeout_seconds' => null,
],
```

### Timeouts

typesense-php no longer applies any timeouts itself, so a hung node could hold a request indefinitely. When [guzzlehttp/guzzle](https://github.com/guzzle/guzzle) is installed and no `client` is configured, the client is given a Guzzle client with:

| Setting | Purpose |
| --- | --- |
| `connection_timeout_seconds` | Seconds to connect to a node (default 1) |
| `request_timeout_seconds` | Seconds for the whole request (default no limit, so imports of large batches aren't cut off) |

Without Guzzle, or when you set your own `client`, these settings are ignored and the client is used as configured, or found through php-http discovery.

Each failed request is retried on the next node, so the worst case for a request to an unreachable cluster is roughly `(num_retries + 1) × (connection_timeout_seconds + retry_interval_seconds)`: about 8 seconds with the settings above.

> **Change of behaviour:** apps that set `connection_timeout_seconds` and have Guzzle installed now get that connect timeout applied, where it was previously ignored by typesense-php v6. Apps that don't set it get a 1 second connect timeout.

## System status monitor

With [oilstone/system-status](https://github.com/oilstone/system-status) and guzzlehttp/guzzle installed, the `typesense` monitor type checks the health of the Typesense cluster in `scout.typesense`:

| Option | Purpose |
| --- | --- |
| `collections` | Collection or alias names that must exist (optional). The Scout prefix (`scout.prefix`) is added, as it is for `searchableAs()`, so use the same names as your models |
| `timeout` | Seconds for each request (default 5) |
| `connect_timeout` | Seconds to connect to each node (default 3) |
| `options` | Guzzle client options such as `proxy` or `verify` |

Every distinct node in `nodes` and `nearest_node` is checked separately and without retries, so a down node is reported even while the client would fail over to another. A down node degrades the check while another node is healthy, and the check fails when none are. Each collection is then retrieved from a healthy node: a missing collection fails the check, and an empty one degrades it. The `healthy_nodes` and `documents` metadata show the node count and each collection's document count.

The monitor uses its own timeouts, not `connection_timeout_seconds` or `request_timeout_seconds`. It is rated optimal under 500ms and acceptable under 2 seconds; override that with the `thresholds` option.

Add it to `system-status.monitors`:

```php
'monitors' => [
    'connections' => [
        ['type' => 'typesense', 'collections' => ['products']],
    ],
],
```

Or use it as a watch's `monitor`:

```php
'watches' => [
    'definitions' => [
        'search' => [
            'monitor' => ['type' => 'typesense', 'collections' => ['products']],
            'failures' => 3,
            'handler' => App\Watches\DisableSearch::class,
        ],
    ],
],
```
