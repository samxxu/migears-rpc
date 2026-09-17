<?php

declare(strict_types=1);

namespace MiGears\Rpc\Tests;

use PHPUnit\Framework\TestCase;
use MiGears\Rpc\JsonRpcException;

class JsonRpcExceptionTest extends TestCase
{
    public function testConstructor(): void
    {
        $e = new JsonRpcException(-32601, 'Method not found', ['some' => 'data']);
        $this->assertSame(-32601, $e->getCode());
        $this->assertSame('Method not found', $e->getMessage());
        $this->assertSame(['some' => 'data'], $e->getData());
    }

    public function testParseError(): void
    {
        $e = JsonRpcException::parseError();
        $this->assertSame(-32700, $e->getCode());
        $this->assertStringContainsString('Parse error', $e->getMessage());
    }

    public function testParseErrorWithDetails(): void
    {
        $e = JsonRpcException::parseError('invalid json');
        $this->assertStringContainsString('invalid json', $e->getMessage());
    }

    public function testInvalidRequest(): void
    {
        $e = JsonRpcException::invalidRequest();
        $this->assertSame(-32600, $e->getCode());
    }

    public function testMethodNotFound(): void
    {
        $e = JsonRpcException::methodNotFound('add');
        $this->assertSame(-32601, $e->getCode());
        $this->assertStringContainsString('add', $e->getMessage());
    }

    public function testInvalidParams(): void
    {
        $e = JsonRpcException::invalidParams('missing id');
        $this->assertSame(-32602, $e->getCode());
    }

    public function testInternalError(): void
    {
        $e = JsonRpcException::internalError();
        $this->assertSame(-32603, $e->getCode());
    }

    public function testExtendsRuntimeException(): void
    {
        $e = new JsonRpcException(-1, 'test');
        $this->assertInstanceOf(\RuntimeException::class, $e);
    }

    public function testNullData(): void
    {
        $e = new JsonRpcException(-1, 'test');
        $this->assertNull($e->getData());
    }
}
