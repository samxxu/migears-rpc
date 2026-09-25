<?php

declare(strict_types=1);

namespace MiGears\Rpc;

/**
 * JSON-RPC 2.0 client.
 *
 * Lightweight implementation using PHP stream contexts.
 * No curl dependency. Supports single calls, notifications,
 * and batch requests.
 *
 * Usage:
 *   $client = new JsonRpcClient('https://api.example.com/rpc');
 *   $result = $client->call('add', [1, 2]);
 *   $client->notify('ping');
 */
class JsonRpcClient
{
    public const VERSION = '2.0.0';

    /** @var string JSON-RPC protocol version */
    private const JSONRPC_VERSION = '2.0';

    private int $requestId = 0;

    /**
     * @param string $endpoint URL of the JSON-RPC server
     * @param float $timeout Request timeout in seconds
     * @param array<string, string> $headers Additional HTTP headers
     */
    public function __construct(
        private readonly string $endpoint,
        private readonly float $timeout = 10.0,
        private array $headers = [],
    ) {}

    /**
     * Call a remote method and return the result.
     *
     * @param string $method Method name
     * @param array<int|string, mixed> $params Parameters (array or associative array)
     *
     * @throws JsonRpcException If the response contains an error
     * @return mixed The result from the server
     */
    public function call(string $method, array $params = []): mixed
    {
        $id = ++$this->requestId;

        $request = [
            'jsonrpc' => self::JSONRPC_VERSION,
            'method' => $method,
            'params' => $params,
            'id' => $id,
        ];

        $response = $this->postJson($request);
        $data = $this->parseResponse($response);

        // Validate response
        if (!isset($data['jsonrpc']) || $data['jsonrpc'] !== self::JSONRPC_VERSION) {
            throw new JsonRpcException(-32603, 'Invalid response: missing or wrong jsonrpc version');
        }

        if (!array_key_exists('id', $data) || $data['id'] !== $id) {
            throw new JsonRpcException(-32603, 'Invalid response: id mismatch');
        }

        if (isset($data['error'])) {
            $error = $data['error'];
            throw new JsonRpcException(
                (int) ($error['code'] ?? -32603),
                (string) ($error['message'] ?? 'Unknown error'),
                $error['data'] ?? null,
            );
        }

        if (!array_key_exists('result', $data)) {
            throw new JsonRpcException(-32603, 'Invalid response: missing result');
        }

        return $data['result'];
    }

    /**
     * Send a notification (no response expected).
     *
     * Notifications are fire-and-forget — no id is sent
     * and no response is waited for.
     *
     * @param string $method Method name
     * @param array<int|string, mixed> $params Parameters
     */
    public function notify(string $method, array $params = []): void
    {
        $request = [
            'jsonrpc' => self::JSONRPC_VERSION,
            'method' => $method,
            'params' => $params,
            // No id = notification
        ];

        $this->postJson($request, waitForResponse: false);
    }

    /**
     * Send a batch of requests.
     *
     * @param list<array{method: string, params?: array, id?: int|string|null}> $requests
     * @return array<mixed> Array of results (in the same order)
     */
    public function batch(array $requests): array
    {
        $batch = [];
        foreach ($requests as $req) {
            $item = [
                'jsonrpc' => self::JSONRPC_VERSION,
                'method' => $req['method'],
            ];
            if (isset($req['params'])) {
                $item['params'] = $req['params'];
            }
            if (array_key_exists('id', $req)) {
                $item['id'] = $req['id'];
            }
            $batch[] = $item;
        }

        $response = $this->postJson($batch);
        $data = json_decode($response, true);

        if (!is_array($data)) {
            throw new JsonRpcException(-32603, 'Invalid batch response');
        }

        // Each element of a batch response must itself be a response object.
        foreach ($data as $entry) {
            if (!is_array($entry)) {
                throw new JsonRpcException(-32603, 'Invalid batch response');
            }
        }

        return $data;
    }

    /**
     * Add a custom HTTP header.
     */
    public function setHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    /**
     * Get the current request counter value.
     */
    public function getLastRequestId(): int
    {
        return $this->requestId;
    }

    /**
     * Send JSON via POST and return the response body.
     */
    private function postJson(array $payload, bool $waitForResponse = true): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            throw new JsonRpcException(-32603, 'Failed to encode request JSON');
        }

        $headers = array_merge([
            'Content-Type: application/json',
            'Accept: application/json',
        ], $this->headers);

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => $json,
                'timeout' => $this->timeout,
                'ignore_errors' => true,
            ],
        ]);

        if (!$waitForResponse) {
            // Fire and forget — use a short timeout and discard response
            $ctx = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => implode("\r\n", $headers),
                    'content' => $json,
                    'timeout' => 0.5,
                    'ignore_errors' => true,
                ],
            ]);
            @file_get_contents($this->endpoint, false, $ctx);
            return '';
        }

        $response = @file_get_contents($this->endpoint, false, $context);

        if ($response === false) {
            $error = error_get_last();
            throw new JsonRpcException(
                -32000,
                'Request failed: ' . ($error['message'] ?? 'Unknown error'),
            );
        }

        return $response;
    }

    /**
     * Parse a JSON-RPC response body.
     */
    private function parseResponse(string $body): array
    {
        $data = json_decode($body, true);

        if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new JsonRpcException(
                -32700,
                'Parse error: ' . json_last_error_msg(),
            );
        }

        if (!is_array($data)) {
            throw new JsonRpcException(-32603, 'Invalid response format');
        }

        return $data;
    }
}
