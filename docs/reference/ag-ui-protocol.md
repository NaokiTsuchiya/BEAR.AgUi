# AG-UI プロトコル リファレンス（実装用）

BEAR.AgUi が準拠する AG-UI（Agent-User Interaction Protocol）の要点を、実装に必要な範囲で
**公式仕様から検証して**まとめたもの。出所は [docs.ag-ui.com](https://docs.ag-ui.com)（2026-06 時点で確認）。
仕様は進化するため、`⚠️` の箇所や疑義が出たら一次情報を再確認すること。

---

## トランスポート

- デフォルトは **HTTP POST + SSE**。1 イベント = `data: {json}\n\n`（1 行 `data:`、末尾空行で区切り）。
- リクエストボディは `RunAgentInput`（下記）。AgentCore 等は**無検証で渡す**ため検証はアプリ責任。
- エラーの二分法：**接続前の検証失敗 = HTTP 400** / **実行中の失敗 = SSE 内 `RUN_ERROR`（HTTP 200）**。

## イベント共通

- 共通フィールド：`type`（必須）、`timestamp?`、`rawEvent?`。本実装は `timestamp`/`rawEvent` を**出さない**。
- wire `type` は **SCREAMING_SNAKE_CASE**（例 `"TEXT_MESSAGE_CONTENT"`）。確認済み。

### EventType 一覧（全体）

```
RUN_STARTED, RUN_FINISHED, RUN_ERROR, STEP_STARTED, STEP_FINISHED
TEXT_MESSAGE_START, TEXT_MESSAGE_CONTENT, TEXT_MESSAGE_END, TEXT_MESSAGE_CHUNK
TOOL_CALL_START, TOOL_CALL_ARGS, TOOL_CALL_END, TOOL_CALL_RESULT, TOOL_CALL_CHUNK
STATE_SNAPSHOT, STATE_DELTA, MESSAGES_SNAPSHOT
ACTIVITY_SNAPSHOT, ACTIVITY_DELTA
REASONING_START, REASONING_MESSAGE_START, REASONING_MESSAGE_CONTENT,
REASONING_MESSAGE_END, REASONING_MESSAGE_CHUNK, REASONING_END, REASONING_ENCRYPTED_VALUE
RAW, CUSTOM
```

本実装（v1）が**生成する**のは太字のみ：**RUN_STARTED / RUN_FINISHED / RUN_ERROR /
TEXT_MESSAGE_START / TEXT_MESSAGE_CONTENT / TEXT_MESSAGE_END /
TOOL_CALL_START / TOOL_CALL_ARGS / TOOL_CALL_END / TOOL_CALL_RESULT**。
STATE_*/MESSAGES_*/REASONING_*/STEP_* はスコープ外（アプリ側 or 後続）。

---

## 使用イベントのフィールド（検証済み）

| イベント (`type`) | フィールド |
| --- | --- |
| `RUN_STARTED` | `threadId`、`runId`、`parentRunId?`、`input?` |
| `RUN_FINISHED` | `threadId`、`runId`、`outcome?`（下記）、`result?`（レガシー・後方互換） |
| `RUN_ERROR` | `message`（必須）、`code?` |
| `TEXT_MESSAGE_START` | `messageId`、`role`（`"assistant"`） |
| `TEXT_MESSAGE_CONTENT` | `messageId`、`delta`（非空） |
| `TEXT_MESSAGE_END` | `messageId` |
| `TOOL_CALL_START` | `toolCallId`、`toolCallName`、`parentMessageId?` |
| `TOOL_CALL_ARGS` | `toolCallId`、`delta` |
| `TOOL_CALL_END` | `toolCallId` |
| `TOOL_CALL_RESULT` | `messageId`（**必須**）、`toolCallId`、`content`、`role?`（`"tool"`） |

`role` の取り得る値：`"developer" | "system" | "assistant" | "user" | "tool"`。

### 順序の制約

- **ライフサイクル**：`RUN_STARTED` … `RUN_FINISHED`（または `RUN_ERROR`）。
- **テキスト**：`TEXT_MESSAGE_START` → `TEXT_MESSAGE_CONTENT*` → `TEXT_MESSAGE_END`。
- **ツール**：`TOOL_CALL_START` → `TOOL_CALL_ARGS*` → **`TOOL_CALL_END`** → `TOOL_CALL_RESULT`。
  `TOOL_CALL_END` は **`TOOL_CALL_RESULT` の前に必須**。複数ツールは `toolCallId` ごとに上記順を守れば
  異なる id 間でインターリーブ可。

---

## interrupt（human-in-the-loop）= ターミナルモデル

ブロックして待たない。**run を終了し、新しい run の `resume[]` で再開**する。

### RunFinished.outcome（current・stable）

判別共用体。`outcome` は optional（無い古い producer も妥当）。

```jsonc
// 成功
{ "type": "success" }
// 中断（人間入力待ち）
{ "type": "interrupt", "interrupts": [ /* Interrupt[] 非空 */ ] }
```

### Interrupt オブジェクト（`interrupts[]` の要素）

```typescript
type Interrupt = {
  id: string                 // 相関キー（interrupt / resume / 監査をまたぐ）。必須
  reason: string             // カテゴリ的ルーティングヒント。必須
  message?: string           // 人間可読プロンプト。汎用フォールバック UI 文言
  toolCallId?: string        // 直前の ToolCall* 系列に結びつける
  responseSchema?: JsonSchema // resume.payload の期待スキーマ
  expiresAt?: string         // ISO-8601 TTL
  metadata?: Record<string, any> // フレームワーク固有の自由データ
}
```

### RunAgentInput.resume[]（再開時）

```typescript
{
  interruptId: string                  // 中断 run の interrupts[].id を参照
  status: "resolved" | "cancelled"     // resolved=応答あり（payload に内容）/ cancelled=放棄
  payload?: any                        // status=resolved の時の応答本体
}
```

---

## コア型（RunAgentInput / Message / Tool / …）

出所：<https://docs.ag-ui.com/sdk/js/core/types>

### RunAgentInput（入力ボディ）

| フィールド | 型 | 必須 |
| --- | --- | --- |
| `threadId` | string | ✓ |
| `runId` | string | ✓ |
| `parentRunId` | string | — |
| `state` | any（フロントの現在状態・**信頼できない入力**・要サニタイズ） | ✓ |
| `messages` | `Message[]` | ✓ |
| `tools` | `Tool[]` | ✓ |
| `context` | `Context[]` | ✓ |
| `forwardedProps` | any（自由形式） | ✓ |
| `resume` | `Resume[]`（interrupt 拡張。再開 run のみ。上記参照） | — |

```jsonc
{
  "threadId": "thread-123", "runId": "run-456",
  "messages": [ { "id": "msg-1", "role": "user", "content": "..." } ],
  "tools": [], "context": [], "state": {}, "forwardedProps": {}
}
```

### Role（enum）

`"developer" | "system" | "assistant" | "user" | "tool" | "activity" | "reasoning"`

### Message（派生型・共通に `id` / `role` / `content`）

| 型 | 主なフィールド |
| --- | --- |
| `UserMessage` | `id`、`role:"user"`、**`content: string \| InputContent[]`**、`name?` |
| `AssistantMessage` | `id`、`role:"assistant"`、`content?`、`name?`、`toolCalls?: ToolCall[]` |
| `SystemMessage` | `id`、`role:"system"`、`content`、`name?` |
| `DeveloperMessage` | `id`、`role:"developer"`、`content`、`name?` |
| `ToolMessage` | `id`、`role:"tool"`、`content`、`toolCallId`、`error?`、`encryptedValue?` |
| `ActivityMessage` | `id`、`role:"activity"`、`activityType`、`content: Record<string,any>` |
| `ReasoningMessage` | `id`、`role:"reasoning"`、`content`、`encryptedValue?` |

### ToolCall（**メッセージ履歴上**の関数呼び出し。OpenAI 形式）

```typescript
type ToolCall = { id: string; type: "function"; function: { name: string; arguments: string /* JSON 文字列 */ }; encryptedValue?: string }
```

> ⚠️ これは `AssistantMessage.toolCalls` に載る**履歴上の**ツール呼び出し。ストリーミングの
> `TOOL_CALL_START/ARGS/END/RESULT` イベント（前述）とは**別物**。混同しないこと。

### Tool / Context / State

```typescript
type Tool    = { name: string; description: string; parameters: any /* JSON Schema */ }
type Context = { description: string; value: string }
type State   = any   // 任意のデータ構造
```

### 本実装への含意

- trigger（`RunAgentInput.userMessage`）の導出は `RunAgentInputParser` が担い、**`UserMessage.content` が
  `string` か `InputContent[]`** の両方を想定する（text パートのみ抽出・D17）。
- `RunAgentInput.declaredToolNames` は `Tool.name` を射影する（D16・一致）。
- `messages[]` の**最後の user を trigger**、残りを `history` として `MessageHistoryMapper` が再構成し
  agent に seed する（マルチターンは D15 で M1 に格上げ済み）。

---

## ToolUse `AgentEvent` → AG-UI イベント 写像（本実装の契約）

`bear/tool-use` の高レベル `AgentEvent`（6 種）を、AG-UI へ変換する際の対応。`[reg]` はエンリッチ層
（[`../decisions.md`](../decisions.md) D10）由来の実 id / 引数 / 結果を使う箇所。

| ToolUse `AgentEvent` | 生成する AG-UI イベント |
| --- | --- |
| （run 開始） | `RUN_STARTED` |
| `text_delta` | 必要なら `TEXT_MESSAGE_START` → `TEXT_MESSAGE_CONTENT` |
| `tool_start` | （開いてれば `TEXT_MESSAGE_END`）→ `TOOL_CALL_START`（`[reg]` 実 id・早期発火） |
| `tool_result` | `TOOL_CALL_ARGS`（`[reg]` input）→ `TOOL_CALL_END` → `TOOL_CALL_RESULT`（`[reg]` content、`messageId` 採番） |
| `confirmation_required` | （`TEXT_MESSAGE_END`）→ `RUN_FINISHED{outcome:interrupt, interrupts:[{id, reason:"tool_confirmation", message, toolCallId}]}` で**run 終了**（v1・resume 未対応） |
| `error` | （`TEXT_MESSAGE_END`）→ `RUN_ERROR{code:"AGENT_ERROR", message:汎用文}`（実例外は logger へ） |
| `completed` | （`TEXT_MESSAGE_END`）。`fullText` は破棄 |
| （run 正常終了） | `TEXT_MESSAGE_END`（開いてれば）→ `RUN_FINISHED{outcome:success}` |
| （実行中の例外） | `RUN_ERROR`（HTTP は 200 のまま） |

---

## 出所

- イベント概念・順序: <https://docs.ag-ui.com/concepts/events>
- EventType / フィールド（SDK）: <https://docs.ag-ui.com/sdk/js/core/events>
- interrupt / resume: <https://docs.ag-ui.com/concepts/interrupts>
