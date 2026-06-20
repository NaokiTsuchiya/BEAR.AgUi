# ADR Set: BEAR.Sunday における AG-UI サポート

> AG-UI (Agent-User Interaction Protocol) を BEAR.Sunday アプリケーションでサポートするための設計論点を ADR 形式で整理する。
> 前提パッケージ名は仮に `BEAR.AgUi` とする。デプロイ先は AWS Bedrock AgentCore Runtime を主たるターゲットとし、Swoole + ARM64 構成を活用する。

---

## ADR 0000: AG-UI を BEAR.Sunday に載せる位置づけ（メタ決定）

### Status
Proposed

### Context
AG-UI は `run(input: RunAgentInput) -> Observable<BaseEvent>` という関数抽象を、HTTP POST + SSE で実体化したプロトコルである。約16種のイベント型（`RUN_STARTED` / `TEXT_MESSAGE_*` / `TOOL_CALL_*` / `STATE_SNAPSHOT` / `STATE_DELTA` / `RUN_FINISHED` / `RUN_ERROR` 他）を SSE で `data: {json}\n\n` 形式で送出する。

BEAR.Sunday はリソース指向（アプリ状態 = リソースの遷移）・ステートレス・ハイパーメディア駆動を中核思想とする。両者は「状態をどう扱うか」で思想が逆を向いている部分がある（後述 ADR 0003）。したがって、まず「AG-UI を BEAR のどのレイヤーの関心事として受け入れるか」を決める必要がある。

### Decision
AG-UI は **第一義的にはトランスポート/レンダリング層のプロトコル**として受け入れる。すなわち、リソース設計は従来の BEAR.Sunday 流（`ResourceObject` + メソッド + ハイパーメディア）を維持し、最終段の Renderer / Transfer (Responder) を AG-UI イベントストリームに差し替える形で実装する。

ただし思想的拡張（AG-UI イベント列をハイパーメディアの一種として再解釈する路線）を ADR 0003 で別途検討する。なお `STATE_*` イベントについては、ADR 0003 で state-as-resource として整理した結果、当初想定した「思想的緊張」は解消している。

### Consequences
- 既存の BEAR.Sunday の DI / AOP / リソースモデルをそのまま活かせる。
- AG-UI 対応は「出力アダプタの追加」として段階導入できる（既存アプリへの侵襲が小さい）。
- 一方で、ステートフルな `STATE_*` イベントの扱いは BEAR の世界観と緊張するため、別 ADR で態度を明示する必要がある。

---

## ADR 0001: 入力境界 — RunAgentInput のマッピングとバリデーション

### Status
Accepted（プロトタイプで構造検証と HTTP 400 分岐を実装・確認。@JsonSchema 化のみ本配線で残る）

### Context
AgentCore は POST `/invocations` のペイロードを**無検証で**コンテナに渡す。AG-UI 準拠であるためには `RunAgentInput` スキーマに従う必要があるが、どのフィールドを必須とし、検証エラーをどう扱うかは実装側の責任である。

`RunAgentInput` の構造:

```json
{
  "threadId": "thread-123",
  "runId": "run-456",
  "messages": [{"id": "msg-1", "role": "user", "content": "Hello, agent!"}],
  "tools": [],
  "context": [],
  "state": {},
  "forwardedProps": {}
}
```

この構造は深く（ネストした `messages[]` / `tools[]`）、BEAR.Resource の `onPost($threadId, $runId, ...)` のスカラ引数に素直には載らない。

### Decision
`RunAgentInput` を **専用の Input Object（値オブジェクト）** として受け、`@JsonSchema` でスキーマ検証する。リソースメソッドのシグネチャは個別フィールドを展開せず、Input Object 1個を受け取る形にする。

検証フェーズは、過去に検討した `ValidationPhase` enum の考え方を踏襲し、**ストリーム開始前（接続レベル）で検証**する。これは AgentCore のエラー分類（接続レベルエラー = 標準 HTTP ステータス / ランタイムエラー = SSE 内 `RUN_ERROR`）と整合させるためである。

- スキーマ検証失敗 → HTTP 400 `VALIDATION_ERROR`（ストリームを開始しない）
- 実行中の失敗 → SSE 内 `RUN_ERROR` イベント（HTTP は 200）

### Consequences
- バリデーション責任がリソース層に閉じ、AgentCore 側の無検証ポリシーを安全に補完できる。
- 「接続レベル/ランタイム」のエラー二分法を、BEAR の例外 → HTTP 変換と SSE イベント生成の2系統に明確に分離できる。
- `forwardedProps` のような自由形式フィールドは JsonSchema で緩く受ける必要があり、過剰な厳格化を避ける設計判断が要る。

---

## ADR 0002: 出力境界 — SSE Renderer と Transfer (Responder) の分離

### Status
Accepted（standalone プロトタイプで核心を検証済み。BEAR への Transfer 結線のみ未着手）

> **検証メモ（プロトタイプ）**: SseEncoder（1イベント→`data: {json}\n\n` の純粋変換）と
> SseResponder（Generator を1個ずつ pull→frame→write、畳まない）に責務分離して実装・実行。
> Generator 3段パイプ（ToolUse生成 → adapter変換 → responder書出し）が遅延ストリーミングする
> ことを、生成と書出しが interleave するログで確認。実 HTTP（`php -S`）でも 200ms 間隔の
> トークンが約200ms間隔で逐次到達することをタイムスタンプで確認した。
> 唯一の残課題は下記 Consequences の「`$ro->body` に Generator を入れて通常レンダラをバイパスし
> SseResponder まで生で届ける BEAR 結線」で、これは standalone では検証できず実 BEAR が要る。

### Context
BEAR.Sunday の通常フローは `ResourceObject → RenderInterface → TransferInterface → 出力`。AG-UI では、レスポンスは単一の確定ボディではなく**イベントの連続ストリーム**である。`ResourceObject` の body を「確定値」ではなく「イベントの Observable（PHP では Generator / Swoole coroutine channel）」として扱う必要がある。

責務は2つに分かれる:
1. **直列化**: 各 `BaseEvent` を `data: {json}\n\n` に変換する（純粋な変換、I/O なし）
2. **転送**: `Content-Type: text/event-stream` を立て、各イベントを逐次 flush する（I/O あり）

### Decision
責務を BEAR の既存2インターフェースに割り当てる:

- `AgUiSseRenderer implements RenderInterface`
  各イベント値オブジェクトを SSE フレーム文字列へ直列化。`runId` / `threadId` / `messageId` などイベント型固有フィールドを保持する純粋関数的変換。
- `SwooleSseResponder implements TransferInterface`
  `Content-Type: text/event-stream` を設定し、body の Generator を `yield` ごとに `$response->write()` で送出。Swoole HTTP Server では `end()` ではなく `write()` ループにする。

イベント自体は**16種の値オブジェクト**（`RunStarted`, `TextMessageStart/Content/End`, `ToolCallStart/Args/Result`, `StateSnapshot`, `StateDelta`, `RunFinished`, `RunError` …）として表現し、共通の `AgUiEventInterface` を実装する。

### Consequences
- BEAR の「Renderer = 表現生成 / Transfer = 出力」という関心の分離をそのまま再利用でき、HTTP/JSON との切り替えも DI バインディングの差し替えで済む。
- イベントを値オブジェクト化することで型安全性とテスト容易性が上がる（直列化のユニットテストが純粋関数として書ける）。
- Generator ベースの body は BEAR の `$ro->body` が「スカラ/配列」を想定する箇所と相性問題を起こしうるため、専用の `StreamedResourceObject` 的な拡張が必要になる可能性がある（要検証）。

---

## ADR 0003: 状態同期（STATE_SNAPSHOT / STATE_DELTA）を state-as-resource として扱う

### Status
Accepted

### Context
AG-UI は `STATE_SNAPSHOT` / `STATE_DELTA` により、エージェントとフロントエンド間でアプリ固有の構造化状態（plan、フォーム、ドキュメント内容等）を同期する。

- `STATE_SNAPSHOT.snapshot`: 状態オブジェクト全体。受信側は既存状態を丸ごと置き換える。初回同期・再同期に使う。
- `STATE_DELTA.delta`: JSON Patch（RFC 6902）の操作配列。変更分のみを送る。
- 入力側 `RunAgentInput.state`: フロントエンドの現在状態が POST で運ばれてくる（双方向）。これは**信頼できない入力**であり、サニタイズが必要。

当初、この「サーバが保持する共有可変状態を delta 同期する」モデルは BEAR.Sunday / HATEOAS のステートレス原則と衝突するように見えた。しかしこの緊張は、**state を特別な「状態同期機構」とみなしたことによる偽の対立**である。

REST のステートレス制約が禁じているのは「サーバがクライアントの文脈を暗黙に覚えること」（HTTP セッション的状態）であって、「リソースが状態を持つこと」ではない。`threadId` で一意に識別され、URI で名前を持ち、表現を持ち、永続化されるものは、それ自体が REST 的に正当なリソースである。

### Decision
AG-UI の state を **`threadId` スコープの独立したリソース**として表現する（state-as-resource）。`STATE_*` イベントは、そのリソースに対する標準的な操作の結果として自然に導出される。

```
app://self/agui/run                       ← 最外リソース。会話実行をオーケストレート
  └─ app://self/thread/{threadId}/state    ← state リソース（ステートフル・永続化）
```

**状態の永続化**: state リソースが自身の状態を外部ストア（DynamoDB / libSQL 等、ADR 0005 の方針に従う）に保存・取得する。Swoole メモリには置かない（コンテナ再起動・水平スケール耐性のため）。これは「リソースが管理する状態をリソースが永続化する」という BEAR の通常設計そのものであり、AG-UI 固有の機構ではない。

**STATE_SNAPSHOT の生成**: state リソースの `onGet`（= 現在状態の完全表現）を `@Embed` し、その body を `STATE_SNAPSHOT.snapshot` に流す。再同期要求時、および run 開始時のベースライン確立時に発行する。

**STATE_DELTA の生成**: state リソースの `onPatch` が受け取った JSON Patch を、適用・永続化しつつ、同じパッチを `STATE_DELTA.delta` として echo する。差分を裏で再計算する必要はなく、**変更を起こした操作自身がパッチを宣言する**方式を採る（Pydantic AI の実装に倣う）。これにより delta 生成は正確かつ安価になる。

**入力 state の検証**: `RunAgentInput.state` の取り込みは state リソースへの書き込み（`onPut`）に対応づけ、サニタイズ（`@JsonSchema` による構造検証・サイズ制限・制御シーケンスのエスケープ）をこの一箇所に集約する。リソース化により、信頼境界の所在が明確になる。

### Consequences
- STATE系イベントが BEAR のリソースモデルに自然に溶ける。`STATE_SNAPSHOT` = リソースの完全表現、`STATE_DELTA` = その表現への JSON Patch、という対応が成立し、当初の「思想的緊張」は解消する。
- ステートフルさが state リソースに隔離され、最外リソース（`agui/run`）は純粋なオーケストレーションに保てる。
- 入力 state のサニタイズ（プロンプトインジェクション対策）が state リソースの書き込みパスに一元化される。
- 不整合検出時の再同期（フロントからの STATE_SNAPSHOT 要求）は、state リソースの `onGet` を再度呼ぶだけで実現できる。
- 当初検討していた「割り切り（立場A）vs ハイパーメディア的再解釈（立場B）」の二択は、state をリソースとして見ていなかったために生じた偽の対立だった。state-as-resource は実装の素直さと思想的一貫性（`STATE_DELTA` = リソース表現の部分更新を表すハイパーメディア制御）を同時に満たす。
- ALPS 駆動設計（ADR 0004）と組み合わせると、state リソースの表現も ALPS で記述でき、一貫した世界観になる。

---

## ADR 0004: ツール定義の供給源 — ALPS からの導出

### Status
Accepted（自前実装は不要に。BEAR.ToolUse PR #22 の `AlpsToolPolicyInputProcessor` /
`AlpsContextInputProcessor` を採用する方針に変更）

> **方針変更メモ**: 当初は `AlpsToolProvider` を自前実装する想定だったが、BEAR.ToolUse PR #22 が
> ALPS の descriptor 型（safe/idempotent/unsafe）でツールをフィルタする
> `AlpsToolPolicyInputProcessor`（`safeOnly()` / `safeAndIdempotent()` 等のファクトリ付き）と、
> ALPS セマンティクスを LLM コンテキストに注入する `AlpsContextInputProcessor` を本体に実装した。
> AG-UI 側はこれを `AgentOptions` に渡すだけでよく、自前実装は不要。下記 Decision の「single source
> of truth」の思想はそのまま PR #22 の実装で達成される。

### Context
`RunAgentInput.tools[]` でクライアントが利用可能なツールを宣言し、エージェントは `TOOL_CALL_START` / `TOOL_CALL_ARGS` / `TOOL_CALL_RESULT` でその呼び出しをストリームする。ツール定義（名前・引数スキーマ）をどこから供給するかが論点。

BEAR.ToolUse は ALPS 駆動のエージェントランタイムとして設計されており、ALPS の状態遷移記述からツール（アフォーダンス）を導出する基盤を既に持つ。

### Decision
ツール定義を **ALPS プロファイルから生成する Provider** を（オプションとして）提供する。ALPS の `descriptor`（semantic / safe / idempotent / unsafe）を AG-UI ツールスキーマにマッピングし、`tools[]` および `TOOL_CALL_*` イベントの語彙を ALPS から一貫導出する。

これにより「情報中心設計（ALPS）→ エージェントのアフォーダンス → AG-UI イベント」という単一の真実の源（single source of truth）が成立する。

### Consequences
- ツール定義とリソース設計が ALPS で一元化され、ドリフトを防げる。
- ALPS → AG-UI のマッピング規則自体が新たな仕様となり、その妥当性検証（ALPS の表現力と AG-UI ツールスキーマのギャップ）が必要。
- ADR 0003 の立場Bと組み合わせると、思想的に最も筋の通った構成になる（ALPS = 情報、AG-UI = その情報の実行時表現）。

---

## ADR 0005: トランスポートとデプロイ（SSE、Swoole、AgentCore コントラクト）

### Status
Accepted（SSE 先行を採用。`SseSinkInterface` で出力プリミティブを抽象化し、CLI/FPM 実装を検証済み。
Swoole 実装と AgentCore 実機デプロイは本配線で残る）

> **検証メモ（プロトタイプ）**: 出力プリミティブ（write+flush）を `SseSinkInterface` に抽象化し、
> `PhpSapiSseSink`（echo + flush + バッファ無効化ヘッダ）を実装。`php -S` で逐次配信を確認した。
> Swoole 環境では `SwooleSseSink implements SseSinkInterface` を1つ追加し、`echo/flush` を
> `$response->write()` に置換するだけで `SseResponder` / `AgUiAdapter` は無変更で動く設計とした。
> `/ping` ヘルスチェックも実装・確認済み。

### Context
AgentCore の AG-UI コントラクトは以下を要求する:

- **Container**: Host `0.0.0.0` / Port `8080` / **ARM64**
- **Path**: `POST /invocations`（SSE ストリーム）、`GET /ping`（ヘルスチェック、`{"status":"Healthy", ...}`）
- **Session**: プラットフォームが `X-Amzn-Bedrock-AgentCore-Runtime-Session-Id` ヘッダを自動付与
- **Auth**: OAuth 2.0 Bearer / SigV4。OAuth エラーは `WWW-Authenticate` ヘッダ（RFC 7235）で discovery 可能

AG-UI 仕様自体はトランスポート非依存（SSE デフォルト）。

### Decision
- **第一段階は SSE + `/invocations`** をサポート対象とする（最も firewall-friendly で実装が単純、AgentCore の主経路）。
- Swoole HTTP Server をランタイムとし、`write()` ループでチャンク送信、coroutine でツール並列実行。既存の BEAR.ToolUse / Swoole + ARM64 構成をそのまま流用する。
- `/ping` は軽量な独立リソースとして実装（DI コンテナ初期化と切り離す）。
- セッションは AgentCore 付与のヘッダを信頼境界として扱い、`threadId` との対応付けを行う（セッション状態の保管先は別途決定 — Swoole メモリではなく外部ストアを推奨、過去の authorization code を DynamoDB に置いた判断と同種）。
- 認証は AgentCore の OAuth/SigV4 に委譲し、アプリ層では `Authorization` ヘッダの検証結果を前提に動く。OAuth discovery の `WWW-Authenticate` 応答は接続レベルエラー（ADR 0001）として HTTP 401 で返す。

### Consequences
- SSE 先行により最小実装で AG-UI 準拠を達成できる。
- Swoole coroutine とツール並列実行の既存設計が AG-UI のツールイベントストリームと自然に接続する。
- セッション状態を Swoole メモリに置かない方針は、コンテナ再起動・水平スケールに対する堅牢性を担保する。

---

## ADR 0006: ToolUse AgentEvent → AG-UI イベント変換アダプタの設計

### Status
Accepted（standalone プロトタイプで検証済み）

### Context
ToolUse（PR #22）の `StreamingAgentInterface::runStream()` は
`Generator<int, AgentEvent, mixed, void>` を返す。`AgentEvent` は高レベルの6種
（`text_delta` / `tool_start` / `tool_result` / `completed` / `confirmation_required` / `error`）。
一方 AG-UI は16種のイベントと、メッセージ境界・ライフサイクル境界を要求する。両者の差分を
埋めるアダプタが必要。プロトタイプで以下を確定した。

### Decision
**ToolUse 本体は無改造**とし、`AgUiAdapter` が `Generator<AgentEvent>` を入力に取り
`Generator<AgUiEventInterface>` を出力する変換層とする。アダプタは差分を「境界生成の
ステートマシン」として実装する。

- **ライフサイクル境界**: run の前後で `RUN_STARTED` / `RUN_FINISHED` を1個ずつ生成。
  例外時は `RUN_ERROR`（HTTP は 200 のまま、ADR 0001 の二分法）。
- **メッセージ境界**: ToolUse は `text_delta` しか出さないため、最初の text_delta の前に
  `TEXT_MESSAGE_START`（messageId 採番）、非テキストイベントや run 終了の前に
  `TEXT_MESSAGE_END` を合成する。tool を挟むと message が閉じ、その後のテキストは
  新しい messageId で開き直す。
- **INTERRUPT の双方向**: `confirmation_required` を `INTERRUPT` として yield し、
  呼び出し側が `send(bool)` で返した承認/拒否を、下層の ToolUse 生成器へ `send()` で中継する
  （ToolUse は `send(false)` でツール呼び出しをキャンセルする設計）。

### Consequences
- 変換ロジックは `AgentEvent` の型・定数のみに依存し、ToolUse 全体や LLM バックエンドに
  依存しない。プロトタイプではスタブで検証し、本配線では `use BEAR\ToolUse\Runtime\AgentEvent`
  に差し替えるだけでアダプタは無変更で bind する。
- Generator 3段パイプ（ToolUse → adapter → responder）が遅延ストリーミングすることを
  検証済み（生成と書出しが interleave）。
- **未解決**: `tool_start` が `toolName` しか持たず `TOOL_CALL_ARGS`（引数ストリーミング）を
  出せない。引数が必要なら低レベル `StreamEvent::TOOL_USE_DELTA` まで降りる必要がある。
  また複数ツールの連続/並行時の toolCallId 対応づけ（現状は `lastToolCallId` の単純保持）は
  実 `runStream()` を繋いで詰める必要がある。

---

## パッケージ最小構成（まとめ）

実装名はプロトタイプ（`bear-agui/`）に準拠。

| コンポーネント | 役割 | 状態 | 関連 ADR |
| --- | --- | --- | --- |
| `Input\RunAgentInput` (+ @JsonSchema) | 入力境界・検証 | プロトタイプ実装済 | 0001 |
| `Event\AgUiEventInterface` + イベント値オブジェクト | イベントの型表現 | 主要分実装済 | 0002 |
| `Adapter\AgUiAdapter` | AgentEvent→AgUiEvent 変換 | プロトタイプ実装済 | 0006 |
| `Sse\SseEncoder` | イベント→`data:{json}\n\n` | プロトタイプ実装済 | 0002 |
| `Sse\SseSinkInterface` + `PhpSapiSseSink` | write+flush 抽象と CLI/FPM 実装 | プロトタイプ実装済 | 0005 |
| `Sse\SseResponder` | Generator を逐次 write | プロトタイプ実装済 | 0002 |
| `Sse\SwooleSseSink` | Swoole 用 write 実装 | **未** | 0005 |
| `AgentOptions` + `AlpsToolPolicyInputProcessor` | ALPS ツール制御 | **PR #22 を採用（自前不要）** | 0004 |
| `ThreadStateResource`（`thread/{threadId}/state`） | state の永続化・snapshot/delta | **未** | 0003 |
| `/ping` リソース | ヘルスチェック | プロトタイプ実装済 | 0005 |
| BEAR リソース + SSE Transfer 結線 | `onPost`→Generator body→通常レンダラbypass | **未（要実BEAR）** | 0002 |

## 未解決論点（今後の ADR 候補）

- **[最優先]** `$ro->body` に Generator を入れ、通常レンダラをバイパスして SseResponder まで
  生で届ける BEAR 結線（`StreamedResourceObject` ないし専用 Transfer バインディングの要否）
- 実 `runStream()` 接続で見える変換の穴: TOOL_CALL_ARGS 引数ストリーミング（低レベル
  StreamEvent 経由）、複数ツール時の toolCallId 対応づけ
- STATE_SNAPSHOT / STATE_DELTA の実装（ADR 0003: state-as-resource）
- マルチターン会話のセッション状態ストア選定（DynamoDB / libSQL）— state リソースの永続化先と統一すべきか
- state リソースの並行更新（エージェントとフロントの同時書き込み）の競合解決戦略
