<?php

declare(strict_types=1);

namespace MiGears\Rpc\Tests;

use PHPUnit\Framework\TestCase;
use MiGears\Rpc\JsonRpcServer;
use MiGears\Rpc\JsonRpcException;

class JsonRpcServerTest extends TestCase
{
    private JsonRpcServer $server;

    protected function setUp(): void
    {
        $this->server = new JsonRpcServer();

        $this->server->register('add', function (int $a, int $b): int {
            return $a + $b;
        });

        $this->server->register('subtract', function (array $params): int {
            return $params['minuend'] - $params['subtrahend'];
        });

        $this->server->register('ping', function (): string {
            return 'pong';
        });

        $this->server->register('divide', function (int $a, int $b): float {
            if ($b === 0) {
                throw JsonRpcException::invalidParams('Division by zero');
            }
            return $a / $b;
        });

        $this->server->register('notify_update', function (string $msg): void {
            // Notification handler, return value ignored
        });
    }

    // --- Basic call ---

    public function testCallWithPositionalParams(): void
    {
        $request = json_encode([
            'jsonrpc' => '2.0',
            'method' => 'add',
            'params' => [2, 3],
            'id' => 1,
        ]);

        $response = $this->server->handle($request);
        $data = json_decode($response, true);

        $this->assertSame('2.0', $data['jsonrpc']);
        $this->assertSame(5, $data['result']);
        $this->assertSame(1, $data['id']);
        $this->assertArrayNotHasKey('error', $data);
    }

    public function testCallWithNamedParams(): void
    {
        $request = json_encode([
            'jsonrpc' => '2.0',
            'method' => 'subtract',
            'params' => ['minuend' => 10, 'subtrahend' => 3],
            'id' => 2,
        ]);

        $response = $this->server->handle($request);
        $data = json_decode($response, true);

        $this->assertSame(7, $data['result']);
        $this->assertSame(2, $data['id']);
    }

    public function testCallNoParams(): void
    {
        $request = json_encode([
            'jsonrpc' => '2.0',
            'method' => 'ping',
            'id' => 'abc',
        ]);

        $response = $this->server->handle($request);
        $data = json_decode($response, true);

        $this->assertSame('pong', $data['result']);
        $this->assertSame('abc', $data['id']);
    }

    // --- Notifications ---

    public function testNotificationReturnsEmptyString(): void
    {
        $request = json_encode([
            'jsonrpc' => '2.0',
            'method' => 'notify_update',
            'params' => ['hello'],
        ]);

        $response = $this->server->handle($request);
        $this->assertSame('', $response);
    }

    public function testNotificationOfNonExistentMethodReturnsEmpty(): void
    {
        $request = json_encode([
            'jsonrpc' => '2.0',
            'method' => 'nonexistent',
        ]);

        $response = $this->server->handle($request);
        // Per JSON-RPC 2.0 spec: the server MUST NOT reply to a Notification,
        // even when the method is not found.
        $this->assertSame('', $response);
    }

    // --- Error cases ---

    public function testMethodNotFound(): void
    {
        $request = json_encode([
            'jsonrpc' => '2.0',
            'method' => 'nonexistent',
            'id' => 1,
        ]);

        $response = $this->server->handle($request);
        $data = json_decode($response, true);

        $this->assertArrayHasKey('error', $data);
        $this->assertSame(-32601, $data['error']['code']);
        $this->assertSame(1, $data['id']);
    }

    public function testParseError(): void
    {
        $response = $this->server->handle('{invalid json');
        $data = json_decode($response, true);

        $this->assertArrayHasKey('error', $data);
        $this->assertSame(-32700, $data['error']['code']);
        $this->assertNull($data['id']);
    }

    public function testInvalidRequestMissingJsonrpc(): void
    {
        $request = json_encode([
            'method' => 'add',
            'id' => 1,
        ]);

        $response = $this->server->handle($request);
        $data = json_decode($response, true);

        $this->assertSame(-32600, $data['error']['code']);
    }

    public function testInvalidRequestMissingMethod(): void
    {
        $request = json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
        ]);

        $response = $this->server->handle($request);
        $data = json_decode($response, true);

        $this->assertSame(-32600, $data['error']['code']);
    }

    public function testHandlerThrowsException(): void
    {
        $request = json_encode([
            'jsonrpc' => '2.0',
            'method' => 'divide',
            'params' => [10, 0],
            'id' => 1,
        ]);

        $response = $this->server->handle($request);
        $data = json_decode($response, true);

        $this->assertSame(-32602, $data['error']['code']);
        $this->assertStringContainsString('Division by zero', $data['error']['message']);
    }

    public function testGenericExceptionReturnsInternalError(): void
    {
        $server = new JsonRpcServer();
        $server->register('boom', function () {
            throw new \RuntimeException('something broke');
        });

        $request = json_encode([
            'jsonrpc' => '2.0',
            'method' => 'boom',
            'id' => 1,
        ]);

        $response = $server->handle($request);
        $data = json_decode($response, true);

        $this->assertSame(-32603, $data['error']['code']);
        $this->assertSame('something broke', $data['error']['data']);
    }

    // --- Batch ---

    public function testBatchRequest(): void
    {
        $requests = [
            ['jsonrpc' => '2.0', 'method' => 'add', 'params' => [1, 2], 'id' => 1],
            ['jsonrpc' => '2.0', 'method' => 'ping', 'id' => 2],
            ['jsonrpc' => '2.0', 'method' => 'add', 'params' => [10, 5], 'id' => 3],
        ];

        $response = $this->server->handle(json_encode($requests));
        $results = json_decode($response, true);

        $this->assertCount(3, $results);
        $this->assertSame(3, $results[0]['result']);
        $this->assertSame('pong', $results[1]['result']);
        $this->assertSame(15, $results[2]['result']);
    }

    public function testBatchWithNotificationsReturnsOnlyCallResponses(): void
    {
        $requests = [
            ['jsonrpc' => '2.0', 'method' => 'notify_update', 'params' => ['hi']],
            ['jsonrpc' => '2.0', 'method' => 'add', 'params' => [1, 2], 'id' => 1],
            ['jsonrpc' => '2.0', 'method' => 'notify_update', 'params' => ['bye']],
        ];

        $response = $this->server->handle(json_encode($requests));
        $results = json_decode($response, true);

        $this->assertCount(1, $results);
        $this->assertSame(3, $results[0]['result']);
    }

    public function testBatchAllNotificationsReturnsEmpty(): void
    {
        $requests = [
            ['jsonrpc' => '2.0', 'method' => 'notify_update', 'params' => ['a']],
            ['jsonrpc' => '2.0', 'method' => 'notify_update', 'params' => ['b']],
        ];

        $response = $this->server->handle(json_encode($requests));
        $this->assertSame('', $response);
    }

    // --- Non-object / empty requests (spec edge cases) ---

    public function testNullRequestIsInvalidRequest(): void
    {
        $response = $this->server->handle('null');
        $data = json_decode($response, true);

        $this->assertSame(-32600, $data['error']['code']);
        $this->assertNull($data['id']);
    }

    public function testScalarRequestsAreInvalidRequest(): void
    {
        foreach (['123', '"hello"', 'true'] as $input) {
            $data = json_decode($this->server->handle($input), true);
            $this->assertSame(-32600, $data['error']['code'], "input: $input");
        }
    }

    public function testEmptyBatchIsInvalidRequest(): void
    {
        $response = $this->server->handle('[]');

        // An empty batch yields a single error response (not an array).
        $data = json_decode($response, true);
        $this->assertArrayNotHasKey(0, $data);
        $this->assertSame(-32600, $data['error']['code']);
        $this->assertNull($data['id']);
    }

    public function testBatchWithInvalidRequest(): void
    {
        $requests = [
            'not an array',
            ['jsonrpc' => '2.0', 'method' => 'add', 'params' => [1, 2], 'id' => 1],
        ];

        $response = $this->server->handle(json_encode($requests));
        $results = json_decode($response, true);

        $this->assertCount(2, $results);
        $this->assertSame(-32600, $results[0]['error']['code']);
        $this->assertSame(3, $results[1]['result']);
    }

    // --- has / register ---

    public function testHasMethod(): void
    {
        $this->assertTrue($this->server->has('add'));
        $this->assertFalse($this->server->has('nonexistent'));
    }

    public function testRegisterReturnsSelf(): void
    {
        $result = $this->server->register('foo', fn() => 'bar');
        $this->assertSame($this->server, $result);
    }
}
