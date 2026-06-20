# BEAR.AgUi マイルストーン（ドラフト）

`bear/tool-use` のエージェント出力を **AG-UI プロトコルへ変換するアダプターライブラリ**と、
その使い方を示す **example アプリ**を実装する。

- パッケージ本体は **フレームワーク非依存**（依存は `bear/tool-use` のみ、BEAR.Sunday には依存しない）。
- 設計根拠は [`docs/adr/0000-0006-ag-ui-support.md`](adr/0000-0006-ag-ui-support.md)（※ ADR は
  BEAR.Sunday アプリ + AgentCore デプロイまで含む広い構想。本ライブラリはそのうち **変換 + SSE 直列化**
  の部分のみを対象とする）。
- 構造と流れは [`docs/architecture.md`](architecture.md)。
- AG-UI プロトコルの実装用リファレンスは [`docs/reference/ag-ui-protocol.md`](reference/ag-ui-protocol.md)（検証済み）。
- ToolUse 側への要望は [`docs/feedback/tool-use-resume.md`](feedback/tool-use-resume.md)。

順序は「失敗が早く分かる順」。各 M は独立にグリーンで終われる単位。

---

## M0 — パッケージ土台：移植 + ToolUse 接続（ADR 0001/0002/0006）

> **詳細タスク**: [`tasks-m0.md`](tasks-m0.md)（ファイル/クラス/メソッド粒度、着手順）

poc の実証済み層を本体 `src/`・`tests/` へ移植し、実 `bear/tool-use` に接続して全グリーンにする。

- composer: `bear/tool-use`（PR #22 = `dev-codex/review-hardening`）を require、PHP は `^8.2` 据え置き
- poc の Events / SSE / Input / Adapter を `NaokiTsuchiya\BEARAgUi\` namespace で移植（1クラス1ファイル）
- 偽 `INTERRUPT` イベント削除 → `RunFinished` に `outcome` 追加
- AG-UI 実スキーマで各イベントのフィールドを確定（`RunFinished.outcome` / `RunError` / `ToolCallEnd` 有無）
- ツール対応づけを **FIFO キュー**に（D9）。複数/並行 tool_use を正しく start↔result 対応
- **ツール情報エンリッチ層（D10・Tier 2）**：`ToolCallRegistry` + `RecordingDispatcher` +
  `RecordingStreamingLlmClient`（ToolUse 注入点のデコレータ）で実 id / 引数 / 結果 content を横取りし、
  Adapter は `Generator<AgentEvent>` + `ToolCallRegistry` を受けて `TOOL_CALL_START`(早期·実 id) /
  `TOOL_CALL_ARGS`(input) / `TOOL_CALL_END` / `TOOL_CALL_RESULT`(content) を正しく生成
- `RunError` の例外メッセージ直流しを是正（汎用メッセージ + サーバログ）
- placeholder（`BEARAgUi.php` / `BEARAgUiTest.php`）削除
- テスト（D13）：**Fake を下位境界に下げ、実 `StreamingAgent` を回す**。`FakeStreamingLlmClient`
  （scripted `StreamEvent`）+ `FakeDispatcher` を注入し、実 runStream に本物の AgentEvent 列を生成させる。
  - (1) Adapter ユニット＝単純な `Generator<AgentEvent>` を直接流す（ToolUse 非依存）
  - (2) 契約/結合＝実 StreamingAgent + Fake LLM/Dispatcher + デコレータ + Adapter の全鎖
    （①テキスト②単一ツール③並行ツール④confirmation→interrupt⑤実行中エラー）
  - 遅延性＝poc `verify.php` RUN3 を移植し interleave を spy で順序検証

**DoD**: `composer tests`（mago + phpmd + phpunit）と `composer crc` がグリーン。

## M1 — ファサード `AgUiRunner` + 起動マッピング（ADR 0001/0004/0006）

入力境界からエージェント起動・SSE 配信までを 1 つのファサードにまとめる。

- `RunAgentInput::lastUserMessage()` → `runStream($msg, $options)`
- `declaredToolNames()` → `AgentOptions::withTools($names)`
- ALPS ツール制御は PR #22 の `AlpsToolPolicyInputProcessor::safeOnly()` 等を `AgentOptions` に渡すだけ
- `confirmation_required` → `RunFinished{outcome:interrupt}` で run 終了（**v1 は resume 未対応・前方互換**）
- **`InstrumentedAgentFactory`（IF）+ `DefaultInstrumentedAgentFactory`（既定実装）** を追加。`StreamingAgent`
  は final で依存後付け不可のため、AgUiRunner は「組み上げ済み agent」ではなく **factory を受ける**
  （[architecture](architecture.md) §4-5）。factory が `RecordingStreamingLlmClient`/`RecordingDispatcher` を
  agent の依存に配線する
- `AgUiRunner`：`InstrumentedAgentFactory` + `SseEncoder` + `?LoggerInterface` + 既定 processors を保持し、
  `run(RunAgentInput, SseSinkInterface)` を提供。run ごとに `ToolCallRegistry` を生成し、recorder として
  factory へ、view として Adapter へ**同一実体を二面で**渡す

**DoD**: `DefaultInstrumentedAgentFactory` に Fake LLM/Dispatcher（D13）を注入し、ファサードの統合テストがグリーン。

## M2 — example①：素 PHP HTTP サーバ + 結合テスト（ADR 0005）

`poc/server.php` を発展させた**フレームワーク非依存の最小サーバ**を `example/` に置き、結合テスト対象にする。

- `/invocations`（SSE）+ `/ping`、`PhpSapiSseSink` で配信
- `DefaultInstrumentedAgentFactory` + Fake LLM/Dispatcher（D13）で LLM 不要・決定論的に回す
- サーバを起動して SSE を end-to-end 検証（フレーム順・逐次性）

**DoD**: 結合テストが CI で決定論的にグリーン。

## M3 — example②：BEAR.Sunday ショーケースアプリ

本番想定の **BEAR.Sunday アプリ**として作り込み、BEAR での組み方を示す。

- `/invocations` を薄いハンドラ（or リソース）で結線し `AgUiRunner` を駆動、`/ping`
- DI Module：スコープを正しく束縛（[architecture](architecture.md) §2）。**app 単一**＝factory/encoder/logger、
  **per-request**＝`SseSinkInterface`、**per-run**＝registry/agent/adapter。`InstrumentedAgentFactory` は
  アプリの本物 LLM/Dispatcher を包む実装を束縛
- ストリーミングは通常レンダラを通さず `SseResponder` で配信（body=値 の前提と衝突させない）

**DoD**: 実 BEAR アプリとして起動し `/invocations` が SSE 逐次返す。

---

## スコープ外（ライブラリの責務ではない・アプリ/デプロイの関心事）

- AgentCore デプロイ・ARM64 コンテナ・OAuth/SigV4 認証（ADR 0005）
- state-as-resource / `STATE_SNAPSHOT` / `STATE_DELTA`（ADR 0003）— アプリ側で実装
- ALPS ツール供給（ADR 0004）— `bear/tool-use` 本体が担う
- **本物の interrupt / resume**（ToolUse 側に resume 再入 API が入り次第。`feedback/tool-use-resume.md`）
- `TOOL_CALL_ARGS` 引数ストリーミング / 複数ツールの `toolCallId` 対応づけ（ToolUse feedback 反映待ち）

## 依存とリスク

- **M0 → M1 → (M2 ∥ M3)**。M2/M3 は M1 の上に並行可。
- `bear/tool-use` はオープン PR ブランチ pin のため揺れる。タグが切られ次第差し替え。
- AG-UI イベントのフィールドは poc を鵜呑みにせず実スキーマで確定（M0 で照合）。
