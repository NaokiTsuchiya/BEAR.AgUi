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

> 早見：どのホスティング形態で何が必要かは[利用者視点のマトリクス](#利用者ホスティング視点での整理待機点の置き場所)、
> resume の契約をどうテストで固定するかは[テスト視点での整理](#テスト視点での整理何が固定でき何が固定できないか)を参照。

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

## 利用者（ホスティング）視点での整理：待機点の置き場所

問題の本質は「confirm を**待機する**こと」ではなく、**待機点が直列化できない場所（Generator スタック）に
埋まっている**こと。`yield` の再開は同一プロセス・同一 Generator インスタンスを前提にするので、「誰がこの
エージェントを動かすか（プロセス寿命）」によって、待機が成立するかどうか・必要な API が変わる。

| ホスティング形態 | プロセス寿命 | confirm の解決手段 | 必要な API | 現状 |
| --- | --- | --- | --- | --- |
| **CLI / 単一プロセス** | run 中ずっと生存 | 生 Generator への `send(bool)` | 既存のまま | ✅ 足りる |
| **接続保持型**（SSE/WS 張りっぱなし） | 接続中ずっと生存 | `ConfirmationHandlerInterface`〔同期ブロッキング〕 | 既存のまま | ✅ 足りる |
| **ステートレス/run 終了型**（AgentCore, Lambda 等） | run ごとに死ぬ | run を**終了**し次 run で**再入** | `resumeStream(messages, resume[])` | ❌ 不可 |

「待機」が成立するのは上 2 形態（プロセスが生きている）だけ。最下段では待機そのものが不可能なので、
**「待機」を「終了 + 再入」へ変換する**しかない ＝ これが resume 提案の正体。そして「終了 + 再入」が
成立する前提が、AG-UI が毎 run `messages[]` をフル送信する点（状態をクライアントが運ぶので、サーバは
Generator を生かし続けずに「前回の途中」を `messages[]` ＋ `resume[]` から再構成できる）。**毎 run フル送信は
この再入モデルとセットで意味を持つ**。

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

## テスト視点での整理：何が固定でき、何が固定できないか

「今のテストがどの契約を固定しているか」を見ると、resume の欠落がテスト上どう現れるかがはっきりする
（BEAR.AgUi 側 `tests/Integration/StreamingAgentContractTest`：実 `StreamingAgent` を recording デコレータ
経由で駆動し、fake は LLM/Dispatcher 境界のみ）。

**今テストできている契約**：

- `testConfirmationRequiredEmitsInterruptOutcomeAndStopsRun` — confirmable tool で run を **terminate** し、
  `RunFinished{outcome:interrupt}` を出し、**dispatcher を呼ばない**（事前ゲート）。これは「待機を終了へ変換する」
  入口側の半分。

**今テストできない契約（API が無いため書けない）**：

- **再入の往復**：`interrupt → resume[resolved] → 該当 tool だけ dispatch → ループ続行 → 最終 text`。
  `resumeStream()` が無いので書けず、外で `public $messages` を組み直すと**エージェントループ自体を再実装**＝
  テスト対象が二重化する。再開の置き場所が ToolUse 内部であるべき理由は、テストの観点でも同じ。

**resume が入ったときに書くべきテスト（契約の形を先に決めておける）**：

1. **resolved 再入** — 中断後 `resume=[{toolCallId, status:resolved}]` で再入 → その tool が dispatch され、
   `tool_result` が出て run が `end_turn` で閉じる。**LLM にツール呼び出しを再生成させていない**ことを assert。
2. **cancelled 再入** — `status:cancelled` → `ToolResult::cancelled` で継続、dispatch は**呼ばれない**。
3. **不整合/冪等** — 存在しない `toolCallId`、二重 resume、`messages[]` と `resume[]` の食い違い → エラーの出方を固定。
4. **状態無依存（決定的テスト）** — 中断を出した Agent インスタンスと再入する Agent インスタンスを**別物**にしても
   通る。これが「Generator 生存に依存していない＝ステートレス」を 1 本で保証し、上 2 形態と最下段を分ける。

4 番が「利用者視点（表）」と「テスト」の接点：**別インスタンス再入が通る＝ stateless 契約**を 1 テストが裏打ちする。

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

## 会話履歴を seed する正式 API（中優先）

`runStream(string $userMessage, ?AgentOptions)` は単一メッセージしか取らず、**過去の会話履歴を渡す入口が無い**。
AG-UI はマルチターンで毎 run `messages[]`（履歴フル）を送ってくるため、サーバはそれを `StreamingAgent` の
会話状態へ seed して続きを回したい。現状は **`public array $messages` への直接代入**で代替できるが、これは
公開契約ではなく将来変わりうる。

要望：履歴を seed する**支援された API**。例：

```php
public function runStream(array $history, string $userMessage, ?AgentOptions $options = null): Generator;
// もしくは
public function withHistory(array $messages): static;   // list<Message>
```

これがあれば BEAR.AgUi 側の `MessageHistoryMapper`（AG-UI `messages[]` → `list<Message>` 変換）から
クリーンに seed できる（変換自体は AG-UI 固有なので BEAR.AgUi 側に残る）。

`resume`（前述）とこの history seed は、別々の要望に見えて**同じ一つの根**を共有する：
**ターン境界をまたいで状態をクライアントが運び、サーバは毎 run それを注入し直して続きを回す**（ステートレス
再入モデル）。`messages[]` が「会話の途中」を、`resume[]` が「pending tool call の決定」を運ぶ。どちらも
入口（seed / 再入）が ToolUse 側に無いのが課題で、上表「ステートレス/run 終了型」を成立させる両輪。

## まとめ

- **必須**: ステートレス resume 再入 API（上記）。これが無いと AgentCore 等での AG-UI 準拠 interrupt は
  ToolUse 無改造では実装できない。
- **中優先**: 会話履歴を seed する正式 API（`public $messages` 直叩きを置換）。マルチターン対応の前提。
- **中優先**: tool 系 `AgentEvent` のエンリッチ（`tool_start.toolCallId` / `tool_result.toolCallId` + `content`）。
  特に `tool_result.content` 欠落は、AG-UI のツール結果が「ツール名」になってしまう実害がある。
- **任意**: `tool_args` ストリーミング（無くても `tool_start.input` の一括で代替可）。
- **テスト指針**: resume の契約は「中断を出した Agent と再入する Agent を**別インスタンス**にしても通る」
  テストで固定する。これが Generator 生存に依存しない＝ステートレスであることを 1 本で保証し、
  「待機を終了 + 再入へ変換する」という設計全体の裏打ちになる。
- BEAR.AgUi の v1 は interrupt を **非必須** として、`confirmation_required` → `RunFinished{outcome:interrupt}`
  で run を終了（resume 未対応・前方互換）し、既定は `AlpsToolPolicyInputProcessor::safeOnly()` で
  confirmable を出さない方針で進める予定。resume API が入った時点で `resume[]` を結線する。
