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

    private const string JSONRPC_VERSION = '2.0';

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
     * Handle a JSON-RPC request string and return the response JSON string.
     *
     * Returns empty string for notifications (no response needed).
     */
    public function handle(string $rawRequest): string
    {
        $request = json_decode($rawRequest, true);

        if ($request === null && json_last_error() !== JSON_ERROR_NONE) {
            return $this->encodeError(-32700, 'Parse error');
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

        return json_encode($response, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Handle a single request array.
     *
     * @return array<string, mixed>|null Response array, or null for notifications
     *         (including notifications that fail validation or method lookup)
     */
    private function handleSingle(array $request): ?array
    {
        $id = $request['id'] ?? null;
        $isNotification = $id === null;

        // Validate request structure
        if (!isset($request['jsonrpc']) || $request['jsonrpc'] !== self::JSONRPC_VERSION) {
            if ($isNotification) return null;
            return $this->errorResponse(-32600, 'Invalid Request', $id);
        }

        if (!isset($request['method']) || !is_string($request['method'])) {
            if ($isNotification) return null;
            return $this->errorResponse(-32600, 'Invalid Request', $id);
        }

        $method = $request['method'];
        $params = $request['params'] ?? [];

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
            return $this->errorResponse(-32603, 'Internal error', $id, $e->getMessage());
        }
    }

    /**
     * Handle a batch of requests.
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

        return json_encode($responses, JSON_UNESCAPED_UNICODE);
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
        return json_encode([
            'jsonrpc' => self::JSONRPC_VERSION,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
            'id' => null,
        ], JSON_UNESCAPED_UNICODE);
    }
}
