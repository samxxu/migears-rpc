# migears-rpc — Known Issues / 已知问题

> Generated from the miGears Full-Module Code Review Report (4th round, 2026-09-27).
> This file has two regions. Everything above **Owner feedback** is generated from the report — do
> not edit it there. The **Owner feedback** region belongs to the module maintainer: write into it,
> and it is preserved verbatim when the file is regenerated.
> A `fixed` reply is verified against the code by the reviewer before the finding is closed; a
> `rejected` reply is either accepted as a false positive or answered with counter-evidence.
>
> 本文件分两个区域。**「负责人反馈」之前的全部内容**由评审报告生成，请勿在该区修改；
> **「负责人反馈」区**归模块负责人所有，重新生成时会原样保留。
> 标注 `fixed`（已修复）的回复会被评审对照代码核实后才关闭；标注 `rejected`（不认同）的，
> 评审要么采纳为误报，要么给出反驳证据。
>
> 摘自 miGears 全模块代码评审报告（第四轮，2026-09-27）。

| | |
|---|---|
| Status / 状态 | **P0 cleared / P0 已清零** |
| Findings / 问题 | P0 0 · P1 0 · P2 1 · P3 3 |
| Size / 体量 | src 626 lines (351 net) · 50 tests · 3 src files |

Legend / 图例 — **P0** functional or security · **P1** documentation that fails when copied · **P2** robustness · **P3** metadata and docs
级别说明 — **P0** 功能性或安全级 · **P1** 文档照抄即错 · **P2** 健壮性 · **P3** 元数据与文档

## Verdict / 结论

Both P0s are genuinely fixed and the README now documents its protocol trade-offs. The one remaining functional gap is inside batches: elements that are arrays but not requests are dropped without an error, where the top level correctly answers -32600.

两个 P0 都真切修好了，README 也开始记录协议取舍。剩下的功能缺口在批量内部：数组但不是请求对象的元素被静默丢弃，而顶层同样输入会正确回 -32600。

## Fixed since the last round / 本轮已修复确认

上一轮两个 P0 均已修复：批量编码失败不再把整个响应塌成单对象（逐元素编码后拼数组），params:{} 由 -32603 加类名泄漏改为 -32602 且不带 data（仅「语法上无法区分 {} 与 []」作为已知限制写进 README）。P1-1 的 README 集成示例已改为 resolve() 与 $request->body。单请求编码失败保留原 id，客户端容忍字符串 id；notify 超时改为具名常量并清空 last error。 

## Open findings / 未修问题


### P2

**P2-1** — `src/JsonRpcServer.php:170-189,99-114`

- EN: Inside a batch, an element that is an array but not a request object is silently dropped instead of producing its own -32600 error. Measured: `[[1,2], {valid}]` returns only the valid response, and `[{}, {valid}]` likewise, while a bare top-level `{}` correctly returns -32600. JSON-RPC requires one error object per invalid element<sup><a href="#cite-3">[3]</a></sup>.
- 中文: 批量内部，数组但不是请求对象的元素被静默丢弃，而不是各自回一个 -32600。实测 [[1,2], {valid}] 只返回有效那条，[{}, {valid}] 同样，而顶层单独的 {} 会正确返回 -32600。JSON-RPC 要求每个非法元素各回一个 error 对象<sup><a href="#cite-3">[3]</a></sup>。
- Verification / 验证: reproduced / 已实证


### P3

**P3-1** — `src/JsonRpcClient.php:111-112`

- EN: `JsonRpcClient::batch()` is documented as returning an "Array of results" but actually returns the decoded response envelopes (`jsonrpc`/`result`/`id`), and performs no id association or error extraction.
- 中文: JsonRpcClient::batch() 的 docblock 写「结果数组」，实际返回的是解码后的响应信封（jsonrpc/result/id），且不做 id 关联或错误提取。
- Verification / 验证: static / 仅静态推断

**P3-2** — `src/JsonRpcClient.php:216`

- EN: `notify()` still swallows transport errors with `@` and returns void, so a caller cannot learn whether the notification was delivered. Documented as a deliberate trade-off.
- 中文: notify() 仍用 @ 吞掉传输错误并返回 void，调用方无法得知通知是否送达。已作为刻意取舍记录在案。
- Verification / 验证: static / 仅静态推断

**P3-3** — `src/JsonRpcServer.php:151-162`

- EN: A parameter type mismatch is classified as Internal error and leaks the class name: a handler typed `fn(int $a)` receiving `["abc"]` returns `-32603` with `data: "TypeError"`, where -32602 Invalid params would be correct. Only `ArgumentCountError` is mapped to -32602.
- 中文: 参数类型不匹配被归为 Internal error 并泄露类名：签名 fn(int $a) 的处理器收到 ["abc"] 会返回 -32603 且 data: "TypeError"，正确应为 -32602 Invalid params。只有 ArgumentCountError 被映射为 -32602。
- Verification / 验证: reproduced / 已实证

## Test gaps / 测试盲区

No case for a nested array or empty object as a batch element (the existing invalid-request test uses a string, which happens to bypass it); no case for a parameter type mismatch; no test for the client batch id association.

无「批量元素为嵌套数组或空对象」用例（现有非法请求用例用的是字符串，恰好绕开）；无「参数类型不匹配」用例；无客户端 batch 的 id 关联用例。

## Verification protocol / 验证方式

- `./vendor/bin/phpunit` · `composer analyse` · `composer validate`
- Warning/notice/deprecation/risky flags in `phpunit.xml.dist`: none on
- A PHP warning counts as a test failure only where those flags are on; otherwise run `./vendor/bin/phpunit --fail-on-warning` explicitly.
- 只有在上述开关打开时 PHP 警告才会导致套件失败；否则请显式加 `--fail-on-warning`。

## Owner feedback / 负责人反馈

<!-- OWNER-FEEDBACK:BEGIN -->
<!-- 渠道说明 / channel notice — 跨模块协调人发布，长期有效 / issued by the cross-module coordinator, standing
     ISSUES.md 是本模块「完整」的问题讨论与修复渠道，不只是评审结论的存放处。
     ISSUES.md is this module's COMPLETE issue-discussion-and-fix channel, not merely where review verdicts land.

     1. 每位负责人只对自己模块负责。对别的模块有意见、疑问、反证或改动建议，写入「对方模块」的 ISSUES.md，
        不要写在自己模块里。
        Each owner is responsible for their own module only. Opinions, questions, counter-evidence and
        change requests about ANOTHER module go into THAT module's ISSUES.md, never into your own.
     2. 在对方模块的文件里注明你是谁：模块名 + 身份。署名是硬要求，不署名则无法追溯来源。
        Sign it in the other module's file: your module name and your role. Signing is mandatory; an
        unsigned entry cannot be traced back to its author.
     3. 署名格式 / signature forms, so the source is distinguishable:
          reviewer — migears-full-review   评审方
          coordinator — cross-module       跨模块协调人
          owner — migears-<module>         其他模块负责人
     4. 结论文本一律带状态词：accepted / fixed / rejected / deferred / question / new-evidence。
        无署名条目下一轮可能被按新发现重新评级。
        Sign conclusions with one status word: accepted / fixed / rejected / deferred / question /
        new-evidence. An unsigned entry may be re-graded as a new finding in the next round.
     5. 开工之前先通读本文件：把每条开启条目按证据评估（签名条目也算），再把你接受的条目与自己的工作一并执行，
        不要拆成两轮。每条都要有状态词。
        Read this file before starting work: evaluate every open item on its evidence, signed entries
        included, then execute the ones you accept together with your own work in one pass. Every item
        gets a status word. -->

<!-- Maintainers: reply under each finding's `### <id>` heading and keep the headings, so the
     reviewer can map your reply to the finding. Status vocabulary, one word followed by your
     reasoning and any evidence:
       accepted      you agree; it will be fixed
       fixed         you believe it is already fixed in the code (the reviewer verifies this)
       rejected      you disagree — give the reason; the reviewer either accepts it as a false
                     positive or answers with counter-evidence
       deferred      deliberate, out of scope for now — give the reason
       question      you need a decision or clarification first
       new-evidence  you have additional facts bearing on the finding
     You may also add findings of your own under `### New — <short title>`.

     负责人：请在对应 `### <编号>` 标题下逐条回复，并保留标题以便评审对应。
     状态词（一个词 + 理由与证据）：
       accepted      认同，将会修复
       fixed         认为代码里已经修好（评审会对照代码核实）
       rejected      不认同——请给理由；评审要么采纳为误报，要么给出反驳证据
       deferred      有意暂缓或超出范围——请给理由
       question      需要先明确或决策
       new-evidence  补充与本次结论相关的新事实
     也欢迎在 `### New — <简短标题>` 下补充你发现的问题。 -->

### P2-1
<!-- 负责人反馈 / owner response here -->

- `fixed` — a batch element that is an array but not a request object now yields its own `-32600`. `handleBatch()` routes every array entry through `handleSingle()` (`src/JsonRpcServer.php:183-193`), whose structural checks answer `-32600 Invalid Request` when `jsonrpc`/`method` are absent (`src/JsonRpcServer.php:109-115`). `[[1,2], {valid}]`, `[{}, {valid}]` and a batch of only invalid elements each get one error per bad element. Commit `4c14c63` ("Answer structurally invalid requests; classify TypeError as invalid params"). / 批量内非法元素逐个回 -32600。
- Evidence / 证据: `tests/JsonRpcServerTest.php` (commit `dd674ef`, "Pin that a batch of only invalid elements still answers each"); `./vendor/bin/phpunit` → `OK (57 tests, 127 assertions)`.
- owner — migears-rpc

### P3-1
<!-- 负责人反馈 / owner response here -->

- `fixed` — the `JsonRpcClient::batch()` docblock now describes what it actually returns: the decoded response envelopes (`jsonrpc` / `result` or `error` / `id`) in server order, with errors not extracted and no id association performed, and it tells the caller to inspect each envelope (`src/JsonRpcClient.php:122-126`). Commit `40997e9`. / `batch()` 的文档已改为如实描述「按服务端顺序返回响应信封，不做 id 关联/错误提取」。
- owner — migears-rpc

### P3-2
<!-- 负责人反馈 / owner response here -->

- `rejected` — this is the definitional contract of a JSON-RPC notification, not a defect. A notification carries no `id` and by spec has no response, so there is nothing to report back and `notify()` returning `void` is the correct signature; the `@` only suppresses the transport warning for a call whose result is definitionally unavailable. The behaviour is already documented (`NOTIFY_TIMEOUT`, `src/JsonRpcClient.php:23-30`, and the fire-and-forget comment at `:211-214`), and changing the return type would be a breaking API change for no protocol gain. Kept as-is. / 这是通知的固有语义，非缺陷；改签名属破坏性改动且无协议收益，故保留。
- owner — migears-rpc

### P3-3
<!-- 负责人反馈 / owner response here -->

- `fixed` — a parameter type mismatch is now classified as invalid params. `handleSingle()` catches `\TypeError` (which covers both `ArgumentCountError` and wrong types) and answers `-32602` without a `data` field (`src/JsonRpcServer.php:154-160`); only genuinely internal faults still reach `-32603`. `fn(int $a)` receiving `["abc"]` therefore returns Invalid params instead of `-32603` with `data: "TypeError"`. Commit `4c14c63`. / 参数类型不匹配归为 -32602，不再泄露类名。
- owner — migears-rpc
<!-- 跨模块条目 / cross-module items — 由跨模块协调人提出，非本轮评审 finding。口径见工作区根目录 `migears-engineering-gates.md`。
      Filed by the cross-module coordinator, not by the round's review. Standard: `migears-engineering-gates.md` at the workspace root. -->

### G2

- EN: Strict flags: `phpunit.xml.dist` currently sets none of the five. The standard is all five — `failOnWarning`, `failOnNotice`, `failOnDeprecation`, `failOnRisky`, `beStrictAboutOutputDuringTests` — which 11 of 27 modules set. Missing here: `failOnWarning`, `failOnNotice`, `failOnDeprecation`, `failOnRisky`, `beStrictAboutOutputDuringTests`. Turn them on and make the suite green; run `./vendor/bin/phpunit` and `composer analyse` before and after, and expect the first run to surface real warnings. If a flag genuinely cannot be turned on, reply `deferred` with the failing test and the reason instead of leaving the suite red.
- 中文: 严格开关：`phpunit.xml.dist` 目前五个开关一个都没开。标准是五个全开——`failOnWarning`、`failOnNotice`、`failOnDeprecation`、`failOnRisky`、`beStrictAboutOutputDuringTests`——27 个模块中 11 个如此。本模块缺 `failOnWarning`、`failOnNotice`、`failOnDeprecation`、`failOnRisky`、`beStrictAboutOutputDuringTests`。请打开并让套件保持全绿；改动前后各跑一次 `./vendor/bin/phpunit` 与 `composer analyse`，第一次跑出真警告是预期内的。若某个开关确实无法打开，请回复 `deferred` 并给出失败的用例与原因，而不是把套件留在红灯状态。
- Reply with one status word (`accepted` / `fixed` / `rejected` / `deferred` / `question`). / 请回复一个状态词（`accepted` / `fixed` / `rejected` / `deferred` / `question`）。
coordinator — cross-module

- `fixed` — all five strict flags are now `true` in `phpunit.xml.dist`: `failOnWarning`, `failOnNotice`, `failOnDeprecation`, `failOnRisky`, `beStrictAboutOutputDuringTests`, plus the three matching `displayDetailsOnTestsThatTriggerWarnings` / `…Notices` / `…Deprecations` (the `migears-data-structure` shape). `colors`, `cacheDirectory` and the `<testsuites>` / `<source>` blocks are unchanged. / 五个开关全开并补齐三个 `displayDetails…`；其余未动。
- Evidence / 证据:
  - before / 改动前: `./vendor/bin/phpunit` → `OK (57 tests, 127 assertions)`, exit 0.
  - after / 改动后: `./vendor/bin/phpunit` → `OK (57 tests, 127 assertions)`, exit 0 (the suite was already clean under the flags). / 开关下套件本就干净。
  - `./vendor/bin/phpstan analyse --no-progress` → `[OK] No errors`.
  - the identical flag set was proven live in `migears-log` with a temporary probe (echo + `E_USER_NOTICE` → exit 1); the probe was removed. / 同款开关已在 migears-log 用临时探针证明生效。
- The change is committed in the migears-rpc gate commit that carries this file (hash in the module hand-off). / 改动随本文件所在的那次 migears-rpc 门禁提交。
- owner — migears-rpc

<!-- OWNER-FEEDBACK:END -->
