# migears-rpc — Known Issues

> Summary of this module's issues. The items themselves are in [`issues/`](issues/README.md), one file
> per item: a front-matter header and a thread. This file is generated from them and can be rewritten at
> any time; edit an item, never this file.
>
> From the miGears Full-Module Code Review Report (5th round, 2026-09-28).

| | |
|---|---|
| Status | **P2 open** |
| Size | src 348 lines (net) · 57 tests · 3 src files |

Legend — **P0** functional or security · **P1** documentation that fails when copied · **P2** robustness · **P3** metadata and docs

## At a glance

| | |
|---|---|
| Unsettled | P0 0 · P1 0 · P2 1 · P3 3 · other 1 |
| Settled | 0 of 5 |
| Waiting on the owner | _nothing_ |
| Waiting on the reviewer | `P2-1`, `P3-1`, `P3-2`, `P3-3`, `G2` |
| Waiting on the coordinator | _nothing_ |
| Deferred, owing nobody | _nothing_ |

| id | level | status | title |
|---|---|---|---|
| [`P2-1`](issues/P2-1.md) | P2 | **fixed** | Inside a batch, an element that is an array but not a request object is … |
| [`P3-1`](issues/P3-1.md) | P3 | **fixed** | `JsonRpcClient::batch()` is documented as returning an 'Array of … |
| [`P3-2`](issues/P3-2.md) | P3 | **rejected** | `notify()` still swallows transport errors with `@` and returns void, … |
| [`P3-3`](issues/P3-3.md) | P3 | **fixed** | A parameter type mismatch is classified as Internal error and leaks the … |
| [`G2`](issues/G2.md) | - | **fixed** | Strict flags: `phpunit.xml.dist` currently sets none of the five. The … |

## Unclosed

What is left to do here: every item whose `status` is not `verified` or `closed`,
highest severity first. `waiting on` is the party who acts next, read from that status.

| | |
|---|---|
| Unclosed | **5** of 5 |
| By status | `rejected` 1 · `fixed` 4 |
| Waiting on | reviewer 5 |

| level | item | status | waiting on | title |
|---|---|---|---|---|
| **P2** | [`P2-1`](issues/P2-1.md) | `fixed` | reviewer | Inside a batch, an element that is an array but not a request object is … |
| **P3** | [`P3-1`](issues/P3-1.md) | `fixed` | reviewer | `JsonRpcClient::batch()` is documented as returning an 'Array of … |
| **P3** | [`P3-2`](issues/P3-2.md) | `rejected` | reviewer | `notify()` still swallows transport errors with `@` and returns void, … |
| **P3** | [`P3-3`](issues/P3-3.md) | `fixed` | reviewer | A parameter type mismatch is classified as Internal error and leaks the … |
| **-** | [`G2`](issues/G2.md) | `fixed` | reviewer | Strict flags: `phpunit.xml.dist` currently sets none of the five. The … |

## Verdict

A compact JSON-RPC 2.0 server with proper batch handling and error code mapping; internal errors still leak the exception's short class name through the data field — an information-disclosure gap.

## Fixed since the last round

All prior P2/P3 items confirmed fixed: dropped batch elements now return -32600 per element; TypeError on params now maps to -32602 not -32603; G2 strict flags complete.

## Test gaps

No test for request with non-object params (array params are valid per spec); no test for very large request payloads; no test for notification (no id) batch responses.

## Verification protocol

- `./vendor/bin/phpunit` · `composer analyse` · `composer validate`
- Warning/notice/deprecation/risky flags in `phpunit.xml.dist`: all four on
- A PHP warning counts as a test failure only where those flags are on; otherwise run `./vendor/bin/phpunit --fail-on-warning` explicitly.


---

# migears-rpc — 已知问题

> 本模块问题的概览。条目本体在 [`issues/`](issues/README.md)，一条目一文件：前置字段加讨论串。
> 本文件由条目生成，随时可以整段重写；请改条目，不要改本文件。
>
> 出自 miGears 全模块代码评审报告（5th round，2026-09-28）。

| | |
|---|---|
| 状态 | **P2 待修** |
| 体量 | src 348 行（净）· 57 个用例 · 3 个源文件 |

级别说明 — **P0** 功能性或安全级 · **P1** 文档照抄即错 · **P2** 健壮性 · **P3** 元数据与文档

## 状态一览

| | |
|---|---|
| 未了结 | P0 0 · P1 0 · P2 1 · P3 3 · 其他 1 |
| 已了结 | 0 / 5 |
| 等负责人 | _无_ |
| 等评审方 | `P2-1`, `P3-1`, `P3-2`, `P3-3`, `G2` |
| 等协调人 | _无_ |
| 已暂缓，不欠谁 | _无_ |

| id | 级别 | 状态 | 标题 |
|---|---|---|---|
| [`P2-1`](issues/P2-1.md) | P2 | **fixed** | 批量内部，数组但不是请求对象的元素被静默丢弃，而不是各自回一个 -32600。实测 [[1,2], {valid}] 只返回有效那条，[{}, … |
| [`P3-1`](issues/P3-1.md) | P3 | **fixed** | JsonRpcClient::batch() 的 docblock … |
| [`P3-2`](issues/P3-2.md) | P3 | **rejected** | notify() 仍用 @ 吞掉传输错误并返回 void，调用方无法得知通知是否送达。已作为刻意取舍记录在案。 |
| [`P3-3`](issues/P3-3.md) | P3 | **fixed** | 参数类型不匹配被归为 Internal error 并泄露类名：签名 fn(int $a) 的处理器收到 ["abc"] 会返回 -32603 … |
| [`G2`](issues/G2.md) | - | **fixed** | 严格开关：`phpunit.xml.dist` … |

## 未关闭

本模块还剩什么要做：所有 `status` 不是 `verified` 或 `closed` 的条目，按严重度从高到低。
`waiting on` 是下一步该动手的一方，由其状态读出。

| | |
|---|---|
| 未关闭 | **5** / 5 |
| 按状态 | `rejected` 1 · `fixed` 4 |
| 等在谁 | 评审方 5 |

| 级别 | 条目 | 状态 | 等在谁 | 标题 |
|---|---|---|---|---|
| **P2** | [`P2-1`](issues/P2-1.md) | `fixed` | 评审方 | 批量内部，数组但不是请求对象的元素被静默丢弃，而不是各自回一个 -32600。实测 [[1,2], {valid}] 只返回有效那条，[{}, … |
| **P3** | [`P3-1`](issues/P3-1.md) | `fixed` | 评审方 | JsonRpcClient::batch() 的 docblock … |
| **P3** | [`P3-2`](issues/P3-2.md) | `rejected` | 评审方 | notify() 仍用 @ 吞掉传输错误并返回 void，调用方无法得知通知是否送达。已作为刻意取舍记录在案。 |
| **P3** | [`P3-3`](issues/P3-3.md) | `fixed` | 评审方 | 参数类型不匹配被归为 Internal error 并泄露类名：签名 fn(int $a) 的处理器收到 ["abc"] 会返回 -32603 … |
| **-** | [`G2`](issues/G2.md) | `fixed` | 评审方 | 严格开关：`phpunit.xml.dist` … |

## 结论

一个紧凑的 JSON-RPC 2.0 服务器，批量处理与错误码映射正确；内部错误仍通过 data 字段泄露异常的短类名——信息泄露缺口。

## 本轮已修复确认

All prior P2/P3 items confirmed fixed: dropped batch elements now return -32600 per element; TypeError on params now maps to -32602 not -32603; G2 strict flags complete.

## 测试盲区

无非对象 params 请求测试（按规范数组 params 也合法）；无超大请求负载测试；无通知（无 id）批量响应测试。

## 验证方式

- `./vendor/bin/phpunit` · `composer analyse` · `composer validate`
- `phpunit.xml.dist` 中的 warning/notice/deprecation/risky 开关：四个全开
- 只有在上述开关打开时 PHP 警告才会导致套件失败；否则请显式加 `--fail-on-warning`。
