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
        } catch (\TypeError) {
            if ($isNotification) return null;
            // The params do not fit the handler's signature: wrong argument count
            // (ArgumentCountError extends TypeError) or wrong argument type.
            // Note: `{}` and `[]` both decode to an empty array, so "too few
            // arguments" is reported as invalid params rather than distinguished.
            return $this->errorResponse(-32602, 'Invalid params', $id);
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

        // Check if params is associative (named) or list (positional)
        if (array_is_list($params)) {
            return $handler(...$params);
        }

        // Named params — pass as single associative array
        return $handler($params);
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
