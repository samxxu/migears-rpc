<?php

declare(strict_types=1);

namespace MiGears\Rpc\Tests;

use PHPUnit\Framework\TestCase;
use MiGears\Rpc\JsonRpcClient;
use MiGears\Rpc\JsonRpcException;

/**
 * Tests JsonRpcClient against a built-in PHP HTTP server.
 *
 * The test server echoes back the JSON-RPC request body so we can
 * verify the client sends correctly formatted requests.
 */
class JsonRpcClientTest extends TestCase
{
    private static string $serverScript;
    private static int $serverPort;
    private static mixed $serverProcess;
    /** @var array<int, resource> */
    private static array $pipes = [];

    public static function setUpBeforeClass(): void
    {
        self::$serverPort = 18300 + random_int(0, 1000);
        self::$serverScript = tempnam(sys_get_temp_dir(), 'rpc_test_server_') . '.php';

        // Simple test server that processes JSON-RPC requests
        file_put_contents(self::$serverScript, <<<'PHP'
<?php
$body = file_get_contents('php://input');
$request = json_decode($body, true);

if (!$request) {
    header('Content-Type: application/json');
    echo json_encode(['jsonrpc' => '2.0', 'error' => ['code' => -32700, 'message' => 'Parse error'], 'id' => null]);
    exit;
}

// Handle single or batch
if (isset($request[0])) {
    $responses = [];
    foreach ($request as $req) {
        if (!isset($req['id'])) continue; // skip notifications
        $responses[] = handleRequest($req);
    }
    header('Content-Type: application/json');
    echo json_encode($responses);
} else {
    // Notification (no id)
    if (!isset($request['id'])) {
        // Write notification to a file so test can verify it
        file_put_contents(sys_get_temp_dir() . '/rpc_notify.log', json_encode($request) . "\n", FILE_APPEND);
        header('HTTP/1.1 204 No Content');
        exit;
    }
    header('Content-Type: application/json');
    echo json_encode(handleRequest($request));
}

function handleRequest($req) {
    $id = $req['id'] ?? null;
    $method = $req['method'] ?? '';
    $params = $req['params'] ?? [];

    switch ($method) {
        case 'add':
            return ['jsonrpc' => '2.0', 'result' => $params[0] + $params[1], 'id' => $id];
        case 'echo':
            return ['jsonrpc' => '2.0', 'result' => $params, 'id' => $id];
        case 'error':
            return ['jsonrpc' => '2.0', 'error' => ['code' => -32001, 'message' => 'Server error', 'data' => 'details'], 'id' => $id];
        default:
            return ['jsonrpc' => '2.0', 'error' => ['code' => -32601, 'message' => 'Method not found'], 'id' => $id];
    }
}
PHP
);

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        self::$serverProcess = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . self::$serverPort, '-t', sys_get_temp_dir(), self::$serverScript],
            $descriptors,
            self::$pipes
        );

        // Wait for server to start
        usleep(500000);
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$serverProcess)) {
            proc_terminate(self::$serverProcess);
            proc_close(self::$serverProcess);
        }
        @unlink(self::$serverScript);
        @unlink(sys_get_temp_dir() . '/rpc_notify.log');
    }

    private function getClient(): JsonRpcClient
    {
        return new JsonRpcClient('http://127.0.0.1:' . self::$serverPort);
    }

    // --- call ---

    public function testCallReturnsResult(): void
    {
        $client = $this->getClient();
        $result = $client->call('add', [3, 4]);
        $this->assertSame(7, $result);
    }

    public function testCallIncrementsId(): void
    {
        $client = $this->getClient();
        $client->call('add', [1, 2]);
        $this->assertSame(1, $client->getLastRequestId());
        $client->call('add', [1, 2]);
        $this->assertSame(2, $client->getLastRequestId());
    }

    public function testCallWithNamedParams(): void
    {
        $client = $this->getClient();
        $result = $client->call('echo', ['foo' => 'bar', 'num' => 42]);
        $this->assertSame(['foo' => 'bar', 'num' => 42], $result);
    }

    // --- Error handling ---

    public function testCallThrowsOnMethodNotFound(): void
    {
        $client = $this->getClient();
        $this->expectException(JsonRpcException::class);
        $this->expectExceptionCode(-32601);
        $client->call('nonexistent_method');
    }

    public function testCallThrowsOnServerError(): void
    {
        $client = $this->getClient();
        try {
            $client->call('error');
            $this->fail('Expected JsonRpcException');
        } catch (JsonRpcException $e) {
            $this->assertSame(-32001, $e->getCode());
            $this->assertSame('Server error', $e->getMessage());
            $this->assertSame('details', $e->getData());
        }
    }

    // --- notify ---

    public function testNotifyDoesNotThrow(): void
    {
        $client = $this->getClient();
        $client->notify('notify_update', ['test' => 'hello']);
        $this->assertTrue(true); // No exception = pass
    }

    // --- setHeader ---

    public function testSetHeaderReturnsSelf(): void
    {
        $client = $this->getClient();
        $result = $client->setHeader('X-Custom', 'value');
        $this->assertSame($client, $result);
    }

    // --- batch ---

    public function testBatchRequest(): void
    {
        $client = $this->getClient();
        $results = $client->batch([
            ['method' => 'add', 'params' => [1, 2], 'id' => 1],
            ['method' => 'add', 'params' => [10, 20], 'id' => 2],
        ]);

        $this->assertCount(2, $results);
        $this->assertSame(3, $results[0]['result']);
        $this->assertSame(30, $results[1]['result']);
    }

    // --- Version constant ---

    public function testVersionConstant(): void
    {
        $this->assertSame('2.0.0', JsonRpcClient::VERSION);
    }
}
