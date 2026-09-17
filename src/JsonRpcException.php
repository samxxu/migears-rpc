<?php

declare(strict_types=1);

namespace MiGears\Rpc;

/**
 * JSON-RPC 2.0 exception.
 *
 * Standard error codes:
 *   -32700  Parse error
 *   -32600  Invalid Request
 *   -32601  Method not found
 *   -32602  Invalid params
 *   -32603  Internal error
 *   -32000  Server error (generic)
 */
final class JsonRpcException extends \RuntimeException
{
    /** @var mixed Optional error data */
    private mixed $data;

    public function __construct(int $code, string $message, mixed $data = null)
    {
        parent::__construct($message, $code);
        $this->data = $data;
    }

    /**
     * Get the optional error data.
     */
    public function getData(): mixed
    {
        return $this->data;
    }

    // --- Factory methods for standard errors ---

    public static function parseError(string $details = ''): self
    {
        return new self(-32700, 'Parse error' . ($details ? ": $details" : ''));
    }

    public static function invalidRequest(string $details = ''): self
    {
        return new self(-32600, 'Invalid Request' . ($details ? ": $details" : ''));
    }

    public static function methodNotFound(string $method): self
    {
        return new self(-32601, "Method not found: $method");
    }

    public static function invalidParams(string $details = ''): self
    {
        return new self(-32602, 'Invalid params' . ($details ? ": $details" : ''));
    }

    public static function internalError(string $details = ''): self
    {
        return new self(-32603, 'Internal error' . ($details ? ": $details" : ''));
    }
}
