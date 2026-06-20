# M0 実装タスク（詳細）

[`decisions.md`](decisions.md) D1〜D13 を実装タスクに落とし込んだもの。上から着手順。
各タスクの末尾 `(Dxx)` は根拠の決定。`⚠️` は実装時に実物で確認する点。
AG-UI 側の正確な仕様は [`reference/ag-ui-protocol.md`](reference/ag-ui-protocol.md)（検証済み）を参照。

パッケージ namespace = `NaokiTsuchiya\BEARAgUi\`（D2）、1クラス1ファイル、PHP `^8.2`（D3）。

---

## T0. パッケージ/依存セットアップ

- [ ] `composer.json` に require 追加：`"bear/tool-use": "dev-codex/review-hardening"`、`"psr/log": "^3.0"`（D11 の logger 用）(D3/D11)
- [ ] `minimum-stability: "dev"` + `prefer-stable: true`（dev ブランチ pin のため）(D3)
- [ ] `composer update` 実行 → ⚠️ `bear/tool-use` が実際に解決・install できるか、依存（bear/resource, ray/di 等）が重すぎないか確認 (D3)
- [ ] placeholder 削除：`src/BEARAgUi.php` / `tests/BEARAgUiTest.php` (D6 周辺)
- [ ] `git rm` 後 autoload 確認（`composer dump-autoload`）

## T1. AG-UI イベント値オブジェクト（`src/Event/`）

実スキーマ準拠（D8）。すべて `AgUiEventInterface`（`type(): string` + `JsonSerializable`）を実装。
wire `type` は SCREAMING_SNAKE。`timestamp`/`rawEvent` は出さない（D8）。

- [ ] `AgUiEventInterface.php`（poc から移植・namespace 変更のみ）
- [ ] `RunStarted.php` … `{type, threadId, runId}`
- [ ] `RunFinished.php` … `{type, threadId, runId, outcome}`。`outcome` を追加 (D8/D4)
  - [ ] `RunOutcome` 表現：`success()` = `{type:"success"}` / `interrupt(array $interrupts)` = `{type:"interrupt", interrupts:[…]}`。値オブジェクト or `RunFinished::success()/::interrupt()` ファクトリ
  - [ ] interrupt エントリ＝`{id, reason(必須), message?, toolCallId?, responseSchema?, expiresAt?, metadata?}`（[protocol ref](reference/ag-ui-protocol.md) で確定済み）
- [ ] `RunError.php` … `{type, message}` + `code`（optional）。順序は `message` 必須・`code` 任意 (D8/D11)
- [ ] `TextMessageStart.php` … `{type, messageId, role}`（role 既定 `"assistant"`）
- [ ] `TextMessageContent.php` … `{type, messageId, delta}`
- [ ] `TextMessageEnd.php` … `{type, messageId}`
- [ ] `ToolCallStart.php` … `{type, toolCallId, toolCallName, parentMessageId?}`
- [ ] `ToolCallArgs.php` … `{type, toolCallId, delta}`
- [ ] `ToolCallEnd.php` **新規** … `{type, toolCallId}`。Result の前に必須 (D8)
- [ ] `ToolCallResult.php` … `{type, messageId, toolCallId, content, role?}`。**`messageId` 必須化** (D8)
- [ ] 偽 `Interrupt`（type `"INTERRUPT"`）は**移植しない**（削除）(D6)
- [ ] 各 `jsonSerialize()` の出力をユニットテスト（T6-1）

## T2. SSE レイヤー（`src/Sse/`、poc からほぼそのまま移植）

- [ ] `SseEncoder.php` … `encode(AgUiEventInterface): string`（`data: {json}\n\n`、純粋）
- [ ] `SseSinkInterface.php` … `open(int)` / `write(string)` / `close()`
- [ ] `PhpSapiSseSink.php` … echo+flush、`X-Accel-Buffering: no` 等
- [ ] `SseResponder.php` … `respond(iterable<AgUiEventInterface>, int $status=200)`：1個ずつ pull→encode→write（畳まない）

## T3. 入力境界（`src/Input/RunAgentInput.php`、poc 移植）

- [ ] `fromJson(string): self`（threadId/runId/messages 必須検証、不正は `InvalidArgumentException` → 上流で HTTP 400）(D-ADR0001)
- [ ] `lastUserMessage(): string`、`declaredToolNames(): list<string>`
- [ ] ⚠️ `state`/`forwardedProps`/`resume` フィールドの受けは緩く（v1 は resume を読まないが構造は受容）(D4)

## T4. エンリッチ層（`src/ToolUse/`、D10・Tier 2）★ToolUse 無改造の代替実装

ToolUse の公開注入点をデコレートし、不足データ（実 id / 引数 / 結果 content）を横取りする。

- [ ] `ToolCallRecorder`（書き手 IF）/ `ToolCallView`（読み手 IF）を定義。`ToolCallRegistry` が両方を実装（ISP）
  - [ ] `recordStart(string $id, string $name): void`（開始順に enqueue）
  - [ ] `appendInput(string $id, string $delta): void`（入力 JSON 断片を蓄積）
  - [ ] `recordResult(ToolCall $call, ToolResult $result): void`（id→{content,isError,input}）
  - [ ] `nextStarted(): ?StartedToolCall`（FIFO で {id,name}）/ `resultFor(string $id): ?ToolCallOutcome`
- [ ] `RecordingStreamingLlmClient.php` implements `StreamingLlmClientInterface`（`ToolCallRecorder` 依存）
  - [ ] シグネチャ確定：`chatStream(string $system, array $messages, array $tools): Generator<int,StreamEvent,mixed,void>`
  - [ ] `chatStream()` を**透過 yield**しつつ `StreamEvent` を観測：`TOOL_USE_START`(data:id,name)→`recordStart`、`TOOL_USE_DELTA`(data:input)→`appendInput`（直前 START の id に紐付け）。定数=`StreamEvent::{TEXT_DELTA,TOOL_USE_START,TOOL_USE_DELTA,CONTENT_BLOCK_STOP,MESSAGE_STOP}`
- [ ] `RecordingDispatcher.php` implements `DispatcherInterface`
  - [ ] `dispatch(ToolCall): ToolResult` を委譲し、戻り値で `recordResult($call, $result)`（id+input+content+isError）
- [ ] ⚠️ 未登録ツール（`! $toolList->has`）は dispatch を通らない → registry に result 無し。Adapter 側でフォールバック（content 空・isError）(D10)
- [ ] 撤去条件をクラス docblock に明記：ToolUse が `tool_start`/`tool_result` をエンリッチしたら本層削除（→ feedback）

## T5. Adapter（`src/Adapter/AgUiAdapter.php`、書き直し）

入力：`Generator<AgentEvent>`（タイムライン）＋ `ToolCallRegistry`（不足データ）＋ `?LoggerInterface`。
出力：`Generator<AgUiEventInterface>`。

- [ ] ライフサイクル：先頭 `RUN_STARTED`、正常終了で `closeOpenMessage()` → `RUN_FINISHED{outcome:success}` (D8)
- [ ] テキスト境界：`text_delta` で `ensureOpenMessage()`（無ければ `TEXT_MESSAGE_START`）→ `TEXT_MESSAGE_CONTENT`。非テキスト/終了で `TEXT_MESSAGE_END`（poc 流用）
- [ ] **tool_start**：`closeOpenMessage()` → `registry.nextStarted()` で実 id/name 取得 → `TOOL_CALL_START(id,name)`（早期発火）→ id を内部 FIFO `awaitingResult` に push (D10 Tier2)
- [ ] **tool_result**：`awaitingResult` から id を dequeue → `registry.resultFor(id)` で input/content 取得 → `TOOL_CALL_ARGS(id, input)` → `TOOL_CALL_END(id)` → `TOOL_CALL_RESULT(messageId採番, id, content)`。registry 欠落時はフォールバック (D9/D10/D8)
- [ ] **confirmation_required**：`closeOpenMessage()` → `RUN_FINISHED{outcome:interrupt, interrupts:[…]}` を yield して **run 終了**（`send()` しない＝ツール非実行）(D4)
  - [ ] interrupt エントリは confirmation データ（toolName/toolId/input/message）から構成
- [ ] **error**：`closeOpenMessage()` → logger に実例外、`RUN_ERROR(code:"AGENT_ERROR", message:汎用文)` (D11)
- [ ] **completed**：`closeOpenMessage()` のみ。`fullText` 破棄（D12）。`RUN_FINISHED` は run() 末尾で1回
- [ ] try/catch：実行中例外も logger → `RUN_ERROR`（HTTP は 200 のまま）(D11)
- [ ] `lastToolCallId` 単一スロットを撤去し FIFO 化 (D9)

## T6. テスト（`tests/`、D13）

- [ ] **T6-1 Adapter ユニット**：単純な `Generator<AgentEvent>` を直接流す（ToolUse 非依存）。境界生成・interrupt・error の写像
- [ ] **T6-2 各層ユニット**：`SseEncoder`（フレーム形）、`RunAgentInput`（検証・例外）、各イベント `jsonSerialize`
- [ ] **T6-3 契約/結合**：`tests/Fake/FakeStreamingLlmClient`（scripted `StreamEvent`）+ `tests/Fake/FakeDispatcher`（scripted `ToolResult`）を注入し、**実 `StreamingAgent::runStream()`** を回す。デコレータ＋Adapter の全鎖で AG-UI 出力を検証 (D13)
  - [ ] シナリオ①テキストのみ ②単一ツール ③**並行ツール**（FIFO・実 id 対応 D9）④confirmation（→`RUN_FINISHED{interrupt}` D4）⑤実行中エラー（→`RUN_ERROR` D11）
  - [ ] 各 Fake の docblock に「emission 順の根拠＝実 `StreamContentAccumulator`/`dispatchPendingToolCalls`」を明記
- [ ] **T6-4 遅延性**：poc `verify.php` RUN3 を移植。生成 vs write の interleave を spy で順序検証

## T7. 仕上げ / DoD

- [ ] `composer tests`（mago format:check + lint + analyze + phpmd + phpunit）グリーン
- [ ] `composer crc`（composer-require-checker）グリーン（`bear/tool-use`/`psr/log` を正しく宣言）
- [ ] `mago.toml`/`phpmd.xml` 既存設定に適合（1クラス1ファイル等）

## T8. アーキテクチャ境界の強制（`mago guard`）

全モジュールが揃った後に、[architecture](architecture.md) §1/§8 の依存規則を機械的にロックする。

- [ ] `mago.toml` に `[guard]` を追加し、以下を encode：
  - `…\Event\*` / `…\Sse\*` は `BEAR\ToolUse\*` に依存禁止
  - `BEAR\ToolUse\*` への結合は `…\ToolUse\*` と `…\Adapter\*` のみ許可
  - 依存方向：`Runner → {Input, Adapter, ToolUse, Sse}`、`Adapter → Event`、`Sse → Event`（逆禁止）
- [ ] `composer sa` に `@guard` を組込み（現状 `sa`/`tests` から外れているため CI ゲート化）
- [ ] ⚠️ `mago guard` の `[guard]` 設定スキーマを mago docs / `mago.toml` 冒頭の schema URL で確認してから書く

---

## コミット粒度

作業ブランチ（例 `feature/m0-adapter`）で進める。**各コミットは tree を green に保つ**
（`composer tests` が通る＝小さく・レビュー可能・依存順）。メッセージは既存履歴の流儀（命令形・プレフィックス無し）。

| # | コミットメッセージ（案） | 含むタスク | green 条件 |
| --- | --- | --- | --- |
| C1 | `Set up package dependencies and remove scaffolding` | T0（require `bear/tool-use`/`psr/log`、PHP `^8.2`、`minimum-stability`/`prefer-stable`、`composer update`、placeholder 削除） | `composer install` 成功・phpunit 通過 |
| C2 | `Add AG-UI event value objects` | T1 ＋ 各イベント `jsonSerialize` のユニット（T6-2 一部） | phpunit |
| C3 | `Add SSE encoding and transport layer` | T2 ＋ `SseEncoder` フレーム形ユニット（T6-2 一部） | phpunit |
| C4 | `Add RunAgentInput input boundary` | T3 ＋ 検証・例外・`lastUserMessage`/`declaredToolNames` ユニット（T6-2 一部） | phpunit |
| C5 | `Add ToolUse enrichment layer (registry and recording decorators)` | T4（`ToolCallRecorder`/`ToolCallView` 分離、`ToolCallRegistry`、`RecordingDispatcher`、`RecordingStreamingLlmClient`）＋ デコレータの記録ユニット | phpunit |
| C6 | `Rewrite AgUiAdapter with enrichment and AG-UI conformance` | T5 ＋ T6-1（`Generator<AgentEvent>` を直接流す Adapter ユニット：境界・interrupt・error・FIFO） | phpunit |
| C7 | `Add contract tests driving real StreamingAgent` | T6-3（`FakeStreamingLlmClient`+`FakeDispatcher`→実 `StreamingAgent`→デコレータ→Adapter、①〜⑤）＋ T6-4（遅延性） | phpunit 全シナリオ |
| C8 | `Pass static analysis and require-checker` | T7（`composer tests`＝mago format/lint/analyze + phpmd、`composer crc`、設定適合）※差分が出た分のみ | `composer tests` + `composer crc` |
| C9 | `Enforce module boundaries with mago guard` | T8（`[guard]` に依存規則を encode、`composer sa` へ `@guard` 組込み） | `mago guard` + `composer sa` |

依存順：**C1 → C2 → C3 → C4 → C5 → C6 → C7（→ C8 → C9）**。C3/C4 は C2 後なら順不同。
C5 は C1（`bear/tool-use` install）必須。C6 は C2（Event）＋ C5（`ToolCallView`）必須。
**C9 は全 src モジュール（C2〜C6）が揃った後**（境界が物理的に存在してから guard を効かせる）。

> 原則：T7（SA/crc グリーン）は**各コミットで満たす**のが理想。C8 は設定調整が必要になった場合の受け皿で、
> 不要なら省略。`mago format` 由来の整形差分は各コミットに含めて、整形専用コミットを作らない。

---

## 実装時に確定する未決の小項目（⚠️ 集約）

- 未登録ツール時の Adapter フォールバック表現（content/isError）

> 解決済み: interrupt エントリのスキーマ・wire `type`・各イベントフィールド・`RunFinished.outcome`
> （→ [protocol ref](reference/ag-ui-protocol.md)）／`chatStream()` シグネチャ・`StreamEvent` 定数・
> `DispatcherInterface` シグネチャ（→ ToolUse 実物確認済み、[architecture](architecture.md) §4-6）。

## 依存ファイル構成（最終形）

```
src/
  Event/  AgUiEventInterface, RunStarted, RunFinished(+RunOutcome), RunError,
          TextMessageStart/Content/End, ToolCallStart/Args/End/Result
  Sse/    SseEncoder, SseSinkInterface, PhpSapiSseSink, SseResponder
  Input/  RunAgentInput
  ToolUse/ ToolCallRegistry, RecordingDispatcher, RecordingStreamingLlmClient
  Adapter/ AgUiAdapter
tests/
  Unit/ … Fake/ FakeStreamingLlmClient, FakeDispatcher
```
