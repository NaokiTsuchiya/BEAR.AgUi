# Feedback: AG-UI 対応で見えた ToolUse 側の論点（resume 再入 API）

> 宛先: BEAR.ToolUse（PR #22 / `codex/review-hardening`）
> 発信元: BEAR.AgUi（AG-UI サポート層）の実装過程で確認した内容

## 背景

BEAR.ToolUse PR #22 の上に AG-UI（Agent-User Interaction Protocol）サポート層（BEAR.AgUi）を実装中。
`StreamingAgent::runStream()` が返す `AgentEvent` ストリームを AG-UI イベントへ変換するアダプタを書いている。
`text_delta` / `tool_start` / `tool_result` / `completed` のストリーミング変換は問題なく機能し、
**ToolUse 本体は無改造**で載る。1点だけ、ToolUse 側に機能追加が要りそうな箇所が見つかったので共有する。

## 結論（要望）

**ステートレスな「resume 再入 API」を streaming agent に追加してほしい。**
AG-UI の human-in-the-loop（interrupt）をサーバーレス/ステートレス HTTP（AWS Bedrock AgentCore 等）で
ホストするのに必要。

## なぜ必要か：2つのモデルの不一致

**AG-UI の interrupt は「ターミナル + 再開」モデル**（プロセスを生かし続けない）:

1. 承認が必要になると run を終了：`RunFinished { outcome: { type:"interrupt", interrupts:[{id,…}] } }`
2. クライアントは **新しい run** で再開：`RunAgentInput.resume = [{ interruptId, status:"resolved"|"cancelled", payload? }]`
3. run と run の間はステートレス（状態はクライアントの `resume[]` ＋ 毎 run 送られる `messages[]` が運ぶ）

**ToolUse の confirmation は「生 Generator + `send(bool)`」モデル**（`StreamingAgent::dispatchPendingToolCalls`）:

```php
if ($toolList->isConfirmable($toolCall->name)) {
    $approved = yield AgentEvent::confirmationRequired(...);  // 同一 Generator を suspend
    if ($approved !== true) { /* cancelled */ }
}
$result = $this->dispatcher->dispatch($toolCall);  // 承認後にのみ実行（事前ゲートは正しく機能）
```

これは CLI / 単一プロセスでは正しく動く。だが **2つの HTTP リクエストをまたぐと Generator は生存できない**：

- ループ位置（`for ($i…)` の周回、`foreach ($pendingToolCalls…)`）は **Generator スタックにしか無く、直列化不能**。
- `runStream(string $userMessage, ?AgentOptions)` は先頭で必ず `Message::user()` を append して
  **LLM ループを頭から**回す。「解決済みの pending tool call の続きから入る」入口が存在しない。

→ アダプタ側で `public $messages` を組み直して外部から続行しようとすると、
**ToolUse のエージェントループ（stream 消費・accumulator・iteration）を外で再実装**することになり、
無改造の利点が実質失われ、壊れやすい。再開の正しい置き場所は ToolUse 内部。

## 提案するインターフェース（たたき台）

ToolUse 自身が永続化を持つ必要はない（AG-UI は毎 run で `messages[]` を全送する）。
**「再構成済み messages + 決定」を受けて再入するステートレス API** で十分：

```php
/**
 * @param list<Message>      $messages 直前の run までの会話（assistant の tool_use ブロックを含む）
 * @param list<ToolDecision> $resume   [{ toolCallId, status: resolved|cancelled, payload? }]
 *
 * @return Generator<int, AgentEvent, mixed, void>
 */
public function resumeStream(array $messages, array $resume, ?AgentOptions $options = null): Generator;
```

- `resolved` → 該当 tool を dispatch して結果を継続。`cancelled` → `ToolResult::cancelled` で継続。
- いずれも **LLM にツール呼び出しを再生成させずに** pending tool call を解決してループを続ける。

（注：これが必要なのは **ステートレス/run 終了型** のホスティングのみ。接続を握り続ける **接続保持型** なら
既存の `ConfirmationHandlerInterface`〔同期ブロッキング〕で足り、resume は不要。）

## tool 系 AgentEvent のエンリッチ（中優先・AG-UI 対応に実質必須）

高レベル `AgentEvent` の tool 系イベントが、**ToolUse 内部に在るデータを捨てている**。AG-UI の
`TOOL_CALL_*` を正しく作るのに以下が要る（いずれも既存データを載せ替えるだけで、新しい状態管理は不要）。

| イベント | 現状 | 追加してほしい | ToolUse 内の出所 |
| --- | --- | --- | --- |
| `tool_start` | `toolName` のみ | **`toolCallId`** ＋（可能なら decoded `input`） | `StreamContentAccumulator::$currentToolId` / input JSON |
| `tool_result` | `toolName` のみ | **`toolCallId`** ＋ **`content`**（実際の結果）＋ `isError` | `ToolCall::$id` / `ToolResult::$content` / `$isError` |
| （任意）`tool_args` 新設 | 無し | `{toolCallId, delta}` を `TOOL_USE_DELTA` ごとに | `$currentToolInputJson` の delta |

**特に重要**: `tool_result` に `content` が無いため、AG-UI アダプタは現状 `TOOL_CALL_RESULT.content` に
**ツール名を入れざるを得ない**（結果本体が欠落）。`content` を載せれば解決する。

これらが入ると：

- **`tool_start.toolCallId` / `tool_result.toolCallId`** → 複数・並行ツール（Claude の並行 tool_use）の
  start↔result 対応が**実 id で厳密化**。BEAR.AgUi 側で暫定採用している「synthesize id の FIFO キュー」も不要になる。
- **`tool_result.content`** → ツール結果が AG-UI クライアントに正しく届く。
- **`tool_args` or `tool_start.input`** → `TOOL_CALL_ARGS` を、低レベル `StreamEvent::TOOL_USE_DELTA` に
  降りずに生成できる（`tool_start` に full `input` を1回載せるだけでも AG-UI 的には十分）。

なお `confirmation_required` は `toolName/toolId/input/message` を持ち、**interrupt の payload にそのまま
対応する**。ここは良好で、欠けているのは resume 再入のみ。

## まとめ

- **必須**: ステートレス resume 再入 API（上記）。これが無いと AgentCore 等での AG-UI 準拠 interrupt は
  ToolUse 無改造では実装できない。
- **中優先**: tool 系 `AgentEvent` のエンリッチ（`tool_start.toolCallId` / `tool_result.toolCallId` + `content`）。
  特に `tool_result.content` 欠落は、AG-UI のツール結果が「ツール名」になってしまう実害がある。
- **任意**: `tool_args` ストリーミング（無くても `tool_start.input` の一括で代替可）。
- BEAR.AgUi の v1 は interrupt を **非必須** として、`confirmation_required` → `RunFinished{outcome:interrupt}`
  で run を終了（resume 未対応・前方互換）し、既定は `AlpsToolPolicyInputProcessor::safeOnly()` で
  confirmable を出さない方針で進める予定。resume API が入った時点で `resume[]` を結線する。
