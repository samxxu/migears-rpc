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

    public function testNotificationWithInvalidParamsIsNotAnswered(): void
    {
        // A well-formed Request object without an id is a notification, so its
        // content errors are never replied to — unlike structural failures.
        $response = $this->server->handle(
            '{"jsonrpc":"2.0","method":"add","params":"not-an-array"}'
        );

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
        $this->assertSame('RuntimeException', $data['error']['data']);
    }

    public function testTypeErrorRaisedByTheHandlerItselfIsInternalError(): void
    {
        // A TypeError the handler raises used to be caught together with the ones PHP raises while binding the
        // arguments, so the server's own defect travelled back to the caller as `-32602 Invalid params`.
        // 处理器自己抛出的 TypeError，此前会与 PHP 绑定参数时抛的那些一起被捕获，于是服务端自身的缺陷以
        // `-32602 Invalid params` 回到调用方。
        $server = new JsonRpcServer();
        $server->register('broken', function (): string {
            throw new \TypeError('the handler is broken');
        });

        $data = json_decode($server->handle('{"jsonrpc":"2.0","method":"broken","id":1}'), true);

        $this->assertSame(-32603, $data['error']['code']);
        $this->assertSame('TypeError', $data['error']['data']);
    }

    public function testTypeErrorFromInsideTheHandlerBodyIsInternalError(): void
    {
        // The same defect one level down: the argument fits the signature — `[[]]` is one empty array for
        // `array $a` — and the TypeError comes from a call the handler itself makes. Nothing here is the
        // caller's fault, so nothing here may be answered as though it were.
        // 同一个缺陷低一层：参数合签名——对 `array $a` 来说 `[[]]` 就是一个空数组——TypeError 来自处理器自己
        // 发出的一次调用。这里没有一处是调用方的错，因此没有一处可以答成是。
        $server = new JsonRpcServer();
        $server->register('miscount', function (array $a): int {
            /** @var mixed $first */
            $first = $a[0] ?? null;

            return strlen($first);
        });

        $data = json_decode(
            $server->handle('{"jsonrpc":"2.0","method":"miscount","params":[[]],"id":1}'),
            true
        );

        $this->assertSame(-32603, $data['error']['code']);
        $this->assertSame('TypeError', $data['error']['data']);
    }

    public function testAnInternalHandlerWithTooManyArgumentsIsInvalidParams(): void
    {
        // PHP refuses extra arguments to an internal function, so that one stays the caller's fault and is
        // judged before the call, like any other argument list that does not fit.
        // PHP 拒绝传给内部函数的多余参数，因此这一种仍然是调用方的错，和任何不合适的参数列一样在调用前判定。
        $server = new JsonRpcServer();
        $server->register('length', 'strlen');

        $data = json_decode(
            $server->handle('{"jsonrpc":"2.0","method":"length","params":["a","b"],"id":1}'),
            true
        );

        $this->assertSame(-32602, $data['error']['code']);
        $this->assertArrayNotHasKey('data', $data['error']);
    }

    public function testAUserHandlerWithAnExtraArgumentStillRuns(): void
    {
        // The other half of that asymmetry: PHP tolerates extra arguments to a user-defined function, and the
        // check mirrors PHP rather than tightening it — refusing here would break a call that works.
        // 那处不对称的另一半：PHP 容忍传给用户函数的多余参数，而这道检查照搬 PHP、不额外收紧——在这里拒绝，
        // 会弄坏一个本来能工作的调用。
        $server = new JsonRpcServer();
        $server->register('first', fn ($a) => $a);

        $data = json_decode(
            $server->handle('{"jsonrpc":"2.0","method":"first","params":["a","b"],"id":1}'),
            true
        );

        $this->assertSame('a', $data['result']);
    }

    public function testInternalErrorDoesNotLeakMessage(): void
    {
        $server = new JsonRpcServer();
        $server->register('connect', function () {
            throw new \PDOException('SQLSTATE[HY000] Access denied for user root@localhost (using password: YES)');
        });

        $request = json_encode([
            'jsonrpc' => '2.0',
            'method' => 'connect',
            'id' => 1,
        ]);

        $response = $server->handle($request);
        $data = json_decode($response, true);

        $this->assertSame(-32603, $data['error']['code']);
        $this->assertSame('PDOException', $data['error']['data']);
        // Raw message must not travel back to the remote caller.
        $this->assertStringNotContainsString('root@localhost', $response);
        $this->assertStringNotContainsString('using password', $response);
    }

    // --- id: null is a valid request, NOT a notification ---

    public function testIdNullIsNotANotification(): void
    {
        // Per JSON-RPC 2.0 spec, only the absence of "id" makes a notification.
        // id: null is a valid (though discouraged) request id and MUST get a response.
        $request = json_encode([
            'jsonrpc' => '2.0',
            'method' => 'ping',
            'id' => null,
        ]);

        $response = $this->server->handle($request);
        $this->assertNotSame('', $response);

        $data = json_decode($response, true);
        $this->assertArrayHasKey('id', $data);
        $this->assertNull($data['id']);
        $this->assertSame('pong', $data['result']);
    }

    public function testIdZeroIsValidRequest(): void
    {
        $request = json_encode([
            'jsonrpc' => '2.0',
            'method' => 'ping',
            'id' => 0,
        ]);

        $response = $this->server->handle($request);
        $data = json_decode($response, true);

        $this->assertSame(0, $data['id']);
        $this->assertSame('pong', $data['result']);
    }

    // --- params edge cases ---

    public function testParamsNullReturnsInvalidParams(): void
    {
        // params: null is not a valid structured value per spec.
        $request = json_encode([
            'jsonrpc' => '2.0',
            'method' => 'add',
            'params' => null,
            'id' => 1,
        ]);

        $response = $this->server->handle($request);
        $data = json_decode($response, true);

        $this->assertSame(-32602, $data['error']['code']);
    }

    public function testParamsScalarReturnsInvalidParams(): void
    {
        $request = json_encode([
            'jsonrpc' => '2.0',
            'method' => 'add',
            'params' => 'hello',
            'id' => 1,
        ]);

        $response = $this->server->handle($request);
        $data = json_decode($response, true);

        $this->assertSame(-32602, $data['error']['code']);
    }

    // --- json_encode failure must not throw TypeError ---

    public function testHandleDoesNotThrowOnEncodingFailure(): void
    {
        $server = new JsonRpcServer();
        $server->register('bad', function () {
            // Return a string with invalid UTF-8 byte sequence.
            return "\xB1\x31";
        });

        $request = json_encode([
            'jsonrpc' => '2.0',
            'method' => 'bad',
            'id' => 42,
        ]);

        $response = $server->handle($request);

        $data = json_decode($response, true);
        $this->assertSame(-32603, $data['error']['code']);
        // The original id must survive so the client can correlate the failure.
        $this->assertSame(42, $data['id']);
    }

    public function testBatchWithUnencodableResultStaysAnArray(): void
    {
        $server = new JsonRpcServer();
        $server->register('ok', fn() => 'fine');
        $server->register('bad', fn() => "\xB1\x31");

        $request = json_encode([
            ['jsonrpc' => '2.0', 'method' => 'ok', 'id' => 1],
            ['jsonrpc' => '2.0', 'method' => 'bad', 'id' => 2],
        ]);

        $response = $server->handle($request);
        $decoded = json_decode($response, true);

        // A batch response must remain an array — one bad result must not
        // collapse the whole batch into a single error object.
        $this->assertIsArray($decoded);
        $this->assertTrue(array_is_list($decoded));
        $this->assertCount(2, $decoded);

        // Valid result survives.
        $this->assertSame('fine', $decoded[0]['result']);
        $this->assertSame(1, $decoded[0]['id']);

        // Only the offending element degrades, keeping its own id.
        $this->assertSame(-32603, $decoded[1]['error']['code']);
        $this->assertSame(2, $decoded[1]['id']);
    }

    public function testParamsEmptyObjectReturnsInvalidParams(): void
    {
        $server = new JsonRpcServer();
        $server->register('need2', fn($a, $b) => $a + $b);

        // params:{} decodes to [] — an empty param set cannot satisfy a
        // handler that requires arguments, and must not surface as -32603.
        $response = $server->handle('{"jsonrpc":"2.0","method":"need2","params":{},"id":1}');
        $data = json_decode($response, true);

        $this->assertSame(-32602, $data['error']['code']);
        $this->assertSame(1, $data['id']);
        // No PHP exception class name may travel back to the caller.
        $this->assertArrayNotHasKey('data', $data['error']);
    }

    public function testParamTypeMismatchReturnsInvalidParams(): void
    {
        $server = new JsonRpcServer();
        $server->register('typed', fn(int $a) => $a);

        // The argument count is right but the type is not — a TypeError, which
        // must be reported as invalid params rather than an internal error.
        $response = $server->handle(json_encode([
            'jsonrpc' => '2.0',
            'method' => 'typed',
            'params' => ['abc'],
            'id' => 1,
        ]));

        $data = json_decode($response, true);
        $this->assertSame(-32602, $data['error']['code']);
        $this->assertArrayNotHasKey('data', $data['error']);
    }

    // --- Decoded-array input (e.g. miGears Web Request::$body) ---

    public function testHandleAcceptsDecodedArray(): void
    {
        $response = $this->server->handle([
            'jsonrpc' => '2.0',
            'method' => 'add',
            'params' => [2, 3],
            'id' => 7,
        ]);

        $data = json_decode($response, true);
        $this->assertSame(5, $data['result']);
        $this->assertSame(7, $data['id']);
    }

    public function testHandleAcceptsDecodedBatchArray(): void
    {
        $response = $this->server->handle([
            ['jsonrpc' => '2.0', 'method' => 'add', 'params' => [1, 1], 'id' => 1],
            ['jsonrpc' => '2.0', 'method' => 'ping', 'id' => 2],
        ]);

        $results = json_decode($response, true);
        $this->assertCount(2, $results);
        $this->assertSame(2, $results[0]['result']);
        $this->assertSame('pong', $results[1]['result']);
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

    public function testStructurallyInvalidRequestWithoutIdIsStillAnswered(): void
    {
        // Neither input is a valid Request object, so neither is a notification:
        // the server must answer with -32600 even though no id was sent.
        $inputs = [
            '{"jsonrpc":"2.0"}',
            '{"jsonrpc":"1.0","method":"ping"}',
        ];

        foreach ($inputs as $input) {
            $data = json_decode($this->server->handle($input), true);

            $this->assertSame(-32600, $data['error']['code'], "input: $input");
            $this->assertNull($data['id']);
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

    public function testBatchWithNestedArrayElementGetsItsOwnError(): void
    {
        // An array that is not a Request object is not a notification either, so
        // it must be answered per element instead of being silently dropped.
        $response = $this->server->handle(
            '[[1,2],{"jsonrpc":"2.0","method":"add","params":[1,2],"id":1}]'
        );
        $results = json_decode($response, true);

        $this->assertCount(2, $results);
        $this->assertSame(-32600, $results[0]['error']['code']);
        $this->assertNull($results[0]['id']);
        $this->assertSame(3, $results[1]['result']);
    }

    public function testBatchWithEmptyObjectElementGetsItsOwnError(): void
    {
        $response = $this->server->handle(
            '[{},{"jsonrpc":"2.0","method":"add","params":[1,2],"id":1}]'
        );
        $results = json_decode($response, true);

        $this->assertCount(2, $results);
        $this->assertSame(-32600, $results[0]['error']['code']);
        $this->assertNull($results[0]['id']);
        $this->assertSame(3, $results[1]['result']);
    }

    public function testBatchOfOnlyInvalidElementsAnswersEach(): void
    {
        // P2-1: an element that is an array but not a Request object used to be
        // dropped as a notification. When every element was invalid the whole
        // batch then produced no response at all, even though each element owed
        // its own -32600.
        $results = json_decode($this->server->handle('[{"jsonrpc":"2.0"},[1,2]]'), true);

        $this->assertCount(2, $results);
        $this->assertSame(-32600, $results[0]['error']['code']);
        $this->assertSame(-32600, $results[1]['error']['code']);
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
