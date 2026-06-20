# アーキテクチャ：BEAR.AgUi アダプターライブラリ

`bear/tool-use` のエージェント出力（`AgentEvent` ストリーム）を **AG-UI プロトコル**（イベント + SSE）へ
変換するライブラリ。**フレームワーク非依存**（依存は `bear/tool-use` と `psr/log` のみ）。
ここでは「**パッケージ／オブジェクトのつなぎ（境界・依存方向・構築責任）**」を明確化する。
AG-UI 側の正確な仕様は [`reference/ag-ui-protocol.md`](reference/ag-ui-protocol.md)。決定は [`decisions.md`](decisions.md)。

---

## 1. モジュール依存グラフ（依存の向き）

```
                         ┌──────────────────────────────┐
   host app / DI ───────▶│  AgUiRunner（ファサード）       │
   （構築の起点）          └───────────────┬──────────────┘
                                          │ 組み立て・駆動
        ┌──────────────────┬──────────────┼───────────────┬─────────────────┐
        ▼                  ▼              ▼               ▼                 ▼
   Input/             Adapter/        ToolUse/         Sse/             (psr/log)
 RunAgentInput      AgUiAdapter   enrichment shim   SseResponder       Logger
        │                  │        (decorators)    SseEncoder
        │                  │            │           SseSink(IF)
        │                  ▼            ▼               ▲
        │           ┌─────────────────────────┐        │
        │           │ Event/ 値オブジェクト群   │────────┘
        │           │ AgUiEventInterface 実装  │
        │           └─────────────────────────┘
        ▼
  （bear/tool-use の型に依存するのは ToolUse/ と Adapter/ のみ）
```

**不変な依存規則：**

- `Event/` と `Sse/` は **`bear/tool-use` に一切依存しない**（純粋な AG-UI 直列化）。
- `bear/tool-use` への結合は **`ToolUse/`（エンリッチ層）と `Adapter/` の 2 箇所に隔離**。
  → ToolUse が `AgentEvent` をエンリッチしたら `ToolUse/` を削除し `Adapter/` を簡素化するだけで済む。
- 依存は内向き：`AgUiRunner → {Input, Adapter, ToolUse, Sse}`、`Adapter → Event`、`Sse → Event`。
  逆向き（Event→Adapter 等）は作らない。

---

## 2. オブジェクトと構築スコープ

「いつ・誰が作るか」を明示する。**run をまたいで状態を持たせない**（ADR 0005 ステートレス）。

| オブジェクト | スコープ | 主なコンストラクタ依存 | 状態 |
| --- | --- | --- | --- |
| 実 `StreamingLlmClientInterface` | **app 単一** | （LLM 接続） | — |
| 実 `DispatcherInterface` | **app 単一** | （リソース） | — |
| `list<Tool>` / `systemPrompt` | **app 単一** | — | — |
| `LoggerInterface`（psr/log） | **app 単一** | — | — |
| `SseEncoder` | **app 単一** | — | ステートレス |
| `InstrumentedAgentFactory` | **app 単一** | 上記 LLM/Dispatcher/tools/prompt | — |
| `AgUiRunner` | **app 単一** | factory, encoder, logger, 既定 processors | — |
| `SseSinkInterface` 実装 | **per-request** | （HTTP response） | レスポンス保持 |
| `SseResponder` | **per-request** | encoder, sink | — |
| `ToolCallRegistry` | **per-run** | — | tool 相関の状態 |
| `RecordingStreamingLlmClient` | **per-run** | 実 client, registry | 現在 tool id |
| `RecordingDispatcher` | **per-run** | 実 dispatcher, registry | — |
| `StreamingAgent`（ToolUse） | **per-run** | 上記デコレータ 2 つ, tools, prompt | `$messages` |
| `AgUiAdapter` | **per-run** | threadId, runId, registry(view), logger | openMessageId, FIFO |

> app 単一の重い依存（HTTP クライアント等）を、**per-run のデコレータが包む**のが要点。
> registry / agent / adapter は run ごとに作り捨てる。

---

## 3. つなぎ（Seam）一覧 — 各境界を渡る契約

| # | 境界 | 渡る契約（型） | 差し替え対象 |
| --- | --- | --- | --- |
| S1 | **host → library**（構築） | 実 client/dispatcher/tools/prompt（ToolUse 型）, `LoggerInterface` | アプリ／DI が供給 |
| S2 | **agent → adapter** | `Generator<int, AgentEvent, bool\|null, void>` ＋ `ToolCallView`（registry 読み） | — |
| S3 | **adapter → responder** | `Generator<int, AgUiEventInterface, …>` | — |
| S4 | **responder → 出力** | エンコード済み `string` フレーム via `SseSinkInterface` | **ランタイム差し替え点**（FPM/Swoole） |
| S5 | **decorators ↔ ToolUse** | `StreamEvent` / `ToolCall` / `ToolResult`（ToolUse 型）, `ToolCallRecorder`（自前） | **撤去可能な shim**（ToolUse エンリッチ時に削除） |
| S6 | **error 方針** | `LoggerInterface` 注入 + 固定 `RUN_ERROR` | 将来 `RunErrorMapperInterface`（D11） |

S2 が核心：**adapter はタイムライン（AgentEvent）と不足データ（registry）の 2 入力**を受ける。

---

## 4. 本パッケージが導入するインターフェース

エンリッチ層と出力層の「つなぎ」を型で固定する。

```php
// --- registry を read/write で分離（ISP）。実体 ToolCallRegistry が両方を実装 ---
interface ToolCallRecorder {                       // 書き手＝デコレータ
    public function recordStart(string $id, string $name): void;       // TOOL_USE_START
    public function appendInput(string $id, string $delta): void;      // TOOL_USE_DELTA
    public function recordResult(ToolCall $call, ToolResult $result): void; // dispatch 後
}
interface ToolCallView {                            // 読み手＝AgUiAdapter
    public function nextStarted(): ?StartedToolCall;          // FIFO で {id, name}
    public function resultFor(string $id): ?ToolCallOutcome;  // {input, content, isError}
}

// --- host が agent 構築を担い、その中で recorder を配線する seam（S1）---
interface InstrumentedAgentFactory {
    public function create(ToolCallRecorder $recorder): OptionAwareStreamingAgentInterface;
}

// --- 出力プリミティブ（S4・既存）---
interface SseSinkInterface {
    public function open(int $statusCode): void;
    public function write(string $frame): void;
    public function close(): void;
}
```

ライブラリは `InstrumentedAgentFactory` の**既定実装**（素の `StreamingAgent` 用）も同梱する：

```php
final class DefaultInstrumentedAgentFactory implements InstrumentedAgentFactory {
    public function __construct(
        private StreamingLlmClientInterface $client,
        private DispatcherInterface $dispatcher,
        private array $tools, private string $systemPrompt,
    ) {}

    public function create(ToolCallRecorder $rec): OptionAwareStreamingAgentInterface {
        return new StreamingAgent(
            new RecordingStreamingLlmClient($this->client, $rec),  // S5
            new RecordingDispatcher($this->dispatcher, $rec),      // S5
            $this->tools, $this->systemPrompt,
        );
    }
}
```

> `StreamingAgent` は final で依存が private のため**後付けデコレートは不可**。だから
> 「agent を作る前に依存を包む」＝ factory がエンリッチ配線を担う、という形にする。
> AgentFactory/AgentPool を使うアプリは、この IF を自前実装すれば同じ配線にできる。

---

## 5. 構築シーケンス（per-run の組み立て）

`AgUiRunner::run()` が 1 リクエストごとに以下を組む。registry は **同一実体を recorder と view の
2 つの顔**で渡すのがポイント。

```php
final class AgUiRunner {
    public function __construct(
        private InstrumentedAgentFactory $agentFactory,
        private SseEncoder $encoder,
        private ?LoggerInterface $logger = null,
        private array $inputProcessors = [],   // ALPS safeOnly 等（D4/ADR0004）
    ) {}

    public function run(RunAgentInput $input, SseSinkInterface $sink): void {
        $registry = new ToolCallRegistry();                       // per-run（recorder & view）
        $agent    = $this->agentFactory->create($registry);      // S1: decorators を内部配線（S5）
        $options  = AgentOptions::withProcessors(
            inputProcessors: $this->inputProcessors,
            enabledTools: $input->declaredToolNames() ?: null,   // RunAgentInput.tools → withTools
        );
        $agentStream = $agent->runStream($input->lastUserMessage(), $options); // S2 上流
        $adapter   = new AgUiAdapter($input->threadId, $input->runId, $registry, $this->logger);
        $responder = new SseResponder($this->encoder, $sink);    // per-request
        $responder->respond($adapter->run($agentStream));        // S2→S3→S4 を駆動
    }
}
```

`RunAgentInput` 不正時は `run()` 前（`fromJson`）で例外 → **HTTP 400**（接続レベル・ADR 0001）。

---

## 6. 実行シーケンス（データフローと registry サイドチャネル）

```
                       ┌─────────────────── ToolCallRegistry（per-run） ───────────────────┐
                       │  recordStart / appendInput      recordResult        nextStarted /  │
                       │        ▲(S5)                        ▲(S5)            resultFor (S2)  │
                       │        │                            │                     │         │
  実 LLM client ──▶ RecordingStreamingLlmClient   実 Dispatcher ──▶ RecordingDispatcher      │
        │ chatStream(StreamEvent)                       │ dispatch(ToolCall):ToolResult       │
        └──────────────┬───────────────────────────────┘                                     │
                       ▼  （実 StreamingAgent が両者を使って本物のループを回す）                  │
            StreamingAgent::runStream() ── Generator<AgentEvent> ──┐                           │
                                                                   ▼                           ▼
                                                        ┌────────────────────────────────────────┐
                                                        │ AgUiAdapter::run()                       │
                                                        │  AgentEvent（タイムライン）＋ registry(view)│
                                                        │  → Generator<AgUiEventInterface>         │
                                                        └───────────────┬──────────────────────────┘
                                                                        ▼ (S3)
                                                        SseResponder::respond()
                                                          foreach: SseEncoder.encode → SseSink.write+flush (S4)
                                                                        ▼
                                                              SSE をクライアントへ逐次配信
```

要点：

- **タイムライン**（いつ何が起きたか）は `AgentEvent` が運ぶ。**不足データ**（実 id/引数/結果 content）は
  `ToolCallRegistry` が横から供給。Adapter はこの 2 つを突き合わせる（`tool_start`/`tool_result` を契機に
  registry を FIFO 参照、D9/D10）。
- `RecordingStreamingLlmClient` は `chatStream()` を**透過 yield**しつつ `TOOL_USE_START`(id,name) /
  `TOOL_USE_DELTA`(input) を観測。`RecordingDispatcher` は `dispatch()` の戻り値から id+input+content を記録。
- Generator 3 段（runStream → adapter → responder）が**遅延 interleave**（バッファに溜めない）。

ToolUse `AgentEvent` → AG-UI イベントの写像表は [`reference/ag-ui-protocol.md`](reference/ag-ui-protocol.md)。

---

## 7. 拡張・差し替え点（どこを replace すれば何が変わるか）

| 変えたいこと | 差し替える seam / 型 |
| --- | --- |
| 実行環境（FPM→Swoole） | S4：`SseSinkInterface` 実装を `SwooleSseSink` に（上位は無変更） |
| エージェント構築（AgentFactory/Pool 利用） | S1：`InstrumentedAgentFactory` を自前実装 |
| ツール方針（ALPS safe-only 等） | `AgUiRunner.$inputProcessors`（`AgentOptions` へ） |
| エラー表現の細分化 | S6：`RunErrorMapperInterface` を導入（D11・将来） |
| ToolUse がイベントをエンリッチ | S5：`ToolUse/` デコレータと registry を撤去、Adapter を簡素化 |

---

## 8. 不変条件

- **ToolUse 本体を無改造**。不足は S5 のデコレータ（外側）で補い、ループは再実装しない。
- **レイヤーを混同しない**：変換=`AgUiAdapter`、フレーム化=`SseEncoder`、I/O=`SseSink`、起動=`AgUiRunner`/`RunAgentInput`。
- **`SseResponder` は body を畳まない**。1 イベントずつ pull→frame→write（無限ストリーム前提）。
- **run をまたいで状態を共有しない**。registry/agent/adapter は per-run。
- **`Event/`・`Sse/` を `bear/tool-use` に依存させない**（結合は `ToolUse/`・`Adapter/` に隔離）。
- エラー二分法：検証失敗=HTTP 400（`run()` 前）／実行中=`RUN_ERROR`（HTTP 200）。
