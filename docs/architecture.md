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
- 依存は内向き：`AgUiRunner → {Adapter, ToolUse（factory/historyMapper）}`、`Adapter → Event`、`Sse → Event`。
  逆向き（Event→Adapter 等）は作らない。**runner は `Sse/` に依存しない**（生成のみ。SSE 化は host が
  `SseResponder`+sink で行う・D23）。`Input/`（parse 済み `RunAgentInput`）は host が runner に渡す。

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
| `MessageHistoryMapper` | **app 単一** | — | ステートレス |
| `InstrumentedAgentFactory` | **app 単一** | 上記 LLM/Dispatcher/tools/prompt | — |
| `AgUiAdapter` | **app 単一** | logger | ステートレス（per-run 値は `run()` 引数） |
| `SseResponder` | **app 単一** | encoder | ステートレス（sink は `respond()` 引数） |
| `AgUiRunner` | **app 単一** | factory, historyMapper, adapter, 既定 processors | ステートレス |
| `SseSinkInterface` 実装 | **per-request** | （HTTP response） | レスポンス保持 |
| `ToolCallRegistry` | **per-run** | — | tool 相関の状態 |
| `RecordingStreamingLlmClient` | **per-run** | 実 client, registry | 現在 tool id |
| `RecordingDispatcher` | **per-run** | 実 dispatcher, registry | — |
| `StreamingAgent`（ToolUse） | **per-run** | 上記デコレータ 2 つ, tools, prompt | `$messages` |

> app 単一の重い依存（HTTP クライアント等）を、**per-run のデコレータが包む**のが要点。
> per-run で作り捨てるのは **registry / デコレータ / agent** のみ。adapter・responder・runner は
> ステートレスな app 単一で、run/request 固有の値（threadId/runId/registry/sink）は**メソッド引数**で渡す
> （D23 で per-run 構築から app 単一へ精緻化）。

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

// --- host が agent 構築を担い、その中で recorder 配線と履歴 seed を行う seam（S1）---
interface InstrumentedAgentFactory {
    /** @param list<Message> $history 再構成済み会話履歴（D15・最後の user を除く） */
    public function newInstance(ToolCallRecorder $recorder, array $history): OptionAwareStreamingAgentInterface;
    /** @return list<string> agent に登録されたサーバツール名（D16: 宣言ツールとの交差用） */
    public function knownToolNames(): array;
}

// --- 出力プリミティブ（S4・D23 で単一 send() に集約）---
interface SseSinkInterface {
    /**
     * @param array<string, string> $headers SSE レスポンスヘッダ
     * @param iterable<string>       $frames  エンコード済み SSE フレーム（遅延 pull）
     */
    public function send(array $headers, iterable $frames): void;
}
```

> `send()` 一発に集約したのは、status＋headers → body フレーム → end の**順序を内部に隠蔽**し、
> 呼び出し側が `open/write/close` を誤順序で呼べないようにするため（`headersSent` 状態も持たない）。

ライブラリは `InstrumentedAgentFactory` の**既定実装** `StreamingAgentFactory`（素の `StreamingAgent` 用）も同梱する：

```php
final readonly class StreamingAgentFactory implements InstrumentedAgentFactory {
    public function __construct(
        private StreamingLlmClientInterface $client,
        private DispatcherInterface $dispatcher,
        private array $tools, private string $systemPrompt,
    ) {}

    public function newInstance(ToolCallRecorder $rec, array $history): OptionAwareStreamingAgentInterface {
        $agent = new StreamingAgent(
            new RecordingStreamingLlmClient($this->client, $rec),  // S5
            new RecordingDispatcher($this->dispatcher, $rec),      // S5
            $this->tools, $this->systemPrompt,
        );
        $agent->messages = $history;   // D15: 履歴 seed（ToolUse の history API 欠如をカバー）
        return $agent;
    }

    public function knownToolNames(): array {       // D16
        return array_map(static fn (Tool $t) => $t->name, $this->tools);
    }
}
```

> `StreamingAgent` は final で依存が private のため**後付けデコレートは不可**。だから
> 「agent を作る前に依存を包む」＝ factory がエンリッチ配線を担う、という形にする。
> AgentFactory/AgentPool を使うアプリは、この IF を自前実装すれば同じ配線にできる。

---

## 5. 構築シーケンス（ストリーム生成と host による枠付け）

役割分担が D23 で精緻化された：**`AgUiRunner::stream()` はイベントストリームを *生成*するだけ
（レンダリングしない）**。SSE 化と I/O・HTTP ステータス写像は host の関心事で、host が
`SseResponder` + sink で枠付ける：

```php
// host 側（example/server や BEAR ハンドラ）
$input = $parser->parse($body);                    // RunAgentInput | ParseError
if ($input instanceof ParseError) {
    // 接続レベルの失敗 → HTTP 400（ADR 0001）。stream は開かない
    return http400($input->message);
}
$responder->respond($runner->stream($input), $sink);   // 200 + SSE を逐次配信
```

`AgUiRunner` 自身は run 固有の状態を持たず、協力者はすべてステートレスな app 単一を直接使う。
per-run で作るのは registry と agent だけ。registry は **同一実体を recorder と view の 2 つの顔**で渡す。

```php
final readonly class AgUiRunner {
    public function __construct(
        private InstrumentedAgentFactory $agentFactory,
        private MessageHistoryMapper $historyMapper,   // D15: messages[] → list<Message>
        private AgUiAdapter $adapter,                  // app 単一・ステートレス
        private array $inputProcessors,                // ALPS safeOnly 等（D4/ADR0004）
    ) {}

    /** @return iterable<AgUiEventInterface> 遅延ストリーム（host が消費するまで agent は走らない） */
    public function stream(RunAgentInput $input): iterable {
        $registry = new ToolCallRegistry();                       // per-run（recorder & view）
        $history  = $this->historyMapper->map($input->history);   // D15: 純射影。検証済み入力前提
        $agent    = $this->agentFactory->newInstance($registry, $history);  // S1: decorators 配線 + 履歴 seed
        $enabled  = $input->declaredToolNames === []              // D16: lenient 交差
            ? null                                                // 空 → 絞らない（ALPS が統治）
            : array_values(array_intersect($input->declaredToolNames, $this->agentFactory->knownToolNames()));
        $options  = AgentOptions::withProcessors(
            inputProcessors: $this->inputProcessors,
            enabledTools: $enabled,                              // 未知名（client-side tool）は除外
        );
        $agentStream = $agent->runStream($input->userMessage, $options);          // S2 上流
        return $this->adapter->run($agentStream, $input->threadId, $input->runId, $registry); // S2→S3
    }
}
```

`RunAgentInput` は**純データ**で、不正入力は parse 境界（`RunAgentInputParser::parse(): RunAgentInput|ParseError`、
**throw しない総関数**）で `ParseError` として弾かれ host が **HTTP 400** に写像する（ADR 0001）。よって
`stream()` 到達時点で trigger（非空 user メッセージ）は検証済みで、`stream()` 内は純射影に保てる。
返すのは遅延ストリームなので、実行中（mid-stream）の失敗は例外ではなく **`RUN_ERROR` イベント**として
すでに開いた 200 ストリームに乗る。

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
                                                        SseResponder::respond(events, sink)
                                                          SseSink.send(headers, frames): frame ごとに encode→write+flush (S4)
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
- **レイヤーを混同しない**：変換=`AgUiAdapter`、フレーム化=`SseEncoder`/`SseResponder`、I/O=`SseSink`、
  オーケストレーション=`AgUiRunner`、入力境界=`RunAgentInputParser`/`RunAgentInput`。**生成（runner）と
  レンダリング（responder+sink）は host が枠付けで分離**（D23）。
- **`SseResponder` は body を畳まない**。1 イベントずつ pull→frame→write（無限ストリーム前提）。
- **run をまたいで状態を共有しない**。registry/agent/adapter は per-run。
- **`Event/`・`Sse/` を `bear/tool-use` に依存させない**（結合は `ToolUse/`・`Adapter/` に隔離）。
- エラー二分法：検証失敗=HTTP 400（parse 境界の `ParseError`・`stream()` 前）／実行中=`RUN_ERROR`（HTTP 200）。
