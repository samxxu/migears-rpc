# migears-rpc — Known Issues

> Summary of this module's issues. The items themselves are in [`issues/`](issues/README.md), one file
> per item: a front-matter header and a thread. This file is generated from them and can be rewritten at
> any time; edit an item, never this file.
>
> From the miGears Full-Module Code Review Report (6th round, 2026-10-01).

| | |
|---|---|
| Status | **Best state** |
| Size | src 406 lines (net) · 62 tests · 3 src files |

Legend — **P0** functional or security · **P1** documentation that fails when copied · **P2** robustness · **P3** metadata and docs

## At a glance

| | |
|---|---|
| Unsettled | P0 0 · P1 0 · P2 1 · P3 1 · other 0 |
| Settled | 6 of 8 |
| Waiting on the owner | _nothing_ |
| Waiting on the coordinator | `P2-2` |
| Waiting on the reviewer | `P3-2` |
| Deferred, owing nobody | _nothing_ |

| id | level | status | title |
|---|---|---|---|
| [`P2-1`](issues/P2-1.md) | P2 | **verified** | Inside a batch, an element that is an array but not a request object is … |
| [`P2-2`](issues/P2-2.md) | P2 | **question** | Internal errors (code -32603) include the exception's short class name … |
| [`P3-1`](issues/P3-1.md) | P3 | **verified** | `JsonRpcClient::batch()` is documented as returning an 'Array of … |
| [`P3-2`](issues/P3-2.md) | P3 | **rejected** | `notify()` still swallows transport errors with `@` and returns void, … |
| [`P3-3`](issues/P3-3.md) | P3 | **verified** | A parameter type mismatch is classified as Internal error and leaks the … |
| [`P3-4`](issues/P3-4.md) | P3 | **verified** | README claims '~350 lines of source' which is roughly accurate for the … |
| [`P3-5`](issues/P3-5.md) | P3 | **verified** | The `\TypeError` catch added for parameter binding is too broad: a … |
| [`G2`](issues/G2.md) | - | **verified** | Strict flags: `phpunit.xml.dist` currently sets none of the five. The … |

## Unclosed

What is left to do here: every item whose `status` is not `verified` or `closed`,
highest severity first. `waiting on` is the party who acts next, read from that status.

| | |
|---|---|
| Unclosed | **2** of 8 |
| By status | `question` 1 · `rejected` 1 |
| Waiting on | coordinator 1 · reviewer 1 |

| level | item | status | waiting on | title |
|---|---|---|---|---|
| **P2** | [`P2-2`](issues/P2-2.md) | `question` | coordinator | Internal errors (code -32603) include the exception's short class name … |
| **P3** | [`P3-2`](issues/P3-2.md) | `rejected` | reviewer | `notify()` still swallows transport errors with `@` and returns void, … |

## Verdict

Both fixes are load-bearing: reverting either turns a dedicated test red, and the internal-error class-name disclosure is a documented, test-pinned decision waiting on the user.

## Fixed since the last round

P3-4 and P3-5 verified by mutation: the README’s size claim is now ~760 lines against a measured 762, and the over-broad catch (\TypeError) was replaced by a pre-call binding assertion, so a TypeError raised inside a handler is reported as -32603 again.

## Test gaps

There is no test for a server returning a non-JSON body (an HTML 5xx), so parseResponse()’s -32700 branch is only exercised by malformed JSON; the server’s final encode() fallback has no test.

## Verification protocol

- `./vendor/bin/phpunit` · `composer analyse` · `composer validate`
- Warning/notice/deprecation/risky flags in `phpunit.xml.dist`: all four on
- A PHP warning counts as a test failure only where those flags are on; otherwise run `./vendor/bin/phpunit --fail-on-warning` explicitly.


---

# migears-rpc — 已知问题

> 本模块问题的概览。条目本体在 [`issues/`](issues/README.md)，一条目一文件：前置字段加讨论串。
> 本文件由条目生成，随时可以整段重写；请改条目，不要改本文件。
>
> 出自 miGears 全模块代码评审报告（6th round，2026-10-01）。

| | |
|---|---|
| 状态 | **状态最好** |
| 体量 | src 406 行（净）· 62 个用例 · 3 个源文件 |

级别说明 — **P0** 功能性或安全级 · **P1** 文档照抄即错 · **P2** 健壮性 · **P3** 元数据与文档

## 状态一览

| | |
|---|---|
| 未了结 | P0 0 · P1 0 · P2 1 · P3 1 · 其他 0 |
| 已了结 | 6 / 8 |
| 等模块主 | _无_ |
| 等协调人 | `P2-2` |
| 等评审方 | `P3-2` |
| 已暂缓，不欠谁 | _无_ |

| id | 级别 | 状态 | 标题 |
|---|---|---|---|
| [`P2-1`](issues/P2-1.md) | P2 | **verified** | 批量内部，数组但不是请求对象的元素被静默丢弃，而不是各自回一个 -32600。实测 [[1,2], {valid}] 只返回有效那条，[{}, … |
| [`P2-2`](issues/P2-2.md) | P2 | **question** | 内部错误（code -32603）在 data 字段中包含异常的短类名（通过 "class": … |
| [`P3-1`](issues/P3-1.md) | P3 | **verified** | JsonRpcClient::batch() 的 docblock … |
| [`P3-2`](issues/P3-2.md) | P3 | **rejected** | notify() 仍用 @ 吞掉传输错误并返回 void，调用方无法得知通知是否送达。已作为刻意取舍记录在案。 |
| [`P3-3`](issues/P3-3.md) | P3 | **verified** | 参数类型不匹配被归为 Internal error 并泄露类名：签名 fn(int $a) 的处理器收到 ["abc"] 会返回 -32603 … |
| [`P3-4`](issues/P3-4.md) | P3 | **verified** | README 称「约 350 行源码」，三个源文件大致准确，但数字模糊——具体数字或范围会更可信。 |
| [`P3-5`](issues/P3-5.md) | P3 | **verified** | 为参数绑定加入的 `\TypeError` 捕获过宽：处理器体内抛出的 `TypeError` 会被报成 `-32602 Invalid … |
| [`G2`](issues/G2.md) | - | **verified** | 严格开关：`phpunit.xml.dist` … |

## 未关闭

本模块还剩什么要做：所有 `status` 不是 `verified` 或 `closed` 的条目，按严重度从高到低。
`waiting on` 是下一步该动手的一方，由其状态读出。

| | |
|---|---|
| 未关闭 | **2** / 8 |
| 按状态 | `question` 1 · `rejected` 1 |
| 等在谁 | 协调人 1 · 评审方 1 |

| 级别 | 条目 | 状态 | 等在谁 | 标题 |
|---|---|---|---|---|
| **P2** | [`P2-2`](issues/P2-2.md) | `question` | 协调人 | 内部错误（code -32603）在 data 字段中包含异常的短类名（通过 "class": … |
| **P3** | [`P3-2`](issues/P3-2.md) | `rejected` | 评审方 | notify() 仍用 @ 吞掉传输错误并返回 void，调用方无法得知通知是否送达。已作为刻意取舍记录在案。 |

## 结论

两处修复都承重：还原任一处都会让专门用例转红；内部错误泄露类名则是有文档、有用例钉住、等待用户裁定的决定。

## 本轮已修复确认

P3-4 and P3-5 verified by mutation: the README’s size claim is now ~760 lines against a measured 762, and the over-broad catch (\TypeError) was replaced by a pre-call binding assertion, so a TypeError raised inside a handler is reported as -32603 again.

## 测试盲区

无「服务端返回非 JSON 正文（如 5xx 的 HTML）」用例，parseResponse() 的 -32700 分支只由畸形 JSON 覆盖；服务端 encode() 的最后兜底无用例。

## 验证方式

- `./vendor/bin/phpunit` · `composer analyse` · `composer validate`
- `phpunit.xml.dist` 中的 warning/notice/deprecation/risky 开关：四个全开
- 只有在上述开关打开时 PHP 警告才会导致套件失败；否则请显式加 `--fail-on-warning`。
