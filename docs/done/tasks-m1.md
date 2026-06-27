# M1 実装タスク（詳細）

ファサード `AgUiRunner` ＋ 起動マッピング（入力境界 → エージェント起動 → SSE 配信）。
[`decisions.md`](decisions.md) D4/D14/D15/D16/D17 と [`architecture.md`](architecture.md) §4-6 を実装タスクに落としたもの。
末尾 `(Dxx)` は根拠。`⚠️` は実装時に実物で確認する点。前提：M0（Event/Sse/Input/ToolUse 部品/Adapter）完了。

新規 composer 依存は無し（M0 の `bear/tool-use`/`psr/log` で足りる）。

---

## T1. 入力境界の拡張（`src/Input/`）

- [ ] `UserContent`（純 AG-UI・tool-use 非依存）：`toText(string|array $content): string` (D17)
  - `string` → そのまま
  - `InputContent[]` → `type:"text"` パートのみ `\n` 連結、非テキストは除外（debug ログ任意）
  - 抽出結果が空 → `InvalidArgumentException`（上流で **HTTP 400**）
  - [ ] ⚠️ `InputContent` の判別子（`type:"text"` と text フィールド名）を AG-UI 型で確認
- [ ] `RunAgentInput::lastUserMessage()`：最後の user メッセージの `content` を `UserContent::toText()` で正規化（D17）
- [ ] `RunAgentInput::historyMessages(): list<array>`：`messages[]` から**最後の user を除いた**履歴（D15・mapper 入力）
- [ ] `declaredToolNames()` は M0 実装済み（変更なし）
- [ ] テスト：string / 配列（text 連結）/ 非テキスト除外 / 空→例外 / user 不在→例外

## T2. 会話履歴マッパ（`src/ToolUse/MessageHistoryMapper.php`、D15）

`map(list<array> $messages): list<BEAR\ToolUse\Runtime\Message>`。**全再構成**（部分は ReAct を壊すため不可）。

- [ ] `UserMessage` → `Message::user(UserContent::toText($content))`
- [ ] `AssistantMessage{content?, toolCalls?}` → `Message::assistant($blocks)`
  - text block `{type:text, text}`（content があれば）＋ `toolCalls[]` → `{type:tool_use, id, name, input}`
  - `function.arguments`（JSON 文字列）を decode → `input`（不正 JSON は `[]`）
- [ ] **連続する `ToolMessage` を 1 つの `Message::toolResults([...])` に grouping**
  - 各 `ToolMessage{content, toolCallId, error?}` → `error` 有り `ToolResult::error(toolCallId, msg)` / 無し `ToolResult::success(toolCallId, content)`
- [ ] `System` / `Developer` / `Activity` / `Reasoning` は skip（systemPrompt は別管理）
- [ ] ⚠️ assistant content-block 形式が ToolUse 期待（`StreamContentAccumulator::finalizeContentBlock` と同形）であることを確認
- [ ] テスト：①テキストのみ ②tool ターン（assistant tool_use ↔ tool_result ペア）③並行ツール（grouped results）④skip ロール ⑤不正 arguments JSON

## T3. Instrumented agent factory（`src/ToolUse/`、D14/D15/D16）

- [ ] `InstrumentedAgentFactory`（IF）
  - `create(ToolCallRecorder $rec, array $history): OptionAwareStreamingAgentInterface`
  - `knownToolNames(): list<string>`
- [ ] `DefaultInstrumentedAgentFactory implements InstrumentedAgentFactory`
  - ctor: 実 `StreamingLlmClientInterface` / `DispatcherInterface` / `list<Tool>` / `systemPrompt`
  - `create()`：`RecordingStreamingLlmClient`/`RecordingDispatcher`（M0）で実依存を包み `StreamingAgent` を構築、`$agent->messages = $history` で **seed**（D15）
  - `knownToolNames()`：`array_map(fn(Tool $t)=>$t->name, $this->tools)`（D16）
- [ ] テスト：create がデコレータを配線し history を seed する／knownToolNames が登録名を返す

## T4. ファサード（`src/AgUiRunner.php`、D4/D14/D15/D16/ADR0001/0004）

- [ ] ctor：`InstrumentedAgentFactory`, `MessageHistoryMapper`, `SseEncoder`, `?LoggerInterface`, `list<InputProcessorInterface> $inputProcessors = []`
- [ ] `run(RunAgentInput $input, SseSinkInterface $sink): void`
  - [ ] **pre-flight（stream 開始前＝接続レベル）**：`$userMsg = $input->lastUserMessage()`（空なら例外→400）、`$history = $this->historyMapper->map($input->historyMessages())`
  - [ ] `$registry = new ToolCallRegistry()`
  - [ ] `$agent = $this->agentFactory->create($registry, $history)`
  - [ ] **ツール交差（D16）**：`$enabled = $declared===[] ? null : array_values(array_intersect($declared, $factory->knownToolNames()))`
  - [ ] `$options = AgentOptions::withProcessors(inputProcessors: $this->inputProcessors, enabledTools: $enabled)`
  - [ ] `$agentStream = $agent->runStream($userMsg, $options)`
  - [ ] `$adapter = new AgUiAdapter($input->threadId, $input->runId, $registry, $this->logger)`
  - [ ] `(new SseResponder($this->encoder, $sink))->respond($adapter->run($agentStream))`
  - [ ] 順序保証：pre-flight は `respond()`（= `sink->open` / `RUN_STARTED`）より**前**。例外は呼び出し側で HTTP 400 に写像

## T5. 統合テスト（`tests/`）

- [ ] `DefaultInstrumentedAgentFactory` に `FakeStreamingLlmClient`+`FakeDispatcher`（M0・D13）を注入し、`AgUiRunner` を
  recording sink で駆動して **SSE フレームを end-to-end 検証**
  - [ ] 単一ターン（テキスト）
  - [ ] **マルチターン**：history を seed → 2 ターン目で文脈参照（fake LLM が messages 数/内容を反映する形で検証 or seed 後の `$agent->messages` を確認）（D15）
  - [ ] **ツール交差**：宣言が既知の部分集合＝その分だけ有効／未知名は除外しエラー無し（D16）
  - [ ] interrupt：confirmation → `RUN_FINISHED{outcome:interrupt}`（D4）
  - [ ] error：実行中失敗 → `RUN_ERROR`（HTTP 200）
  - [ ] 検証：空 user content → 400／不正 JSON → 400（stream 開始前）

## T6. 仕上げ / DoD

- [ ] `composer tests`（mago + phpmd + phpunit）グリーン
- [ ] `composer crc` グリーン
- [ ] `mago guard`（M0/T8 の境界規則）グリーン。`MessageHistoryMapper`/factory は `…\ToolUse\` で tool-use 結合 OK、`AgUiRunner` は許可された依存のみ

---

## コミット粒度

作業ブランチ（M0 の続き or 新規）。**各コミット green**、依存順。メッセージは命令形・プレフィックス無し。

| # | コミットメッセージ（案） | 含むタスク | green |
| --- | --- | --- | --- |
| C1 | `Add user-content text extraction and history accessors` | T1 | phpunit |
| C2 | `Add AG-UI message history mapper` | T2 | phpunit |
| C3 | `Add instrumented agent factory` | T3 | phpunit |
| C4 | `Add AgUiRunner facade with integration tests` | T4 + T5 | `composer tests` |

依存順：**C1 → C2 → C3 → C4**。C2 は C1（`UserContent`）を使う。C3 は M0 のエンリッチ部品（`ToolCallRecorder`/
デコレータ）必須。C4 は C1〜C3 ＋ M0 の Adapter/SSE 必須。guard（M0/T8）は導入済みなので各コミットで効く。

---

## 実装時に確定する未決の小項目（⚠️ 集約）

- `InputContent` の判別子（`type:"text"` と text フィールド名）（D17）
- `AssistantMessage.toolCalls[].function.arguments` の decode 失敗時の扱い（`[]` で続行を想定）
- マルチターン検証で「文脈が効いている」ことをどう決定論的に assert するか（fake LLM の応答を messages 依存にする）

> 解決済み（→ [protocol ref](reference/ag-ui-protocol.md)）：Message 派生型・ToolCall（履歴）・Tool のフィールド。
