# ConnectionPool
Connection Pool based on Swoole with balancer feature implementation

## Parameters
* int `maxActive` (default: 2) - The maximum number of active connections that can be allocated from this pool at the same time
* int `minActive` (default: 0) - The minimum number of established connections that should be kept in the pool at all times
* float `maxWaitTime` (default: 5.0) - The maximum number of seconds that the pool will wait (when there are no available connections)
for a connection to be returned before throwing an exception.
Zero value (0.0) will disable wait timeout.
* float `validationInterval` (default: 5.0) - The number of seconds to sleep between runs of the idle connection validation/cleaner timer.
This value should not be set under 1 second.
Zero value will disable validate connections timer.
* int `maxIdleTime` (default: 60) - The minimum amount of time (seconds) a connection may sit idle in the pool before it is eligible for closing.
Zero value will disable idle connections freeing.
* int `maxLifeTime` (default: 0) - The maximum amount of time (seconds) a connection may exist in the pool before it is eligible for closing.
Zero value will disable expired connections freeing.
* bool `testOnBorrow` (default: true) - The indication of whether objects will be validated before being borrowed from the pool.
If the object fails to validate, it will be dropped from the pool, and we will attempt to borrow another.
* bool `testOnReturn` (default: true) - The indication of whether objects will be validated before being returned to the pool
* bool `resetConnections` (default: false) -  Reset the connection to its initial state when it is borrowed from the pool

All of these parameters can be changed at runtime.

## API
* `init` - Initialize (start) connection pool
* `close` - Close (stop) connection pool
* `getStats` - Get connection pool statistics
* `pop` (default visibility: `protected`) - Borrow connection from pool. 
May throw `BorrowTimeoutException` when waiting of free connection is timed out (read `maxWaitTime` parameter doc).
May throw `PoolIsClosedException` when connection pool is closed.
* `push` (default visibility: `protected`) - Return connection to pool

### Getters
* `getIdleCount` - Get idle connections count
* `getTotalCount` - Get count of connections created by the pool
* `getMaxActive` - read `maxActive` parameter doc
* `getMinActive` - read `minActive` parameter doc
* `getMaxWaitTime` - read `maxWaitTime` parameter doc
* `getMaxIdleTime` - read `maxIdleTime` parameter doc
* `getMaxLifeTime` - read `maxLifeTime` parameter doc
* `getValidationInterval` - read `validationInterval` parameter doc
* `getTestOnBorrow` - read `testOnBorrow` parameter doc
* `getTestOnReturn` - read `testOnReturn` parameter doc
* `getResetConnections` - read `resetConnections` parameter doc

### Setters
* `setMaxActive` - read `maxActive` parameter doc
* `setMinActive` - read `minActive` parameter doc
* `setMaxWaitTime` - read `maxWaitTime` parameter doc
* `setMaxIdleTime` - read `maxIdleTime` parameter doc
* `setMaxLifeTime` - read `maxLifeTime` parameter doc
* `setValidationInterval` - read `validationInterval` parameter doc
* `setTestOnBorrow` - read `testOnBorrow` parameter doc
* `setTestOnReturn` - read `testOnReturn` parameter doc
* `setResetConnections` - read `resetConnections` parameter doc

## Complete example (HTTP Connection Pool)
```php
<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use MakiseCo\Connection\ConnectionConfig;
use MakiseCo\Connection\ConnectionConfigInterface;
use MakiseCo\Connection\ConnectionInterface;
use MakiseCo\Connection\ConnectorInterface;
use MakiseCo\Pool\Exception\BorrowTimeoutException;
use MakiseCo\Pool\Pool;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoole\Coroutine\Http\Client;

use function Swoole\Coroutine\run;

class HttpResponse
{
    public string $content;
    public int $statusCode;

    public function __construct(string $content, int $statusCode)
    {
        $this->content = $content;
        $this->statusCode = $statusCode;
    }
}

interface HttpConnectionInterface extends ConnectionInterface
{
    /**
     * Perform GET request
     *
     * @param string $path
     *
     * @return HttpResponse
     *
     * @throws RuntimeException on connection problems
     */
    public function get(string $path): HttpResponse;
}

class HttpConnectionConfig extends ConnectionConfig
{
    private float $timeout;
    private bool $ssl;

    public function __construct(string $host, int $port, ?bool $ssl = null, float $timeout = 30)
    {
        $this->timeout = $timeout;

        if ($ssl === null) {
            $ssl = $port === 443;
        }

        $this->ssl = $ssl;

        parent::__construct($host, $port, null, null, null);
    }

    public function getTimeout(): float
    {
        return $this->timeout;
    }

    public function getSsl(): bool
    {
        return $this->ssl;
    }

    public function getConnectionString(): string
    {
        return '';
    }
}

class HttpConnection implements HttpConnectionInterface
{
    private Client $client;
    private int $lastUsedAt;
    private bool $isClosed = false;

    public function __construct(Client $client)
    {
        $this->client = $client;
        $this->lastUsedAt = time();
    }

    public function __destruct()
    {
        $this->close();
    }

    public function isAlive(): bool
    {
        return !$this->isClosed;
    }

    public function close(): void
    {
        if ($this->isClosed) {
            return;
        }

        $this->isClosed = true;
        $this->client->close();
    }

    public function getLastUsedAt(): int
    {
        return $this->lastUsedAt;
    }

    public function resetSession(): void
    {
    }

    public static function connect(HttpConnectionConfig $config): self
    {
        $client = new Client($config->getHost(), $config->getPort(), $config->getSsl());
        $client->set(['timeout' => $config->getTimeout()]);

        return new self($client);
    }

    /**
     * @param string $path
     * @return HttpResponse
     *
     * @throws RuntimeException on connection errors
     */
    public function get(string $path): HttpResponse
    {
        $this->lastUsedAt = time();

        $this->client->get($path);

        $code = $this->client->getStatusCode();
        $this->checkStatusCode($code);

        return new HttpResponse(
            $this->client->getBody(),
            $this->client->getStatusCode()
        );
    }

    private function checkStatusCode(int $code): void
    {
        if ($code === SWOOLE_HTTP_CLIENT_ESTATUS_CONNECT_FAILED) {
            throw new RuntimeException('Connection failed');
        }

        if ($code === SWOOLE_HTTP_CLIENT_ESTATUS_REQUEST_TIMEOUT) {
            throw new RuntimeException('Request timeout');
        }

        if ($code === SWOOLE_HTTP_CLIENT_ESTATUS_SERVER_RESET) {
            throw new RuntimeException('Server has closed connection unexpectedly');
        }
    }
}

class HttpConnector implements ConnectorInterface
{
    /**
     * @param HttpConnectionConfig|ConnectionConfigInterface $config
     * @return HttpConnection
     */
    public function connect(ConnectionConfigInterface $config): HttpConnection
    {
        return HttpConnection::connect($config);
    }
}

class HttpPool extends Pool implements HttpConnectionInterface
{
    protected function createDefaultConnector(): HttpConnector
    {
        return new HttpConnector();
    }

    /**
     * {@inheritDoc}
     *
     * @throws BorrowTimeoutException
     */
    public function get(string $path): HttpResponse
    {
        $connection = $this->pop();

        try {
            return $connection->get($path);
        } finally {
            $this->push($connection);
        }
    }
}

run(static function () {
    $httpPool = new HttpPool(new HttpConnectionConfig('google.com', 80));
    $httpPool->setMaxActive(4);
    $httpPool->init();

    $tasks = [
        '/',
        '/help',
        '/search',
        '/test',
        '/query',
        '/images',
        '/videos',
        '/mail',
    ];

    $ch = new Channel();

    $start = microtime(true);

    foreach ($tasks as $task) {
        Coroutine::create(static function (Channel $ch, string $path) use ($httpPool) {
            $result = new class {
                public string $task;
                public ?HttpResponse $success = null;
                public ?Throwable $fail = null;
            };

            $result->task = $path;

            try {
                $result->success = $httpPool->get($path);
                $ch->push($result);
            } catch (Throwable $e) {
                $result->fail = $e;
                $ch->push($result);
            }
        }, $ch, $task);
    }

    $results = [];
    for ($i = 0, $iMax = \count($tasks); $i < $iMax; $i++) {
        $results[] = $ch->pop();
    }

    $end = microtime(true);

    foreach ($results as $result) {
        if ($result->fail !== null) {
            printf("Task: %s failed with: %s\n", $result->task, $result->fail->getMessage());

            continue;
        }

        printf("Task: %s returned %d status code\n", $result->task, $result->success->statusCode);
    }

    printf("\nResults fetched in %.4f secs\n\n", round($end - $start, 4));

    $stats = $httpPool->getStats();

    printf("Connections limit = %d\n", $stats->maxActive);
    printf("Connections count = %d\n", $stats->totalCount);
    printf("Idle connections = %d\n", $stats->idle);
    printf("Busy connections = %d\n", $stats->inUse);

    printf("Total wait time for an available connections = %f secs\n", $stats->waitDuration);
    printf("Total wait count = %d\n", $stats->waitCount);
    printf("Average wait time per one connection = %f secs\n", $stats->waitDuration / $stats->waitCount);

    $httpPool->close();
});

```

Output is:
```
Task: /test returned 404 status code
Task: / returned 301 status code
Task: /search returned 301 status code
Task: /help returned 404 status code
Task: /query returned 404 status code
Task: /images returned 301 status code
Task: /mail returned 301 status code
Task: /videos returned 404 status code

Results fetched in 0.1483 secs

Connections limit = 4
Connections count = 4
Idle connections = 4
Busy connections = 0
Total wait time for an available connections = 0.380008 secs
Total wait count = 4
Average wait time per one connection = 0.095002 secs
```
