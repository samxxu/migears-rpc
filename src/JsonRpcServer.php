<?php

declare(strict_types=1);

namespace MiGears\Rpc;

/**
 * JSON-RPC 2.0 server.
 *
 * Registers method handlers and processes JSON-RPC requests.
 * Can be used with any input source (HTTP, CLI, etc.).
 *
 * Usage:
 *   $server = new JsonRpcServer();
 *   $server->register('add', fn(int $a, int $b) => $a + $b);
 *   $response = $server->handle(file_get_contents('php://input'));
 *   echo $response;
 */
class JsonRpcServer
{
    public const VERSION = '2.0.0';

    /** @var string JSON-RPC protocol version */
    private const JSONRPC_VERSION = '2.0';

    /** @var array<string, callable> Registered method handlers */
    private array $methods = [];

    /**
     * Register a method handler.
     *
     * The callable receives params as arguments (for positional params)
     * or a single associative array (for named params).
     */
    public function register(string $method, callable $handler): self
    {
        $this->methods[$method] = $handler;
        return $this;
    }

    /**
     * Check if a method is registered.
     */
    public function has(string $method): bool
    {
        return isset($this->methods[$method]);
    }

    /**
     * Handle a JSON-RPC request and return the response JSON string.
     *
     * Accepts a raw JSON string, or an already-decoded request array (a single
     * request object or a batch list). The array form suits HTTP layers that
     * have already parsed the body — e.g. miGears Web's `Request::$body`.
     *
     * Returns an empty string for notifications (no response needed).
     *
     * @param string|array<int|string, mixed> $request Raw JSON or decoded request
     */
    public function handle(string|array $request): string
    {
        if (is_string($request)) {
            $decoded = json_decode($request, true);

            if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
                return $this->encodeError(-32700, 'Parse error');
            }

            $request = $decoded;
        }

        // A well-formed JSON-RPC request must be an object or a batch array.
        if (!is_array($request)) {
            return $this->encodeError(-32600, 'Invalid Request');
        }

        // Batch request (an empty array is a list, handled per spec below)
        if (array_is_list($request)) {
            return $this->handleBatch($request);
        }

        $response = $this->handleSingle($request);

        // Notification (no id) = no response
        if ($response === null) {
            return '';
        }

        return $this->encodeResponse($response);
    }

    /**
     * Handle a single request array.
     *
     * @param array<string, mixed> $request Decoded JSON-RPC request object
     * @return array<string, mixed>|null Response array, or null for notifications
     *         (including notifications whose method or params are rejected)
     */
    private function handleSingle(array $request): ?array
    {
        $hasId = array_key_exists('id', $request);
        $id = $request['id'] ?? null;
        $isNotification = !$hasId;

        // Structural validation comes first, and is not exempt for id-less input:
        // a Notification must itself be a valid Request object (spec §4.2), so an
        // element that is not one — `{}`, `[1,2]`, a wrong version — is answered
        // with -32600 even though it carries no id.
        if (!isset($request['jsonrpc']) || $request['jsonrpc'] !== self::JSONRPC_VERSION) {
            return $this->errorResponse(-32600, 'Invalid Request', $id);
        }

        if (!isset($request['method']) || !is_string($request['method'])) {
            return $this->errorResponse(-32600, 'Invalid Request', $id);
        }

        $method = $request['method'];

        // Past this point the element is a well-formed Request object, so an absent
        // id means notification: content errors are then never replied to.
        // Params is optional; if present, it must be an array (list or object).
        if (array_key_exists('params', $request)) {
            $params = $request['params'];
            if (!is_array($params)) {
                if ($isNotification) return null;
                return $this->errorResponse(-32602, 'Invalid params', $id);
            }
        } else {
            $params = [];
        }

        // Check method exists
        if (!isset($this->methods[$method])) {
            if ($isNotification) return null;
            return $this->errorResponse(-32601, 'Method not found', $id);
        }

        try {
            $handler = $this->methods[$method];
            $result = $this->callHandler($handler, $params);

            if ($isNotification) {
                return null;
            }

            return [
                'jsonrpc' => self::JSONRPC_VERSION,
                'result' => $result,
                'id' => $id,
            ];
        } catch (JsonRpcException $e) {
            if ($isNotification) return null;
            return $this->errorResponse($e->getCode(), $e->getMessage(), $id, $e->getData());
        } catch (\Throwable $e) {
            if ($isNotification) return null;
            // Never leak the raw message (may contain DSNs, credentials, paths).
            // The exception class name gives remote callers type-level diagnostics only.
            return $this->errorResponse(-32603, 'Internal error', $id, (new \ReflectionClass($e))->getShortName());
        }
    }

    /**
     * Handle a batch of requests.
     *
     * @param list<mixed> $requests Decoded batch list (each entry handled as a request)
     */
    private function handleBatch(array $requests): string
    {
        // An empty batch is an Invalid Request — respond with a single error.
        if ($requests === []) {
            return $this->encodeError(-32600, 'Invalid Request');
        }

        $responses = [];

        foreach ($requests as $req) {
            if (!is_array($req)) {
                $responses[] = $this->errorResponse(-32600, 'Invalid Request', null);
                continue;
            }

            $response = $this->handleSingle($req);
            if ($response !== null) {
                $responses[] = $response;
            }
        }

        if ($responses === []) {
            return ''; // All notifications
        }

        // Encode element-wise: one un-encodable result must not collapse the
        // whole batch into a single object — a batch response is always an array.
        $parts = [];
        foreach ($responses as $response) {
            $parts[] = $this->encodeResponse($response);
        }

        return '[' . implode(',', $parts) . ']';
    }

    /**
     * Call a handler with the given params.
     *
     * Positional params (numeric array) are spread as individual arguments.
     * Named params (associative array) are passed as a single array argument.
     */
    private function callHandler(callable $handler, mixed $params): mixed
    {
        if (!is_array($params)) {
            throw new JsonRpcException(-32602, 'Invalid params');
        }

        // Positional params (a list) are spread as individual arguments; named params (an associative array)
        // are handed over as one array argument, which is this server's convention for a handler that
        // declares a single array parameter.
        // 位置参数（列表）展开成一个个实参；命名参数（关联数组）整体作为一个数组实参交出——这是本服务对
        // 「只声明一个数组参数」的处理器的约定。
        $args = array_is_list($params) ? $params : [$params];

        $this->assertArgumentsBind($handler, $args);

        return $handler(...$args);
    }

    /**
     * Refuse an argument list that PHP would refuse when it binds it, before the call is made.
     *
     * The arguments reach the handler through the call above, and a binding failure there raises a
     * `TypeError` — indistinguishable, once caught, from a `TypeError` the handler's own body threw. Catching
     * that type and answering `-32602` therefore blamed the remote caller for the server's own defect, which
     * is the item this method settles. Answering here, before the call, puts the verdict on the side that is
     * actually wrong: these arguments are the caller's, everything after the call is the handler's.
     *
     * What it mirrors is PHP's own rule set, not a simpler one of its own, so it never refuses an argument
     * list PHP would accept. Two properties of this call site are what make that possible:
     * `JsonRpcServer.php` declares `strict_types`, so nothing is coerced on the way in; and the values can
     * only be the kinds `json_decode` produces — null, bool, int, float, string, array — so a type is either
     * decided by the rules below or, when it is a class or an interface, unsatisfiable by any decoded value.
     *
     * 在调用之前，拒绝一列 PHP 绑定时会拒绝的参数。
     *
     * 参数经上面的调用进入处理器，而那里绑定失败抛出的 `TypeError`，一旦被捕获，就与处理器自身函数体抛出的
     * `TypeError` 无从分辨。因此捕获该类型并答 `-32602`，等于把服务端自身的缺陷归给远端调用者——那正是本条
     * 要落定的事。在这里、在调用之前作答，就把裁决放在了真正有错的一侧：这组参数是调用方的，调用之后的一切
     * 是处理器的。
     *
     * 它照搬的是 PHP 自己的规则，而不是自立的简化版，因此不会拒绝 PHP 本会接受的参数列。之所以做得到，靠的
     * 是这个调用点的两个性质：`JsonRpcServer.php` 声明了 `strict_types`，因此不会有权被强转；而取值只可能是
     * `json_decode` 产出的那几种——null、bool、int、float、string、array——于是一个类型要么由下面的规则判定，
     * 要么是类或接口，而任何解码出来的值都满足不了它。
     *
     * @param list<mixed> $args The arguments the call below is about to pass
     */
    private function assertArgumentsBind(callable $handler, array $args): void
    {
        $signature = new \ReflectionFunction(\Closure::fromCallable($handler));
        $passed = count($args);

        // Too few arguments is the ArgumentCountError half, and it has to be judged against the required
        // count rather than the total, or a handler with defaults would be refused its own short call.
        // 参数太少属于 ArgumentCountError 那一半，要按「必需个数」而不是「总个数」来判，否则带默认值的
        // 处理器连自己那种短调用都会被拒。
        if ($passed < $signature->getNumberOfRequiredParameters()) {
            throw new JsonRpcException(-32602, 'Invalid params');
        }

        // Past the required count, PHP tolerates extra arguments to a user-defined function and refuses them
        // for an internal one, so the upper bound is an error in the second case only.
        // 超过必需个数之后，PHP 容忍传给用户函数的多余参数，而对内部函数则拒绝，因此上限只在后一种情况下算错。
        if ($signature->isInternal() && !$signature->isVariadic()
            && $passed > $signature->getNumberOfParameters()) {
            throw new JsonRpcException(-32602, 'Invalid params');
        }

        $parameters = $signature->getParameters();
        foreach ($args as $index => $value) {
            // Past the declared parameters only a variadic signature can get this far, and its last
            // parameter is the one the remainder is checked against; an untyped signature has nothing to
            // check against and is left to PHP.
            // 越过已声明的参数之后，只有变参签名能走到这里，余下的部分按它的最后一个参数校验；无类型签名没有
            // 可对照的东西，交给 PHP。
            $parameter = $parameters[$index]
                ?? ($parameters === [] ? null : $parameters[count($parameters) - 1]);

            if ($parameter !== null && !$this->argumentFits($parameter, $value)) {
                throw new JsonRpcException(-32602, 'Invalid params');
            }
        }
    }

    /**
     * Whether a decoded value satisfies a parameter's declared type under strict typing.
     *
     * 在严格类型下，一个解码出来的值是否满足参数声明的类型。
     */
    private function argumentFits(\ReflectionParameter $parameter, mixed $value): bool
    {
        $type = $parameter->getType();

        if ($type === null) {
            return true;
        }
        if ($value === null) {
            return $type->allowsNull();
        }
        if ($type instanceof \ReflectionUnionType) {
            foreach ($type->getTypes() as $member) {
                if ($member instanceof \ReflectionNamedType && $this->namedTypeFits($member, $value)) {
                    return true;
                }
            }

            return false;
        }
        if ($type instanceof \ReflectionIntersectionType) {
            // An intersection asks for an object, and no value that came out of json_decode is one.
            // 交集类型要的是一个对象，而 json_decode 产出的值没有一个是对象。
            return false;
        }

        return $type instanceof \ReflectionNamedType && $this->namedTypeFits($type, $value);
    }

    /**
     * The strict-typing rule for one named type, as it applies to the values json_decode can produce.
     *
     * 一条具名类型在严格类型下的规则，按 json_decode 可能产出的取值范围来写。
     */
    private function namedTypeFits(\ReflectionNamedType $type, mixed $value): bool
    {
        // A variable, because `instanceof` takes a class name or a variable and not a call.
        // 用一个变量，因为 `instanceof` 右侧只接受类名或变量，不接受函数调用。
        $name = $type->getName();

        return match ($name) {
            'mixed' => true,
            'null' => $value === null,
            'false' => $value === false,
            'true' => $value === true,
            'bool' => is_bool($value),
            'int' => is_int($value),
            // An int widens to float, which strict typing allows; a float does not narrow to int, and this
            // is the one asymmetry in the list.
            // int 可以放宽为 float，严格类型允许；float 不能收窄成 int——这是这张表里唯一的不对称。
            'float' => is_int($value) || is_float($value),
            'string' => is_string($value),
            'array' => is_array($value),
            'iterable' => is_iterable($value),
            'object' => is_object($value),
            'callable' => is_callable($value),
            // A class or interface name, including self / static / parent: unsatisfiable by a decoded value,
            // which is what the instanceof answers rather than a rule of its own.
            // 类名或接口名（含 self / static / parent）：解码出来的值满足不了，instanceof 只是把这个事实答出来，
            // 而不是另立规则。
            default => $value instanceof $name,
        };
    }

    /**
     * Build an error response array.
     *
     * @return array<string, mixed>
     */
    private function errorResponse(int $code, string $message, mixed $id, mixed $data = null): array
    {
        $error = [
            'code' => $code,
            'message' => $message,
        ];

        if ($data !== null) {
            $error['data'] = $data;
        }

        return [
            'jsonrpc' => self::JSONRPC_VERSION,
            'error' => $error,
            'id' => $id,
        ];
    }

    /**
     * Encode a top-level error as JSON string.
     */
    private function encodeError(int $code, string $message): string
    {
        return $this->encode([
            'jsonrpc' => self::JSONRPC_VERSION,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
            'id' => null,
        ]);
    }

    /**
     * Encode a single response, preserving the original id on failure.
     *
     * A result that cannot be encoded (e.g. invalid UTF-8) still yields a
     * correlatable response — the client sees an Internal error for its own id
     * instead of a confusing id mismatch.
     *
     * @param array<string, mixed> $response
     */
    private function encodeResponse(array $response): string
    {
        $json = json_encode($response, JSON_UNESCAPED_UNICODE);

        if ($json !== false) {
            return $json;
        }

        return $this->encode([
            'jsonrpc' => self::JSONRPC_VERSION,
            'error' => [
                'code' => -32603,
                'message' => 'Internal error',
            ],
            'id' => $response['id'] ?? null,
        ]);
    }

    /**
     * Safe json_encode wrapper — always returns a string.
     *
     * Last-resort guard: if even the fallback response cannot be encoded
     * (e.g. an un-encodable id supplied via an array request), return a
     * static JSON string instead of throwing a TypeError.
     *
     * @param array<mixed> $data
     */
    private function encode(array $data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);

        if ($json !== false) {
            return $json;
        }

        // Fallback: hard-coded JSON, guaranteed to encode.
        return '{"jsonrpc":"' . self::JSONRPC_VERSION . '","error":{"code":-32603,"message":"Internal error"},"id":null}';
    }
}
