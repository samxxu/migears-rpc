# migears-rpc — Known Issues / 已知问题

> Summary of this module's issues. The items themselves are in [`issues/`](issues/README.md), one file
> per item: a front-matter header and a thread. This file is generated from them and can be rewritten at
> any time; edit an item, never this file.
>
> 本模块问题的概览。条目本体在 [`issues/`](issues/README.md)，一条目一文件：前置字段加讨论串。
> 本文件由条目生成，随时可以整段重写；请改条目，不要改本文件。
>
> From the miGears Full-Module Code Review Report (4th round, 2026-09-27).

| | |
|---|---|
| Status / 状态 | **P0 cleared / P0 已清零** |
| Size / 体量 | src 626 lines (351 net) · 50 tests · 3 src files |

Legend / 图例 — **P0** functional or security · **P1** documentation that fails when copied · **P2** robustness · **P3** metadata and docs
级别说明 — **P0** 功能性或安全级 · **P1** 文档照抄即错 · **P2** 健壮性 · **P3** 元数据与文档

## At a glance / 状态一览

| | |
|---|---|
| Items / 条目 | P0 0 · P1 0 · P2 1 · P3 3 · other 1 |
| Answered / 已回复 | 5 of 5 |
| Waiting / 等待回复 | _nothing / 无_ |

| id | level | status | title |
|---|---|---|---|
| [`P2-1`](issues/P2-1.md) | P2 | **fixed** | Inside a batch, an element that is an array but not a request object is … |
| [`P3-1`](issues/P3-1.md) | P3 | **fixed** | `JsonRpcClient::batch()` is documented as returning an 'Array of … |
| [`P3-2`](issues/P3-2.md) | P3 | **rejected** | `notify()` still swallows transport errors with `@` and returns void, … |
| [`P3-3`](issues/P3-3.md) | P3 | **fixed** | A parameter type mismatch is classified as Internal error and leaks the … |
| [`G2`](issues/G2.md) | - | **fixed** | Strict flags: `phpunit.xml.dist` currently sets none of the five. The … |

## Verdict / 结论

Both P0s are genuinely fixed and the README now documents its protocol trade-offs. The one remaining functional gap is inside batches: elements that are arrays but not requests are dropped without an error, where the top level correctly answers -32600.

两个 P0 都真切修好了，README 也开始记录协议取舍。剩下的功能缺口在批量内部：数组但不是请求对象的元素被静默丢弃，而顶层同样输入会正确回 -32600。

## Fixed since the last round / 本轮已修复确认

上一轮两个 P0 均已修复：批量编码失败不再把整个响应塌成单对象（逐元素编码后拼数组），params:{} 由 -32603 加类名泄漏改为 -32602 且不带 data（仅「语法上无法区分 {} 与 []」作为已知限制写进 README）。P1-1 的 README 集成示例已改为 resolve() 与 $request->body。单请求编码失败保留原 id，客户端容忍字符串 id；notify 超时改为具名常量并清空 last error。 

## Test gaps / 测试盲区

No case for a nested array or empty object as a batch element (the existing invalid-request test uses a string, which happens to bypass it); no case for a parameter type mismatch; no test for the client batch id association.

无「批量元素为嵌套数组或空对象」用例（现有非法请求用例用的是字符串，恰好绕开）；无「参数类型不匹配」用例；无客户端 batch 的 id 关联用例。

## Verification protocol / 验证方式

- `./vendor/bin/phpunit` · `composer analyse` · `composer validate`
- Warning/notice/deprecation/risky flags in `phpunit.xml.dist`: all four on
- A PHP warning counts as a test failure only where those flags are on; otherwise run `./vendor/bin/phpunit --fail-on-warning` explicitly.
- 只有在上述开关打开时 PHP 警告才会导致套件失败；否则请显式加 `--fail-on-warning`。
