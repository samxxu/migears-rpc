# migears/rpc

![Version](https://img.shields.io/badge/version-2.0.0-blue)

Minimalist JSON-RPC 2.0 client and server for PHP — zero dependencies, pure PHP streams.

A lightweight, fully compliant implementation of JSON-RPC 2.0. No curl required, no external dependencies. Just ~630 lines of code total across three classes: client, server, and exception.

> **Background**: miGears is the open-source successor of **TinyGears**, a
> self-developed PHP framework. It was renamed and open-sourced recently because
> the name *TinyGears* is already taken in the open-source community.

## Features

- **Full JSON-RPC 2.0 compliance** — single calls, notifications, batch requests
- **Zero dependencies** — uses PHP stream contexts, no curl required
- **Client & server** — both sides included, use either or both
- **Positional & named params** — automatic detection on the server side
- **Standard error codes** — parse error, invalid request, method not found, invalid params, internal error
- **Batch requests** — mix calls and notifications in a single batch
- **Notification support** — fire-and-forget, no response expected
- **~630 lines total** — readable, auditable, understandable

## Installation

```bash
composer require migears/rpc
```

Requires: PHP 8.1+.

## Quick Start

### Client

```php
use MiGears\Rpc\JsonRpcClient;
use MiGears\Rpc\JsonRpcException;

$client = new JsonRpcClient('https://api.example.com/rpc');

// Single call
$result = $client->call('add', [1, 2]);
echo $result; // 3

// Named params
$result = $client->call('getUser', ['id' => 42]);

// Notification (fire and forget)
$client->notify('user.loggedIn', ['userId' => 42]);

// Error handling
try {
    $client->call('nonexistentMethod');
} catch (JsonRpcException $e) {
    echo $e->getCode();    // -32601
    echo $e->getMessage(); // Method not found
    echo $e->getData();    // optional error data
}
```

### Server

```php
use MiGears\Rpc\JsonRpcServer;
use MiGears\Rpc\JsonRpcException;

$server = new JsonRpcServer();

// Register methods
$server->register('add', function (int $a, int $b): int {
    return $a + $b;
});

$server->register('divide', function (int $a, int $b): float {
    if ($b === 0) {
        throw JsonRpcException::invalidParams('Division by zero');
    }
    return $a / $b;
});

// Handle request from HTTP POST
$request = file_get_contents('php://input');
$response = $server->handle($request);

if ($response !== '') {
    header('Content-Type: application/json');
    echo $response;
}
```

### Batch Requests

```php
// Client batch
$results = $client->batch([
    ['method' => 'add', 'params' => [1, 2], 'id' => 1],
    ['method' => 'subtract', 'params' => [10, 5], 'id' => 2],
    ['method' => 'ping'], // notification (no id)
]);
```

### Custom Headers

```php
$client = new JsonRpcClient('https://api.example.com/rpc');
$client->setHeader('Authorization', 'Bearer ' . $token);
$client->setHeader('X-API-Key', 'my-key');
```

## API Reference

### JsonRpcClient

| Method | Description |
|--------|-------------|
| `__construct(string $endpoint, float $timeout = 10.0, array $headers = [])` | Create a new client |
| `call(string $method, array $params = []): mixed` | Call a remote method and return the result |
| `notify(string $method, array $params = []): void` | Send a notification (no response; uses `NOTIFY_TIMEOUT`) |
| `batch(array $requests): array` | Send a batch of requests; returns the decoded response envelopes |
| `setHeader(string $name, string $value): self` | Add a custom HTTP header |
| `getLastRequestId(): int` | Get the current request counter value |

### JsonRpcServer

| Method | Description |
|--------|-------------|
| `register(string $method, callable $handler): self` | Register a method handler |
| `has(string $method): bool` | Check if a method is registered |
| `handle(string\|array $request): string` | Handle a raw JSON string or a decoded request array, return response JSON |

### JsonRpcException

| Static Factory | Code |
|----------------|------|
| `parseError(string $details = '')` | -32700 |
| `invalidRequest(string $details = '')` | -32600 |
| `methodNotFound(string $method)` | -32601 |
| `invalidParams(string $details = '')` | -32602 |
| `internalError(string $details = '')` | -32603 |

## Behaviour Notes

**Error `data` field.** A handler that throws `JsonRpcException` controls the error verbatim, including its optional `data`. Any other exception becomes `-32603 Internal error` whose `data` holds only the exception's short class name (e.g. `PDOException`); the raw message is deliberately withheld because it can carry DSNs, credentials and filesystem paths.

**Batch responses** are always a JSON array. If a single result cannot be encoded (for example invalid UTF-8 returned by a handler), only that element becomes a `-32603` error — its `id` is preserved, and the remaining results are returned intact.

**Server-side notifications.** A request without an `id` is only a notification once it is a well-formed Request object. An element that carries no `id` and is not one — `{}`, `[1, 2]`, an unregistered `jsonrpc` version — is answered with `-32600`, since it is not a notification at all; this holds for batch elements too. Content errors on a genuine notification (unknown method, unusable params) are never replied to, as the spec requires.

**Notifications.** `notify()` never waits for the configured request timeout. It uses `JsonRpcClient::NOTIFY_TIMEOUT` (0.5 s) and swallows transport errors, because a notification has no response to report back.

**Known limitations.** After `json_decode()`, `params: {}` and `params: []` are indistinguishable (both `[]`), so an empty param set is passed as zero arguments and a handler that requires arguments answers `-32602 Invalid params`. Likewise, a single request object whose keys happen to be exactly `0..n-1` decodes into a list and is treated as a batch request.

## Design Philosophy

miGears RPC follows the miGears philosophy: **minimal, readable, and useful**.

- **No bloat** — just the JSON-RPC 2.0 spec, nothing more
- **No magic** — explicit method registration, no auto-discovery
- **No dependencies** — pure PHP, uses `file_get_contents` with stream contexts
- **Small enough to read** — three classes, ~630 lines total

**What we don't do**:
- No transport layer abstractions (HTTP, TCP, etc.) — bring your own
- No service discovery or auto-registration
- No middleware system (wrap `handle()` if you need it)
- No built-in authentication (use headers on the client, validate in handlers on the server)

## Integration with miGears Web

```php
use MiGears\Web\MiRest;
use MiGears\Web\AbstractResource;
use MiGears\Web\Request;
use MiGears\Web\Response;
use MiGears\Rpc\JsonRpcServer;

$rest = new MiRest(__DIR__ . '/resources', 'App\\Resources');

// Share the server as a service
$rest->set('rpc', function () {
    $server = new JsonRpcServer();
    $server->register('ping', fn() => 'pong');
    return $server;
});

// In a resource:
class RpcEndpoint extends AbstractResource
{
    public function POST(Request $request): Response
    {
        // resolve() is the container accessor defined on AbstractResource.
        // $request->body is already decoded by the web layer, and handle()
        // accepts a decoded array directly — no need to re-encode it.
        $server = $this->resolve('rpc');
        $json = $server->handle($request->body);

        // A notification produces no response body.
        if ($json === '') {
            return Response::empty();
        }

        // Pass the JSON through verbatim instead of re-encoding it.
        return (new Response($json))
            ->withHeader('Content-Type', 'application/json; charset=utf-8');
    }
}
```

## License

MIT

---

# migears/rpc

![Version](https://img.shields.io/badge/version-2.0.0-blue)

极简 JSON-RPC 2.0 客户端与服务端 — 零依赖，纯 PHP 流实现。

轻量级、完全兼容 JSON-RPC 2.0 规范的实现。不需要 curl，没有外部依赖。三个类总共约 630 行代码：客户端、服务端和异常类。

## 特性

- **完全兼容 JSON-RPC 2.0** — 单次调用、通知、批量请求
- **零依赖** — 使用 PHP 流上下文，不需要 curl
- **客户端 & 服务端** — 两端都包含，可单独使用
- **位置参数与命名参数** — 服务端自动检测
- **标准错误码** — 解析错误、无效请求、方法未找到、无效参数、内部错误
- **批量请求** — 单次批量中可混合调用和通知
- **通知支持** — 发后即忘，不需要响应
- **总共约 630 行** — 可读、可审计、可理解

## 安装

```bash
composer require migears/rpc
```

要求：PHP 8.1+。

## 快速开始

### 客户端

```php
use MiGears\Rpc\JsonRpcClient;
use MiGears\Rpc\JsonRpcException;

$client = new JsonRpcClient('https://api.example.com/rpc');

// 单次调用
$result = $client->call('add', [1, 2]);
echo $result; // 3

// 命名参数
$result = $client->call('getUser', ['id' => 42]);

// 通知（发后即忘）
$client->notify('user.loggedIn', ['userId' => 42]);

// 错误处理
try {
    $client->call('nonexistentMethod');
} catch (JsonRpcException $e) {
    echo $e->getCode();    // -32601
    echo $e->getMessage(); // Method not found: nonexistentMethod
    echo $e->getData();    // 可选的错误数据
}
```

### 服务端

```php
use MiGears\Rpc\JsonRpcServer;
use MiGears\Rpc\JsonRpcException;

$server = new JsonRpcServer();

// 注册方法
$server->register('add', function (int $a, int $b): int {
    return $a + $b;
});

$server->register('divide', function (int $a, int $b): float {
    if ($b === 0) {
        throw JsonRpcException::invalidParams('除数不能为零');
    }
    return $a / $b;
});

// 处理 HTTP POST 请求
$request = file_get_contents('php://input');
$response = $server->handle($request);

if ($response !== '') {
    header('Content-Type: application/json');
    echo $response;
}
```

### 批量请求

```php
// 客户端批量
$results = $client->batch([
    ['method' => 'add', 'params' => [1, 2], 'id' => 1],
    ['method' => 'subtract', 'params' => [10, 5], 'id' => 2],
    ['method' => 'ping'], // 通知（无 id）
]);
```

### 自定义请求头

```php
$client = new JsonRpcClient('https://api.example.com/rpc');
$client->setHeader('Authorization', 'Bearer ' . $token);
$client->setHeader('X-API-Key', 'my-key');
```

## API 参考

### JsonRpcClient

| 方法 | 说明 |
|------|------|
| `__construct(string $endpoint, float $timeout = 10.0, array $headers = [])` | 创建新客户端 |
| `call(string $method, array $params = []): mixed` | 调用远程方法并返回结果 |
| `notify(string $method, array $params = []): void` | 发送通知（无响应；使用 `NOTIFY_TIMEOUT`） |
| `batch(array $requests): array` | 发送批量请求；返回解码后的响应信封 |
| `setHeader(string $name, string $value): self` | 添加自定义 HTTP 头 |
| `getLastRequestId(): int` | 获取当前请求计数器值 |

### JsonRpcServer

| 方法 | 说明 |
|------|------|
| `register(string $method, callable $handler): self` | 注册方法处理器 |
| `has(string $method): bool` | 检查方法是否已注册 |
| `handle(string\|array $request): string` | 处理原始 JSON 字符串或已解码的请求数组，返回响应 JSON |

### JsonRpcException

| 静态工厂方法 | 错误码 |
|--------------|--------|
| `parseError(string $details = '')` | -32700 |
| `invalidRequest(string $details = '')` | -32600 |
| `methodNotFound(string $method)` | -32601 |
| `invalidParams(string $details = '')` | -32602 |
| `internalError(string $details = '')` | -32603 |

## 行为说明

**错误 `data` 字段。** 处理器主动抛出 `JsonRpcException` 时，错误内容（含可选 `data`）原样透传。其他异常统一变为 `-32603 Internal error`，其 `data` 只放异常短类名（如 `PDOException`）；原始 message 一律不回传，因为它可能携带 DSN、账号密码与文件路径。

**批量响应**始终是 JSON 数组。若某一条结果无法编码（例如处理器返回非法 UTF-8），只有该条变成 `-32603` 错误，且保留其原始 `id`，其余结果不受影响、完整返回。

**服务端通知语义。** 只有当一个不带 `id` 的元素本身是合法的 Request 对象时，它才算通知。不带 `id` 但不是合法请求的输入——`{}`、`[1, 2]`、`jsonrpc` 版本不对——一律返回 `-32600`，因为它根本不是通知；批量内部的元素同样适用。真正通知上的内容级错误（方法未找到、参数不可用）则依规范不予响应。

**通知。** `notify()` 不等待构造器配置的请求超时，而是使用 `JsonRpcClient::NOTIFY_TIMEOUT`（0.5 秒）并吞掉传输层错误——通知本就没有响应需要回传。

**已知限制。** 经 `json_decode()` 之后，`params: {}` 与 `params: []` 无法区分（都是 `[]`），因此空参数集会以「零个参数」调用处理器，需要参数的处理器将返回 `-32602 Invalid params`。同理，单个请求对象的键恰好为 `0..n-1` 时会被解码成列表，从而按批量请求处理。

## 设计哲学

miGears RPC 遵循 miGears 设计哲学：**极简、可读、实用**。

- **不臃肿** — 只实现 JSON-RPC 2.0 规范，不多不少
- **不魔法** — 显式方法注册，没有自动发现
- **零依赖** — 纯 PHP，使用 `file_get_contents` + 流上下文
- **小到可以读完** — 三个类，总共约 630 行

**我们不做的事**：
- 没有传输层抽象（HTTP、TCP 等）— 自己选择传输方式
- 没有服务发现或自动注册
- 没有中间件系统（需要的话可以包装 `handle()`）
- 没有内置认证（客户端用请求头，服务端在处理器中验证）

## 与 miGears Web 集成

```php
use MiGears\Web\MiRest;
use MiGears\Web\AbstractResource;
use MiGears\Web\Request;
use MiGears\Web\Response;
use MiGears\Rpc\JsonRpcServer;

$rest = new MiRest(__DIR__ . '/resources', 'App\\Resources');

// 将服务端注册为服务
$rest->set('rpc', function () {
    $server = new JsonRpcServer();
    $server->register('ping', fn() => 'pong');
    return $server;
});

// 在资源类中：
class RpcEndpoint extends AbstractResource
{
    public function POST(Request $request): Response
    {
        // resolve() 是 AbstractResource 提供的容器取值方法。
        // $request->body 已被 web 层解码为数组，handle() 可直接接收，
        // 无需重新编码。
        $server = $this->resolve('rpc');
        $json = $server->handle($request->body);

        // 通知不产生响应体。
        if ($json === '') {
            return Response::empty();
        }

        // 原样透传 JSON，避免二次编解码。
        return (new Response($json))
            ->withHeader('Content-Type', 'application/json; charset=utf-8');
    }
}
```

## 许可证

MIT
