# 並列ツール実行 実装タスク（詳細）

複数ツールの**非同期並列実行**を Swoole で**このリポジトリ内に**実装する。`OptionAwareStreamingAgentInterface` を実装する
自前 `ParallelStreamingAgent` を `src/Runtime/` に置き（ライブラリ第一級機能）、M3 の BEAR ショーケースで実証する。

根拠は [`decisions.md`](decisions.md) **D29**（先行スパイク **S-c** で技術検証済み）。末尾 `(Dxx)` は根拠。`⚠️` は実装時に実物で確認する点。

**前提（スパイク S-c で grounding 済み）**:

- seam は `DispatcherInterface` ではなく**エージェントループ内**。コルーチン対応 Dispatcher 注入だけでは並列化しない（実測 403ms＝逐次）。
- `StreamingAgent` は `final`＝サブクラス不可。よって**ループだけ自前再実装**し、他部品（`StreamContentAccumulator` /
  `StreamIterationState` / `ToolList` / `Message` / `LlmRequest` / `AgentOptions` / `AgentEvent` / `PendingToolCall`）は**全 public で再利用**。
- 並列ポリシー＝confirm なしは `WaitGroup` で並列・confirm 付きは直列（`yield` + `send(bool)`）。S-c part3 で実 Fake LLM 3ツールを 202ms で実証。
- Swoole は `require-dev` + `suggest`。本体 `require` は `psr/log` + `bear/tool-use` 据え置き（D7）。既定は逐次 `StreamingAgent`、並列は opt-in。

---

## 設計サマリ

```
src/Runtime/
├── ParallelStreamingAgent.php        OptionAwareStreamingAgentInterface 実装。reason/act ループ自前・
│                                     dispatch を confirm 有無で分岐（plain=並列 / confirm=直列）
└── ParallelStreamingAgentFactory.php InstrumentedAgentFactory 実装。per-run で recording デコレータを配線し
                                      ParallelStreamingAgent を組む（M1 の StreamingAgentFactory と差し替え可能）
src/Dispatch/（改修）
└── ToolCallRegistry                  FIFO → tool id キーへ。並列 dispatch でコルーチン安全に（D9 改訂）
```

新規依存（`require-dev`）：`swoole`（ext）。`composer.json` の `suggest` にも明記。本体 `require` は不変。

---

## T1. 前提改修：recording 層を id キー化＋コルーチン安全に（D9 改訂・最優先）

並列の土台。FIFO 前提を壊してから並列ループを載せる。

- [ ] `ToolCallRegistry` の対応づけを **FIFO → `toolCallId` キー**に変更（start↔result を id で突き合わせ）
- [ ] `RecordingDispatcher` / `RecordingStreamingLlmClient` を**同時呼び出し下で安全**に（共有配列への追記をアトミックに・参照渡し競合を排除）
- [ ] ⚠️ Adapter（`Generator<AgentEvent>` + registry の二面消費）が **並列で生成された TOOL_CALL_* を id で正しく束ねる**ことを確認
- [ ] テスト：同一ターンで2ツールを並列 dispatch し、`TOOL_CALL_START/ARGS/END/RESULT` が**各 id で正しくペア**になる（順不同許容）

## T2. `ParallelStreamingAgent`（`src/Runtime/`、D29）

S-c part3 の実証コードを本実装に昇格。**ループ以外は bear/tool-use の部品を再利用**。

- [ ] `final class ParallelStreamingAgent implements OptionAwareStreamingAgentInterface`
  - [ ] ctor は `StreamingAgent` と同形（`StreamingLlmClientInterface` / `DispatcherInterface` / `list<Tool>` / systemPrompt / maxIterations）
  - [ ] `runStream(string, ?AgentOptions): Generator`：reason/act ループ。stream 消費は `StreamContentAccumulator`、状態は `StreamIterationState`、
    メッセージは `Message::user/assistant/toolResults` を再利用（**逐次版と同じ AgentEvent 列**を保証）
  - [ ] `reset()`：`$this->messages = []`
- [ ] **dispatch fan-out（唯一の新規ロジック）**：pending を2パスで処理
  - [ ] pass1（直列）：未知ツール → `ToolResult::error`、confirm 付き → `yield CONFIRMATION_REQUIRED` → `send(bool)` で承認/取消、plain は index 退避
  - [ ] pass2（並列）：plain を `Swoole\Coroutine\WaitGroup` で fan-out、`$wg->wait()` で join。結果は index で順序保持（`ksort`）
  - [ ] pass3：`AgentEvent::toolResult($name)` を**元の順序で** yield（イベントは名前のみ・結果本体は registry/messages 経由）
  - [ ] dispatch は try/catch で `ToolResult::error` に落とす（逐次版の挙動踏襲）
- [ ] ⚠️ confirm 付きツールの**実行タイミング**（並列波の前に直列実行する／承認済みを並列波へ合流させる）を design で固定し README に明記
- [ ] テスト（Swoole コルーチン文脈で実行）：
  - [ ] 1ターン N plain ツール → wall-clock ≈ 1×latency（並列）、`tool_start`/`tool_result` が N 個・順序保持
  - [ ] confirm 付き混在 → plain 並列＋confirm 直列、`send(false)` で `ToolResult::cancelled`
  - [ ] 逐次版 `StreamingAgent` と**同一の AgentEvent 列**（confirm 無しケース）になる回帰テスト

## T3. `ParallelStreamingAgentFactory`（`src/Runtime/`、M1 seam 適合）

M1 の `InstrumentedAgentFactory` IF にそのまま差さる並列版 factory。

- [ ] `final class ParallelStreamingAgentFactory implements InstrumentedAgentFactory`
  - [ ] `newInstance(...)`：per-run で `RecordingStreamingLlmClient` / `RecordingDispatcher`（T1 改修版）を配線し `ParallelStreamingAgent` を返す
  - [ ] M1 の `DefaultInstrumentedAgentFactory` と**置換可能**（`AgUiRunner` は無改造で受ける）
- [ ] テスト：factory が `ParallelStreamingAgent` を組み、registry を recorder/view の二面で渡すこと（Fake LLM 注入）

## T4. ランタイム配線 ＋ 依存（D29）

- [ ] `composer.json`：`require-dev` に `swoole`（ext）追加、`suggest` に `ext-swoole: 並列ツール実行（ParallelStreamingAgent）に必要` を明記
- [ ] ⚠️ 本体 `require` は `psr/log` + `bear/tool-use` のまま（`composer crc` で本体が swoole に依存しないことを確認＝D7 不変条件）
- [ ] `AgUiRunner` は無改造（factory 差し替えのみで並列化）。逐次が既定・並列は factory 選択で opt-in

## T5. M3 への組み込み（example/bear、D29/D27）

- [ ] M3 の `AgentFactoryProvider` を `ParallelStreamingAgentFactory` を返すよう構成（[`tasks-m3.md`](tasks-m3.md) T4 を更新）
- [ ] **SSE 配送を Swoole ランタイムに**：`SwooleSseSink implements SseSinkInterface`（`$response->write()` + `flush()`）を追加し、
  M3 の `Invocations::transfer()` 分岐（[`tasks-m3.md`](tasks-m3.md) T7）から呼ぶ。`PhpSapiSseSink` は非 Swoole 経路用に残置
- [ ] ⚠️ **B（BEAR.Resource コルーチン安全性）を本番リソースで再検証**：スパイク **S-d で基本成立は確認済み**（単純リソース2本を
  `enableCoroutine()` 下で並列 dispatch し overlap・クラッシュ無し）。残るは**複雑な DI シングルトン/共有可変状態のレース**を本番の
  4 ツールリソースで確認。NG なら per-coroutine スコープ or リソース複製の方針を決める
- [ ] 3ツールを「2つ並列実行＋1つ confirm」で1 run に見せる canned/Fake を用意し、resource-as-tool の並列を実証

## T6. テスト基盤（Swoole 文脈）

- [ ] PHPUnit を Swoole コルーチン内で回すヘルパ（`Swoole\Coroutine\run()` ラッパ）を `tests/Support/` に追加
- [ ] ⚠️ 並列テストの**決定論性**：latency は固定 sleep ではなく順序検証中心に（時計依存アサートを避ける・D22 の精神）
- [ ] 3連続グリーン（flake 無し）を DoD に含める

## T7. ドキュメント

- [ ] `decisions.md` D29 をリンク（本ファイル）
- [ ] [`milestones.md`](milestones.md) スコープ外の更新：「複数ツールの `toolCallId` 対応づけ」と「Swoole 別ランタイム」を
  **in-scope（D29）**へ移動。`milestones.md` M3 に Swoole 前提と並列ツールを反映
- [ ] README/example：並列ポリシー（plain 並列・confirm 直列）、Swoole 必須、opt-in（既定は逐次）の明記

## T8. ガード（要承認あり）

- [ ] ⚠️ `mago` / `phpmd` で並列ループの複雑度しきい値に当たる可能性。**ルール変更はユーザー承認案件**（[linter rules は無断で触らない]）。
  抵触したらコード側で吸収するか、要否を確認してから

---

## コミット粒度

作業ブランチ。**各コミット green**、依存順。メッセージは命令形・プレフィックス無し。**S-c スパイクは破棄**（コミットしない）。

| # | コミットメッセージ（案） | 含むタスク | green |
| --- | --- | --- | --- |
| C1 | `Key tool call registry by id for concurrent dispatch` | T1 | phpunit |
| C2 | `Add ParallelStreamingAgent for concurrent tool execution` | T2 | phpunit（Swoole 文脈） |
| C3 | `Add parallel instrumented agent factory` | T3 + T4 | `composer tests` |
| C4 | `Wire Swoole SSE sink and parallel agent into BEAR example` | T5 + T6 | `composer tests` |
| C5 | `Document parallel tool execution and update scope` | T7 | — |

依存順：**C1 → C2 → C3 → C4 → C5**。T8（guard 変更）は承認後に別コミット。

---

## DoD

- [ ] `composer tests`（mago + phpmd + phpunit unit/integration）グリーン
- [ ] `composer crc` グリーン（本体 `require` が swoole 非依存＝D7 維持）
- [ ] 並列：1ターン N plain ツールが実時間で並列化（wall-clock ≈ 1×latency）を実測
- [ ] 回帰：confirm 無しで逐次版 `StreamingAgent` と同一 AgentEvent 列
- [ ] M3 手動 smoke：Swoole アプリで `/invocations` が SSE 逐次返し、2ツール並列＋1 confirm が1 run で観測できる
- [ ] B（BEAR.Resource コルーチン安全性）検証済み

---

## スコープ外

- 上流 `bear/tool-use` への `dispatchBatch` seam 追加（将来 PR・本タスクは自前ループで先行）
- ツール間 arguments interleave のストリーミング並列（bear `StreamContentAccumulator` 単数 `currentToolId` 制約・D19）
- subagent / `AgentPool` による並列（D27 で不採用据え置き）
- 非 Swoole ランタイム（ReactPHP/Fiber 等）での並列実装（Swoole に一本化）
