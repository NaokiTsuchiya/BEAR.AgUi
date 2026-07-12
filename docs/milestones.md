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

> **詳細タスク**: [`done/tasks-m0.md`](done/tasks-m0.md)（完了済み）

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

> **詳細タスク**: [`done/tasks-m1.md`](done/tasks-m1.md)（完了済み）。最終形は [`decisions.md`](decisions.md) D23・[`architecture.md`](architecture.md) §4-5 を正とする

入力境界からエージェント起動・SSE 配信までを 1 つのファサードにまとめる。

- `RunAgentInput::lastUserMessage()` → `runStream($msg, $options)`
- **マルチターン履歴の再構成（D15）**：`MessageHistoryMapper` が `messages[]`（最後の user を除く）→
  `list<ToolUse Message>` に**全再構成**（assistant の tool_use ↔ tool_result をペアで・連続 ToolMessage は
  grouping）。factory が `StreamingAgent.$messages` へ seed。`public $messages` 直叩きはカバーで、ToolUse へ
  「履歴 seed の正式 API」を feedback（→ [`feedback/tool-use-resume.md`](feedback/tool-use-resume.md)）
- ツール解決（D16・lenient 交差）：`enabledTools = declaredToolNames() ∩ factory.knownToolNames()`、空宣言は
  `null`（ALPS が統治）。未知名（client-side tool）は黙って除外＝エラーにしない（AG-UI 互換）
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

> **詳細タスク**: [`done/tasks-m2.md`](done/tasks-m2.md)（完了済み）。配置は D28（OpenAI 変換層は
> `example/shared/`）、入力検証の 400 エンベロープは D24（全 ParseError 集約）を正とする

`poc/server.php` を発展させた**フレームワーク非依存の最小サーバ**を `example/server/` に置き、`AgUiRunner` の
使用例とする。あわせて OpenAI 互換**スタブサーバ**を `example/stub-llm/` に同梱し、API キー無しで end-to-end を回す。

- `POST /invocations`（SSE）+ `GET /ping`、`PhpSapiSseSink` で配信
- LLM は `openai-php/client`（OpenAI 互換）で接続。**本物/スタブの切替は `OPENAI_BASE_URL` env のみ**（D18）
- スタブ LLM（D21）：`POST /v1/chat/completions` 単一エンドポイント、単一 canned 会話で tool ループ全周を再現
- **結合テストは HTTP を起こさず**、`AgUiRunner` をプロセス内で Fake LLM/Dispatcher（D13）+ recording sink で駆動（D22）
- HTTP/SSE の本番逐次配信は `php -S` では再現不能のため**手動 smoke**（時計依存の自動テストは置かない・D22）

**DoD**: `composer tests`（mago + phpmd + phpunit unit/integration）と `composer crc` がグリーン。結合テストが
CI で決定論的にグリーン。

## M3 — example②：BEAR.Sunday ショーケースアプリ（resource-as-tool ＋ ALPS）

> **詳細タスク**: [`done/tasks-m3.md`](done/tasks-m3.md)（完了済み。ファイル/クラス/メソッド粒度・先行スパイク S-a/S-b あり）。
> **複数ツールの Swoole 並列実行**（Swoole ランタイム前提・`ParallelStreamingAgent`）は D29 /
> [`done/tasks-parallel-tools.md`](done/tasks-parallel-tools.md)（完了済み。スパイク S-c 検証済み）

本番想定の **BEAR.Sunday アプリ**として作り込み、BEAR での組み方を示す。狙いは **単一エージェントが
BEAR リソースをツールとして呼び、ALPS でツールを統治しつつ、その出力を AG-UI イベントとして SSE 逐次配信する**
こと。M2（フレームワーク非依存・固定値ツール）には無い **resource-as-tool ＋ ALPS ＋ BEAR ネイティブ SSE** を
初めて実物で示す（ADR 0001/0004/0006）。

前提：M1 で `AgUiRunner::stream(RunAgentInput): iterable<AgUiEventInterface>` は完成済み（D23）。
**M3 は M1 を一切改造せず**、`stream()` を消費する側に徹する。

- **入口は ResourceObject**（薄いハンドラ案は却下＝中身が M2 と同化するため）。`Invocations`（`POST`）＋ `Ping`（`GET`）。
  `Invocations::onPost()` が入力を**検証**し、成功時のみ `stream()` の generator を body に置く
- **SSE 配送＝`Invocations::transfer()` オーバーライド**（D25・スパイク S-a/S-b で確定）。body 型で分岐し、
  generator は M1 既存の `SseResponder` + **`SwooleSseSink`**（D29）で逐次配信、配列（`/ping`・検証失敗の 400）は
  渡された標準 responder へ委譲（D23：SSE 化・I/O は host=配送層の関心事）。専用 `SseTransfer` クラスも
  `#[SseStream]` 属性も不要。`bear/streamer` を使う pull 配送は**スコープ外＝将来チャレンジ**
- **ツールは BEAR リソース**。`bear/tool-use` の `Dispatcher` / `ToolRegistry` / `ToolCollector` / `#[Tool]` を
  **そのまま使う**（自前ディスパッチャはゼロ）。`#[Tool]` を付けたリソースを書き、起動時に
  `ToolCollector->collect([uris])` で registry 充填＋ツール宣言を導出（宣言と実装がリソース 1 箇所に集約）
- **エージェントは並列構成**（D26 を D29 で改訂）：ライブラリ同梱の **`ParallelStreamingAgentFactory`**
  （`src/Runtime/`）に collect 済みツールと resource 駆動 `Dispatcher` を渡す。recording デコレータ（S5）は
  factory が per-run で配線。collect は起動時 1 回（Provider）。実 LLM は M2 の OpenAI 変換層を流用。
  **subagent（`AgentPool`）は使わない**（AG-UI の焦点をぼかすため）
- **ALPS を統治に使う**：リソースの ALPS プロファイルを書き、`bear/tool-use` 供給の `AlpsToolPolicyInputProcessor`
  （`safeOnly()` 等）と `AlpsContextInputProcessor` を `AgUiRunner.$inputProcessors` 経由で回す（ADR 0004 の
  「情報設計 ALPS → アフォーダンス → AG-UI」）。ALPS ツール供給自体は `bear/tool-use` 本体の責務（スコープ外・下記）
- **DI Module**：app 単一＝factory / encoder / logger / adapter / responder / 実 LLM・Dispatcher、registry/agent は
  `stream()` 内で per-run に `new`（DI スコープではない）。`ToolUseModule` ＋ BEAR `ResourceModule` を install

**先行スパイク（tasks-m3 執筆の前提条件）**:

- **S-a**：`$ro->body` に `Generator` を置いて BEAR 標準経路が壊さないか（[ADR0001 のメモ](adr/0000-0006-ag-ui-support.md)
  「専用 `StreamedResourceObject` 拡張が要るかも・要検証」）。NG なら拡張が必要＝M3 の骨格が変わる
- **S-b**：検証失敗が `#[SseStream]` を持たない error リソース経由で標準 400 になるか（属性スコープ方式の確認）

**DoD**: 実 BEAR アプリとして起動し `/invocations` が SSE 逐次返す（手動 smoke）。`composer tests` グリーン。

## M4 — example③：PHP CLI クライアント（AG-UI 消費側の参照実装）

> **詳細タスク**: [`tasks-m4.md`](tasks-m4.md)（ファイル/クラス/メソッド粒度）

M0〜M3 はすべて **AG-UI イベントを生成する側**（サーバ）だった。M4 は初めて **消費する側**（クライアント）
を実物で示す。対象は M3 の BEAR Swoole アプリ（`example/bear/`）。狙いは「本ライブラリが生成する SSE を、
何の変哲もない HTTP クライアントがどう読めば良いか」を実物のコードで示すこと（D30）。

- **ライブラリ非依存の素の HTTP+SSE クライアント**（D30）。`NaokiTsuchiya\BEARAgUi\` の型は一切使わない
  （`Event/` クラスも import しない）。実世界のクライアント（ブラウザ／別言語）は当然本ライブラリの PHP 型を
  知り得ないため、[`reference/ag-ui-protocol.md`](reference/ag-ui-protocol.md) のワイヤ仕様（JSON フィールド）
  だけを頼りにデコードする。これによりワイヤ契約が自己記述的であることも同時に実証する
- **配置**：`example/cli-client/`（`Example\CliClient\`、`autoload-dev` のみ・本体 `require`/`autoload` 汚染なし）。
  `example/shared/` は使わない（OpenAI 変換層はサーバ側の関心事）
- **チャット REPL**：`threadId` はセッション固定・`runId` は毎ターン新規生成。ユーザー入力を 1 行受け取るたびに
  `POST /invocations` で 1 run。引数で 1 発トリガー（スクリプト/手動 smoke 用）も可
- **クライアント側で会話履歴を保持**（D30）：AG-UI はサーバ側ステートレス（D15 提要）＝ client が正本。
  各 run で観測した SSE イベントから AG-UI `messages[]`（`UserMessage`/`AssistantMessage.toolCalls`/
  `ToolMessage`）を組み立て、次 run の `messages[]` として**全件再送**する。M1 `MessageHistoryMapper` の
  「サーバ側で messages[]→Message へ変換」の**逆方向**（client 側で events→messages[] へ集約）を実物で示す
- **SSE ストリーミング読み取り**：チャンク単位で即時レンダリング（バッファしない）。curl の
  `CURLOPT_WRITEFUNCTION` でチャンク到着ごとにコールバックし、`data: {json}\n\n` フレームを切り出して
  `json_decode`。サーバ側 `PhpSapiSseSink`/`SwooleSseSink` の逆側（読み取り版）にあたる新規コンポーネント
- **レンダリング**：`TEXT_MESSAGE_CONTENT.delta` を都度 echo（ストリーミング表示）、`TOOL_CALL_START/RESULT` は
  1 行サマリ（呼び出し名・結果）、`RUN_FINISHED{outcome:interrupt}` は `interrupts[].message` を表示して
  ターン終了（**本物の resume は v1 スコープ外・D4 のまま**＝再開はできない旨を明記して次の入力を促すだけ）、
  `RUN_ERROR` はエラーメッセージ表示
- **接続先は env**：`AGUI_BASE_URL`（既定 `http://127.0.0.1:8080`、`OPENAI_BASE_URL` と同じ流儀・D18）
- **結合テストは HTTP を起こさない**（D22 踏襲）：SSE フレーム分割・messages[] 集約はどちらも純粋関数化し
  ユニットテストで決定論的に検証。実 HTTP 経由の動作確認は手動 smoke（M3 の bear アプリ + stub-llm 相手に、
  並列 run・interrupt run・複数ターンの履歴引き継ぎを肉眼確認）

**DoD**: CLI から M3 の bear Swoole アプリを相手に、複数ターンの会話・並列ツール run・interrupt run を
手動 smoke で確認。`composer tests`（mago + phpmd + phpunit）と `composer crc` がグリーン。

## M5 — example④：ビルド不要 React チャット UI（AG-UI 消費側・ブラウザ）

> **詳細タスク**: [`tasks-m5.md`](tasks-m5.md)（ファイル/メソッド粒度）

M4（CLI）に続くもう一つの消費側の実物。今度は**ブラウザ**から M3 の bear Swoole アプリを叩く。狙いは
「Node/npm ビルド環境が無くても、React で AG-UI クライアントが書ける」ことを示すこと（D31）。

- **ビルドツール無し React**：`react`/`react-dom`（UMD）＋ `@babel/standalone` を CDN スクリプトタグで読み、
  `<script type="text/babel">` に直接 JSX を書く。Node/npm は一切不要。1 ファイル完結の静的 HTML
- **配信は同一オリジン**：静的 HTML は M3 の `public/server.php` の `onRequest` から直接返す（BEAR.Resource
  を経由しない＝静的アセットはツールリソースの関心事ではない）。これにより **CORS 対応が一切不要**
  （`/invocations` と同一オリジン。CORS 自体は ADR 0005 の関心事のままスコープ外）
- **SSE は `fetch()` + `ReadableStream` を自前パース**（`EventSource` は不可＝AG-UI は `POST`+body 必須）。
  M4 の `SseFrameReader`（PHP 版）と同型のロジックを JS で再実装（プラットフォームが違うため共有コード化はしない）
- **ライブラリ非依存・会話履歴はブラウザ側が正本**（D30 と同じ精神・M4 と同じ設計をブラウザに移植）
- **JS の自動テストは無し**：本リポジトリは PHP 専用のツールチェーン。検証は**手動ブラウザ smoke**
  （M4 と同じ 3 シナリオ：複数ターン・並列 run・interrupt run）に一本化する
- **本物の resume は引き続きスコープ外**（D4）

**DoD**: ブラウザから M3 の bear Swoole アプリを相手に、複数ターンの会話・並列ツール run・interrupt run を
手動 smoke で確認（肉眼でチャット UI が逐次更新されること）。`composer tests`（新規 PHP コードのみ対象）グリーン。

---

## スコープ外（ライブラリの責務ではない・アプリ/デプロイの関心事）

- AgentCore デプロイ・ARM64 コンテナ・OAuth/SigV4 認証（ADR 0005）
- state-as-resource / `STATE_SNAPSHOT` / `STATE_DELTA`（ADR 0003）— アプリ側で実装
- ALPS ツール供給（ADR 0004）— `bear/tool-use` 本体が担う
- **client-side tool 実行**（フロント実行ツール）— v1 非対応。AG-UI `tools[]` の未知名は除外（D16）
- **マルチモーダル入力**（画像等の `InputContent[]`）— v1 非対応。text パートのみ抽出（D17）。ToolUse は text-only
- **本物の interrupt / resume**（ToolUse 側に resume 再入 API が入り次第。`feedback/tool-use-resume.md`）
- `TOOL_CALL_ARGS` 引数ストリーミング（ToolUse feedback 反映待ち）
  - ※ **複数ツールの `toolCallId` 対応づけ**は M3 の並列ツール実行（D29 / [`tasks-parallel-tools.md`](done/tasks-parallel-tools.md)）で
    **in-scope 化**（FIFO → id キー化）。引数 interleave ストリーミングは引き続きスコープ外

## 依存とリスク

- **M0 → M1 → (M2 ∥ M3) → (M4 ∥ M5)**。M2/M3 は M1 の上に並行可。M4/M5 はどちらも M3 の bear アプリを
  実行対象に取るため M3 完了後（手動 smoke に M3 サーバの起動が要る）。M4/M5 は互いに独立（CLI とブラウザ）
  で並行可。
- `bear/tool-use` はオープン PR ブランチ pin のため揺れる。タグが切られ次第差し替え。
- AG-UI イベントのフィールドは poc を鵜呑みにせず実スキーマで確定（M0 で照合）。
