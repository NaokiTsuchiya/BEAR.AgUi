# 決定ログ（Decision Log）

実装を進める中で確定した判断の記録。設計の大方針は [`adr/`](adr/0000-0006-ag-ui-support.md)、
全体像は [`architecture.md`](architecture.md)、進め方は [`milestones.md`](milestones.md)。
ここには「実装レベルで何をどう決めたか」を時系列で残す。

凡例：`確定` = 合意済み / `保留` = 未決 / `却下` = 採用しない。

---

## スコープ・基盤

- **D1 `確定` スコープ = AG-UI アダプターライブラリ**。`bear/tool-use` のエージェント出力（`AgentEvent`）を
  AG-UI プロトコル（イベント + SSE 直列化）へ変換する部分のみ。**BEAR.Sunday には依存しない**
  （依存は `bear/tool-use` のみのフレームワーク非依存ライブラリ）。AgentCore デプロイ・state-as-resource・
  ALPS 供給はスコープ外（アプリ/ToolUse 側の関心事）。
- **D2 `確定` namespace = `NaokiTsuchiya\BEARAgUi\`**（既存 composer 設定を踏襲）。**1クラス1ファイル**で配置。
- **D3 `確定` `bear/tool-use` を require**。PR #22 = ブランチ `dev-codex/review-hardening`（packagist:
  `dev-codex/review-hardening`）。**PHP は `^8.2` 据え置き**（既存 composer.json / `bear/tool-use` と同じ）。
  オープン PR ブランチ pin のため揺れる前提。タグが切られ次第差し替え。
  - 補足（当初の誤り訂正）：「`#[Override]` が 8.3+ だから ^8.3 必須」は誤り。`#[Override]` は 8.2 では
    no-op 属性で fatal にならない（reflect しない限り評価されない／override チェックは 8.3+ のみ）。
    `bear/tool-use` 自身が `^8.2` で `#[Override]` を使用している。よって ^8.2 で揃える。
- **D7 `確定` example は2種**：①素 PHP HTTP サーバ（フレームワーク非依存・**結合テスト対象**）、
  ② BEAR.Sunday ショーケースアプリ。テストは **LLM 不要の scripted StreamingAgent** で決定論的に回す。

## interrupt / confirmation

- **D4 `確定` interrupt は v1 非必須**。AG-UI の interrupt は「run 終了 + 新 run + `resume[]`」のターミナル
  モデルで、ToolUse の `send(bool)` in-process モデルとは写像不能。v1 は `confirmation_required` →
  `RUN_FINISHED{outcome:interrupt}` で **run を終了**（ツール非実行＝安全）。**resume は未対応だが前方互換**。
  本物の interrupt は ToolUse 側に resume 再入 API が入り次第（→ [`feedback/tool-use-resume.md`](feedback/tool-use-resume.md)）。
- **D6 `確定` 偽 `INTERRUPT` イベントは削除**。AG-UI に `INTERRUPT` イベントは存在しない。
  `RunFinished` に `outcome` フィールドを追加して表現する。
- **D5 `確定` WebSocket はサポートしない**。AG-UI は SSE デフォルトで成立。ADR/docs から記述も削除済み。

## AG-UI イベント仕様（実スキーマ照合済み）

- **D8 `確定` イベントのフィールドは公式スキーマに準拠**（poc を鵜呑みにしない）。照合結果：
  - `ToolCallEnd` は `ToolCallResult` の**前に必須**（順序 Start→[Args]→**End**→Result）
  - `ToolCallResult.messageId` は**必須**
  - `RunFinished.outcome` =（`{type:"success"}` | `{type:"interrupt",interrupts:[]}`）、`result` は root に optional
  - `RunError`：`message` 必須・`code` optional
  - 共通：`type`・`timestamp`(optional)・`rawEvent`(optional)。wire `type` は SCREAMING_SNAKE
- **D-ToolUse `参考` ToolUse 側への要望**を [`feedback/tool-use-resume.md`](feedback/tool-use-resume.md) に記録
  （ステートレス resume 再入 API、高レベル `AgentEvent` への `toolCallId` 付与）。

---

## M0 詳細決定（議論中）

- **D9 (Q1) `確定` 並行/複数ツールを FIFO キューで正しく対応**。poc の単一スロット `lastToolCallId` を、
  synthesize した `toolCallId` の **FIFO キュー**に置換する（`tool_start` で enqueue、`tool_result` で
  dequeue）。ToolUse の dispatch は pending を開始順に処理し結果も開始順に来る（実コードで確認）ため、
  FIFO で start↔result が正しく対応づく。1ターン複数 tool_use（Claude の並行ツール呼び出し）でも崩れない。
  - 却下：案② 単一ツール前提で `lastToolCallId` のまま受容（将来必ず踏むバグを仕様化するだけ）。
  - 補足：D10 採用後は synthesize id ではなく**レジストリ由来の実 id** を FIFO 順で対応づける（相関は
    高レベル `AgentEvent` のタイムライン ↔ レジストリ登録順）。

- **D10 (Q2) `確定` ツール情報の不足は ToolUse 無改造の「デコレータ + レジストリ」代替実装で補う（Tier 2）**。
  `AgentEvent` は `toolCallId` / 引数 / 結果 `content` を捨てているため、AG-UI の `TOOL_CALL_*` を正しく作れない
  （現状 `TOOL_CALL_RESULT.content` がツール名になる実害あり）。ソース patch は却下（オープン PR ブランチで
  force-push の度に壊れる）。代わりに ToolUse の公開注入点をデコレートして不足データを横取りする：
  - `ToolCallRegistry`：横取りしたデータ（実 id / input / content / isError）を保持
  - `RecordingDispatcher`（`DispatcherInterface` デコレータ）：`dispatch()` から id + input + content を記録
  - `RecordingStreamingLlmClient`（`StreamingLlmClientInterface` デコレータ）：`TOOL_USE_START` から
    **tool 開始時に実 id を早期取得**（Tier 2 = 早期 `TOOL_CALL_START` を発火）
  - Adapter は `Generator<AgentEvent>`（タイムライン）＋ `ToolCallRegistry`（不足データ）を受けて enrich
  - **撤去条件**: ToolUse が `tool_start`/`tool_result` をエンリッチしたら（→ feedback）デコレータを削除
  - 却下：Tier 1（Dispatcher デコレータのみ・`TOOL_CALL_START` が tool 完了時発火）。AG-UI はストリーミング
    UI が主眼で「呼び出し中」の早期表示が要るため Tier 2 を採用。

- **D11 (Q3) `確定` `RunError` は固定ポリシー（案A）で開始、必要時に差し替え式（案C）へ非破壊昇格**。
  クライアント（SSE→ブラウザ）には **汎用 `message`** + 安定 `code` `"AGENT_ERROR"` のみを出す。実例外は
  注入した logger（PSR-3 optional、無ければ no-op）へ。`$e->getMessage()` の素通しは情報漏洩のため却下。
  - 検証エラーは HTTP 400（別経路）なので RunError の code は当面 `AGENT_ERROR` 一本でよい。
  - 将来 code を例外型ごとに分けたい等の要求が出たら、固定ロジックを `DefaultRunErrorMapper` に抽出して
    `RunErrorMapperInterface` を切る（案C）。今は YAGNI で IF を足さない。

- **D12 (Q4) `確定` `completed.fullText` は捨てる**。テキストは `text_delta` → `TEXT_MESSAGE_CONTENT` で
  逐次送信済みのため、`AgentEvent::completed` は「開いているメッセージを閉じる」境界としてのみ使い、`fullText` は
  `RunFinished.result` に載せない（全文二重送信を避ける）。クライアントは `TEXT_MESSAGE_CONTENT` の結合で
  最終文を再構築できる。`result` が必要になれば後から非破壊で追加可能。

- **D13 (Q5) `確定` テストは Fake を下位境界に下げ、実 `StreamingAgent` を回す**。`FakeStreamingAgent`
  （ToolUse の振る舞いを模倣）は書かない。代わりに `FakeStreamingLlmClient`（scripted `StreamEvent`）＋
  `FakeDispatcher`（scripted `ToolResult`）を注入して**実 `StreamingAgent::runStream()` に本物の AgentEvent 列を
  生成させる**。理由：手書き Fake は ToolUse（動くオープン PR ブランチ）の思い込みになり、ToolUse が変わっても
  緑のまま乖離する。実コードを回せば乖離時に契約テストが落ちて気づける。Fake 位置を LLM ワイヤ（`StreamEvent`、
  D10 デコレータがどのみち読む契約）に下げることで、本番と同じ配線（デコレータが実 IF を包む）を検証できる。
  - テスト二層：(1) **Adapter ユニット**＝単純な `Generator<AgentEvent>` を直接流す（ToolUse 非依存）。
    (2) **契約/結合**＝実 StreamingAgent + Fake LLM/Dispatcher + デコレータ + Adapter の全鎖。
    シナリオ：①テキストのみ ②単一ツール ③並行ツール（D9）④confirmation（→interrupt）⑤実行中エラー。
  - 遅延性：poc `verify.php` RUN3 を移植し、生成 vs write の interleave を spy で順序検証。

- **D14 `確定`（形は D23 で改訂）`AgUiRunner` は組み上げ済み agent ではなく `InstrumentedAgentFactory` を受ける**
  （アーキテクチャ精緻化で判明 → [architecture](architecture.md) §4-5）。`StreamingAgent` は final で依存が
  private のため、エンリッチ用デコレータ（D10）を**後付けできない**。よって「agent を作る前に依存を包む」＝
  factory がエンリッチ配線を担う形にする。
  - `InstrumentedAgentFactory::create(ToolCallRecorder): OptionAwareStreamingAgentInterface`（IF）＋
    素の `StreamingAgent` 用 `DefaultInstrumentedAgentFactory`（既定実装）を同梱。AgentFactory/AgentPool を
    使うアプリは IF を自前実装して同じ配線にできる。
  - `ToolCallRegistry` を **read/write の 2 IF に分離**：`ToolCallRecorder`（デコレータ＝書）/ `ToolCallView`
    （Adapter＝読）。同一実体を二面で渡す（ISP）。
  - スコープ：app 単一＝factory/encoder/logger・実 LLM/Dispatcher、per-request＝`SseSinkInterface`、
    per-run＝registry/agent/adapter。run をまたいで状態共有しない。
  - 補足（D15）：`create()` は history 引数も取る → `create(ToolCallRecorder, array $history)`。

## M1 詳細決定

- **D15 (Q-M1-1) `確定`（名称は D23 で改訂）マルチターン会話履歴は repo 側カバーで対応**（feedback と両建て・enrichment と同パターン）。
  - 前提整理：AG-UI は毎 run で `messages[]` を全送（履歴の正本はクライアント／サーバはステートレス）。
    **ReAct ループ自体は単一 run 内で完結**（ToolUse の `runStream` が reason/act/observe を回す）。multi-turn が
    足すのは「会話メモリ」で、AG-UI プロトコルとは**別レイヤー**（保管＝クライアント/app、再構成＝本ライブラリの
    `Input` 境界）。`messages[]` が毎回来るのでサーバ側に状態ストアは不要。
  - 実装：`MessageHistoryMapper`（`…\ToolUse\` namespace＝tool-use 結合ゾーン）が `messages[]`（**最後の user を
    除く**）→ `list<BEAR\ToolUse\Runtime\Message>` に再構成。`UserMessage`→`user`、`AssistantMessage(content+
    toolCalls)`→`assistant([text + tool_use blocks])`（`function.arguments` JSON を decode）、**連続する
    `ToolMessage`→1つの `Message::toolResults([...])` にグルーピング**、System/Developer/Activity/Reasoning は skip。
  - seed：`StreamingAgent.$messages`（public）へ代入。配線は D14 factory に history 引数を追加し、
    `DefaultInstrumentedAgentFactory` が `$agent->messages = $history` を行う。
  - **制約：全再構成（tool_use ↔ tool_result をペアで）**。テキストだけ等の部分履歴は「結果の見えないツール
    呼び出し」を生み ReAct を壊すため不可。テストはペア・グルーピング・並行ツールの整合を重点検証。
  - feedback：`public $messages` 直叩きは非公開契約のため、ToolUse に「**履歴を seed する正式 API**」
    （例 `runStream(array $history, string $userMessage)` / `withHistory(Message[])`）を要望（→ feedback doc）。
    API 後は seed 手段のみ差し替え、mapper（AG-UI→ToolUse 変換）は残る。
  - スコープ：multi-turn を後続から **M1 に格上げ**。

- **D16 (Q-M1-2) `確定`（形は D23 で改訂）未宣言ツール名は lenient 交差で扱う（エラーにしない）**。
  - AG-UI の `tools[]` はクライアント提供のツール定義で、**client-side tool（フロント実行）を含みうる**＝
    サーバに無い名は正常。strict に HTTP 400 / RUN_ERROR を返すと標準 AG-UI クライアントを壊すため却下。
  - **`enabledTools = declaredToolNames() ∩ factory.knownToolNames()`**。未知名は黙って除外（debug ログ）。
    → `withTools` に未知名が渡らず `InvalidArgumentException` も起きない（Q-M1-2 の「400 か RUN_ERROR か」が消える）。
  - 空宣言（`tools[]=[]`）→ `enabledTools = null`（フィルタしない。ALPS ポリシー safeOnly 等が露出を統治）。非空→交差。
  - **エラー二分法は維持**：構造不正の入力＝HTTP 400（`fromJson`）、実行中のツール失敗＝`RUN_ERROR`（HTTP 200）。
    未知ツール名は「エラー」ではなく「未サポートの client-side tool」として扱う。
  - seam：`InstrumentedAgentFactory::knownToolNames(): list<string>` を追加（`DefaultInstrumentedAgentFactory`
    は `$this->tools` から1行）。
  - **client-side tool 実行は v1 非対応**（宣言されてもサーバは実行しない）と明記。

- **D17 (Q-M1-3) `確定`（形は D23 で改訂）`UserMessage.content` は text 抽出で正規化、マルチモーダル入力は v1 非対応**。
  - `string`→そのまま。`InputContent[]`→ `type:"text"` パートのみ `\n` 連結、非テキスト（image/file）は**除外**
    （debug ログ）。抽出結果が空（text パート無し / `[]`）→ **HTTP 400**（接続前検証）。
  - 理由：降ろし先 ToolUse の `Message::user` / `runStream` が **text-only**。マルチモーダルは ToolUse 側の
    マルチモーダル `Message` 対応が前提（低優先 feedback 候補・resume/history/enrichment より下）。
  - 置き場所：`content → text` 抽出は純 AG-UI（tool-use 非依存）なので `Input/` 層の共有ヘルパー
    （例 `UserContent::toText(string|array): string`）。`lastUserMessage()` と `MessageHistoryMapper`（D15）で再利用。
  - ⚠️ 実装時に `InputContent` の判別子（`type:"text"` と text フィールド名）を AG-UI 型定義で確認。

- **D18 (M2) `確定` example の LLM クライアントは `openai-php/client ^0.20`**（OpenAI 互換）。
  - 理由：OpenAI 互換＝`OPENAI_BASE_URL` 差替で OpenRouter/Ollama/vLLM/Groq 等に届く。streaming + `tool_calls` delta
    を同経路で対応（検証済み）。依存 7・MIT・de facto 標準。`require-dev` に閉じ本体 `require` を汚さない（`crc` 不変）。
  - エンドポイントは AgentCore 規約踏襲の `POST /invocations` + `GET /ping`（AG-UI 自体は URL 未定義）。**LLM 切替は
    `OPENAI_BASE_URL` env のみ**（`?mode=` クエリは廃止＝AG-UI 表面に裏口を作らない）。env は `OPENAI_*` prefix
    （`OPENAI_API_KEY` / `OPENAI_BASE_URL` 既定 `https://api.openai.com/v1` / `OPENAI_MODEL` 既定 `gpt-4o-mini`）。
  - 不採用：Symfony AI Platform（experimental・BC 保証なし・依存大）。M3 で多プロバイダ抽象の例として再検討可。
    orhanerday/open-ai（streaming + tool_calls 未対応）。
  - **実装補記（M2 実装時）**：openai-php は HTTP トランスポート非同梱（PSR-18 discovery）のため
    **`guzzlehttp/guzzle` も `require-dev` に追加**（無いと実 HTTP 経路が run 内 `RUN_ERROR` になる）。streaming の
    自動対応（stream handler 内蔵）は Guzzle / Symfony クライアントのみで、他の PSR-18 実装は `withStreamHandler()`
    の明示配線が要る（テスト fake は `tests/Support/OpenAiClientBuilder` がこれを担う）。

- **D19 (M2) `確定` OpenAI delta → bear `StreamEvent` は state machine 変換**。
  - open block（`none`/`text`/`tool(index)`）を追跡し、境界で `CONTENT_BLOCK_STOP` を差し込む。`delta.content`→`TEXT_DELTA`、
    `tool_calls[].id` 初出→`TOOL_USE_START`、`tool_calls[].function.arguments`→`TOOL_USE_DELTA`、`finishReason`→
    `MESSAGE_STOP`。
  - finish_reason マッピング：`tool_calls`/`function_call`→**`tool_use`**（唯一クリティカル＝ループ継続トリガ）、
    その他（`stop`/`length`/`content_filter`）→`end_turn`。根拠：`StreamingAgent` は `tool_use`+pending 以外を
    terminal complete に落とす（コード確認済み）。
  - ⚠️ 並行ツールは**順次のみ**対応（index 跨ぎ arguments interleave は非対応）。bear `StreamContentAccumulator` 自体が
    単数 `currentToolId`＝同じ制約。OpenAI は実際には順次送出するため実用上問題なし。README に明記。
  - **実装補記（M2 実装時・truncation ガード）**：`finish_reason` チャンク無しで SSE が終端（切断）した場合は
    throw → `RUN_ERROR`。bear の accumulator は stopReason 既定 `end_turn` のため、ガード無しだと途中切断が
    `RUN_FINISHED{success}` に化ける（D23 の二分法違反）。OpenAI 契約の知識なのでこの変換層が担う。

- **D20 (M2) `確定` bear `Message` → OpenAI request 変換**。
  - `$system` 非空→先頭 `{role:system}`。`user`+text→`{role:user, content}`。`assistant`→`{role:assistant, content,
    tool_calls:[{id, type:function, function:{name, arguments: json_encode(input)}}]}`。
  - ⚠️ bear の `user` ロールは **2 用途**（通常 text / tool_result）。`content[].type` に `tool_result` を含むかで判別し、
    tool_result は `{role:tool, tool_call_id, content}` 複数メッセージへ展開。`ToolResult.content`（mixed）は非 string を
    `json_encode`（OpenAI tool content は string 必須）。

- **D21 (M2) `確定` example は OpenAI 互換スタブ LLM を同梱**（`example/stub-llm/`）。
  - `POST /v1/chat/completions` **単一エンドポイント**（openai-php は他 endpoint を叩かず起動時 preflight も無し・確認済み）。
    `Authorization` は値を見ない（任意キー許可）。
  - 受信 `messages` 末尾 `role` でターン判定する**単一 canned 会話**：ターン1（`role!=tool`）＝text + `get_time` tool_call
    （arguments を 2 チャンクに分割）+ `finish_reason:tool_calls`／ターン2（`role==tool`）＝**受信 tool_result を echo** した
    最終テキスト + `finish_reason:stop`。API キー不要で happy path 全周を再現。
  - SSE 直列化は本体 `Sse/` を流用せず stub 独自の `echo+flush`。チャンク遅延は `STUB_DELAY_MS` env（既定 `0`）。
  - `get_time` は実時刻（決定論不要＝結合テストは Fake 経由でこの経路を通らない）。interrupt は real 経路（model が
    confirmable ツール `ask_confirmation` を選択）でのみ発現＝スタブ happy path とは排他。

- **D22 (M2) `確定` M2 結合テストは HTTP を起こさない**。
  - `AgUiRunner` をプロセス内で `tests/Fake`（D13）+ recording `SseSinkInterface` で駆動し SSE フレーム列（順序・ペアリング・
    error/interrupt outcome）を検証。HTTP spawn（`php -S` + socket）はポート race・起動コスト・本番非再現で避ける。
  - HTTP/SSE の本番逐次配信は `php -S`（cli-server）では本番（fpm+nginx のバッファ）を再現できないため**手動 smoke** に降格。
    時計依存の自動テスト（受信時刻差アサート）は Fake の人工遅延を測るだけで不変条件を検証せず flake 源＝**置かない**。
  - openai-php wrapper（D19）のユニットは、openai-php `withHttpClient()` に **stub `CannedConversation` をプロセス内で呼ぶ
    PSR-18 fake** を注入し、実 SSE パース経路を HTTP 無しで通す（stub を単一ソースに再利用）。

## M1 精緻化（実装後のパイプライン再設計・commit `62ff904`）

- **D23 `確定` run パイプラインを「生成（runner）／レンダリング（host）」に分離し、協力者を app 単一ステートレスへ**。
  M1 実装後の設計議論で D14〜D17 の具体形を以下へ精緻化した（方針は不変、**形のみ改訂**）。
  古い記述（`architecture.md` §5 の `run(input, sink): void`／sink の `open/write/close`／adapter・responder の
  per-run 構築／parse の例外 throw）は本決定で**置き換え**。
  - **入力境界は純データ + 総関数パーサ**：`RunAgentInput` は**メソッドを持たない純データ**
    （`threadId, runId, userMessage, history, declaredToolNames, context, state, forwardedProps, resume`）。
    導出（trigger 分離・履歴・tools 射影・content→text）は唯一のビルダー `RunAgentInputParser::parse(string):
    RunAgentInput|ParseError` が全て担う。**throw しない総関数**で、接続レベル失敗は `ParseError`（host が HTTP 400 へ）。
    → D16/D17 の `fromJson`（例外）/`lastUserMessage()`/`declaredToolNames()`/`UserContent::toText` は廃止。
    導出ロジックは `Input/Parser/` の per-VO パーサ群へ分解。
  - **runner は生成のみ**：`AgUiRunner::stream(RunAgentInput): iterable<AgUiEventInterface>`。SSE 化・I/O・
    HTTP ステータス写像は host の関心事。host が枠付ける：`$responder->respond($runner->stream($input), $sink)`。
    → D14 の `run(RunAgentInput, SseSinkInterface): void` を置き換え。
  - **協力者は app 単一ステートレス、run/request 固有値はメソッド引数**：
    - `AgUiRunner` ctor＝`(InstrumentedAgentFactory, MessageHistoryMapper, AgUiAdapter, list<InputProcessorInterface>)`。
      encoder/logger は直接保持しない（adapter/responder が持つ）。
    - `AgUiAdapter`：ctor`(LoggerInterface)`、`run(Generator, threadId, runId, ToolCallView): Generator`。per-run 構築廃止。
    - `SseResponder`：ctor`(SseEncoder)`、`respond(iterable, SseSinkInterface): void`。SSE ヘッダ集合を所有。per-request 構築廃止。
    - per-run で作り捨てるのは **registry / デコレータ / agent** のみ。→ D14 の「per-run＝registry/agent/adapter」を改訂。
  - **sink は単一 `send()` に集約**：`SseSinkInterface::send(array $headers, iterable $frames): void`（open/write/close 廃止）。
    順序（status+headers→frames→end）を内部に隠蔽し誤順序・`headersSent` 状態を排除。`PhpSapiSseSink` が同梱実装。
  - **factory のメソッド名・既定実装名を確定**：`InstrumentedAgentFactory::newInstance(ToolCallRecorder, list<Message>)`
    （旧 `create`）、既定実装は `StreamingAgentFactory`（旧 `DefaultInstrumentedAgentFactory`）。→ D14/D15/D16 の名称を改訂。
  - **エラー二分法は不変**：parse/接続失敗＝`ParseError`→HTTP 400（`stream()` 前）／実行中失敗＝`RUN_ERROR`（開いた 200 上）。

- **D24 `確定` パースエラーを集約し、Result 化は spike ゲート後段で**（task list #10・ユーザー明示要望）。
  旧パーサは `T|ParseError` のユニット返却で **fail-fast**（最初の `ParseError` で打ち切り）だった。grilling で
  設計を以下に締め、`feature/d24-parse-aggregation` ブランチで **A→B を実装済み**（末尾「実装」参照）。
  - **集約スコープ = level i + ii（到達可能な独立兄弟は全部）**：`threadId`/`runId` +
    `messages`/`tools`/`context`/`resume` の4リストを*全部*走らせ、各 ParseError を path 付きで連結する（ii）。
    さらに**各エントリ内の独立フィールドも集約**（i）：1つの message/tool が複数フィールドを欠いていれば
    まとめて返す（例 activity の `activityType` と `content` の両方）。短絡は2箇所と**エントリ内の依存サブ
    チェック**だけ：`decode`（JSON 壊れ=1件で停止）/ `splitTrigger`（messages が綺麗にパースできた時のみ）/
    依存関係（例 `content must be a string-keyed object` は content 存在が前提）。`mapList` は最初の
    `ParseError` で `return` せず全エントリ分を収集。
    - **(i) は後から追加**：当初は ii 限定だったが、`ActivityMessageParser` 等が複数独立フィールドを短絡して
      いたため (i) も入れた。実装上は **leaf の error 型を `Result<T, list<ParseError>>` に統一**（leaf も
      orchestrator も `list<ParseError>`＝混在解消）し、複数フィールド parser は no-else の累積 +
      narrowing ガード（`if ($errors !== [] || $a === null || ...) return Result::err($errors);`）で組む。
  - **価値の主体（正直版）**：不正な AG-UI クライアントをデバッグする**開発者の DX** ＋ protocol エラー面の
    見通し。「機械クライアントが N 件まとめて自動修正する」とは主張しない（生成元は機械でフォーム入力者では
    ないため）。fail-fast か集約かは YAGNI ではなく**エラーハンドリング方針**の選択として集約を採る。
  - **2ステップに分割（A → B）**：
    - **(A) error 型を単数→複数化 + 集約**：各 parser を `T | list<ParseError>` 返しにする。**ユーザーが欲しい
      集約はここで実現**し、低リスクで先に出せる（generic Result 非依存）。
    - **(B) 汎用 `Result<T,E>` ラッパーを (A) の上に被せる**：ad-hoc ユニオンに「失敗しうる計算」という単一語彙を
      与える趣味判断。**(A) の後段**でのみ実施。
  - **B のゲート = mago の generics / assert 対応を spike で実測（→ 実測済み・GREEN）**：当初「mago は `Result` を
    ナローできず無チェック `unwrap` 散乱でユニオン下位互換」と懸念したが、spike（`@template` な `Result<T>` +
    `@psalm-assert-if-true`）で **mago は (1) ジェネリクスを `Result<T>->unwrap()` 経由で貫通させ（`string` を
    `int` 引数へ渡すと検出）、(2) `@psalm-assert-if-true` で `string|int`→`string` にナローする**ことを確認。
    よって B は技術的に viable。残課題は `unwrap()` を Err 上で呼ぶ実行時安全のみ（Ok/Err 分割等で設計時に詰める。
    ゲート不成立ではない）。
    - **実装時の追加知見（covariance 必須）**：2引数 `Result<T,E>` を実コードに入れる段で、mago は型引数が
      **invariant** のため `ok()`（`self<V, never>`）/`err()`（`self<never, F>`）ファクトリが宣言 `Result<T,E>` に
      **unify しない**と判明（spike2）。`@template-covariant T` / `@template-covariant E` を付けると never が
      bottom として unify し、ファクトリ成立 + `Result<UserMessage, ParseError>` が `Result<Message, ParseError>`
      契約を満たす。よって `Result` は両引数 covariant で定義。`unwrap()` 経由のジェネリクス追跡は維持される。
  - **`ToolOutcome` は統合対象外**（当初文面 (a) から除外）：`ToolOutcome` は成功/失敗で別ペイロードの和型では
    なく、両ケースで `content` を持つ**判別子つき積型**（[`Input/Message/ToolOutcome.php`](../src/Input/Message/ToolOutcome.php)
    の `failure(content, error)`）。和型 `Result<T,E>` に入れると「失敗でも content」不変条件が壊れる。除外根拠は
    「形」であって「parse error でないから」ではない（generic Result の E は何でもよい）。
  - **未決（host 側＝M2/M3 で確定）**：複数エラーの **400 レスポンスエンベロープ**。AG-UI 仕様に複数エラー定義は
    無く独自拡張になる。ライブラリは `parse()` が `list<ParseError>`（各 `message` + path）を返すところまで担い、
    形（例 `{code, errors:[{path, message}]}`）への直列化は host の関心事（D25 の `Invocations::transfer()` が
    配列分岐で 400 を返す経路）。
  - **実装（`feature/d24-parse-aggregation`・3+1 コミット）**：A=`Aggregate input parse errors instead of
    failing fast`(ii)、B=`Introduce Result<T,E> and replace the parser unions`、
    `Aggregate independent field errors within each entry (level i)`。`Input\Result<T,E>`（covariant 両引数・
    ok/err/isOk/unwrap/unwrapErr）を追加し、leaf パーサ全てを `Result<T, list<ParseError>>`、`parse()` を
    `Result<RunAgentInput, list<ParseError>>` に。集約テスト3本（独立兄弟跨ぎ / リスト内跨ぎ / エントリ内）。
    pin 版 mago 1.40.1 で `composer tests`（84 tests / 313 assertions）＋ `composer crc` グリーン。

- **D25 (M3) `確定` BEAR アプリの入口は ResourceObject、SSE 配送は `Invocations::transfer()` オーバーライド**（T0 スパイクで
  実機確認済み）。`/invocations` を薄いハンドラに逃がす案は却下（中身が M2 と同化するため）。`Invocations` が `onPost` で
  `RunAgentInputParser::parse()` を回し、成功時のみ `AgUiRunner::stream()` の `iterable<AgUiEventInterface>` を `$ro->body` に置く。
  `transfer(TransferInterface $responder, array $server)` を上書きして body 型で分岐：Generator → M1 既存の `SseResponder` +
  `PhpSapiSseSink` で配送（SSE 枠付け/flush は再実装しない）、配列（ping/検証失敗の 400）→ 渡された標準 responder へ委譲。
  **専用 `SseTransfer` クラスも `#[SseStream]` 属性束縛も不要**：`ResourceObject::transfer()` は `$responder($this,$server)` に
  委譲するだけでレンダリング（`toString()`→`JsonRenderer`）は responder 内で遅延発火するため、上書きすれば標準フローは generator を
  触らない（`StreamedResourceObject` 拡張も不要＝ADR0001 の懸念は解消）。`bear/streamer` は `stream_copy_to_stream` による
  ファイル差し込み用で `flush()` 無し・body は配列/stream リソース前提のため **SSE には不適**（将来でも採用しない）。

- **D26 (M3) `確定` ツールは `bear/tool-use` の resource 駆動機構をそのまま使用、エージェントは bundled `StreamingAgentFactory`**。
  tool 名→リソース写像・ツール宣言導出は本体の `Dispatcher` / `ToolRegistry` / `ToolCollector` / `#[Tool]` が担う＝**自前
  ディスパッチャゼロ**。`#[Tool]` を付けたリソースを書き、起動時に `ToolCollector->collect([uris])` で registry 充填＋宣言導出。
  M1 の `StreamingAgentFactory` が recording デコレータ配線（D10）を内蔵するため、**custom `InstrumentedAgentFactory` も
  `AgentFactory`/`AgentPool` も不要**：Provider が collect 済みツールと resource 駆動 `Dispatcher` を `StreamingAgentFactory`
  に渡すだけ。

- **D27 (M3) `確定` ALPS 統治を M3 で実物化、subagent は不採用**。`AlpsSemanticDictionary(profilePath)` を `alps/profile.xml`
  から構築し、`AlpsToolPolicyInputProcessor::safeAndIdempotent()` ＋ `AlpsContextInputProcessor` を `AgUiRunner.$inputProcessors`
  へ。3 ツール（safe `weather_get`=通常 / unsafe `message_post`=ポリシー締め出し / idempotent `reminder_put`=confirm→interrupt）で
  「ガバナンスによる締め出し」と「confirm→interrupt」を**同じ run で両立**させる（`safeOnly` だと unsafe を消すため confirm デモが
  発火しない衝突を、`safeAndIdempotent` ＋ idempotent×confirm ツールで解消）。`AgentPool`/subagent は AG-UI の焦点をぼかすため
  不採用。ALPS ツール**供給**自体は `bear/tool-use` 本体の責務（ライブラリのスコープ外・ADR0004）。

- **D28 (M3) `確定` example の OpenAI 変換層は `example/shared/`（`Example\Shared\`）に置き M2/M3 で共有**。M2 の素サーバと M3 の
  BEAR アプリが同じ `OpenAiStreamingLlmClient` / mappers を使うため、`example/server/` 直下ではなく共有ディレクトリに置いて
  example→example の結合を避ける。BEAR.Sunday 非依存に保つ（`bear/tool-use` 型のみ依存）。本物/スタブ切替は `OPENAI_BASE_URL`
  env のみ（D18）。

- **D29 (M3) `確定` 複数ツールの非同期並列実行を Swoole で自前実装（上流を待たない）**。詳細タスクは
  [`tasks-parallel-tools.md`](done/tasks-parallel-tools.md)。先行スパイク **S-c** で技術検証済み（結論を以下に反映）。
  - **決定 = 並列ループをこのリポジトリで実装**。`bear/tool-use` への `dispatchBatch` seam 追加（上流PR）は待てないため、
    `OptionAwareStreamingAgentInterface` を実装する自前 **`ParallelStreamingAgent`** を **`src/Runtime/`**（ライブラリ第一級機能）に置く。
  - **なぜ自前ループが必須か（S-c で実証）**：seam は `DispatcherInterface` ではなく**ループ内**にある。コルーチン対応 Dispatcher を
    注入しても本物の `StreamingAgent` は並列化しなかった（2×200ms ツールで 403ms＝逐次）。`dispatchPendingToolCalls()` が
    `dispatch()` を1個ずつ inline で await し（[`StreamingAgent.php:195`](../vendor/bear/tool-use/src/Runtime/StreamingAgent.php)）、
    かつ `StreamingAgent` は `final` でサブクラス不可のため。
  - **新規コードは dispatch fan-out のみ（≈30行）**。reason/act ループの他部品（`StreamContentAccumulator` / `StreamIterationState` /
    `ToolList` / `Message` / `LlmRequest` / `AgentOptions` / `AgentEvent` / `PendingToolCall`）は**全 public・`@internal` 無し**で再利用。
    S-c part3 で実 Fake LLM（1ターン3ツール）を流し 202ms（逐次なら 600ms）・`tool_start=3/tool_result=3/completed=1` を実測。
  - **並列ポリシー**：confirm なし＝`Swoole\Coroutine\WaitGroup` で並列／confirm 付き＝直列（`yield CONFIRMATION_REQUIRED` +
    `Generator::send(bool)` で HITL 維持）。S-c part1 T3 で 3並列＋1直列 confirm を実証。
  - **ランタイム＝Swoole 前提だが本体の「ランタイム非依存」性は壊さない**：`WaitGroup` はコルーチン文脈を要するため M3 は Swoole
    HTTP サーバで駆動（`php -S` 手動 smoke 経路を置換）。ただし Swoole は **`require-dev` + `suggest`**（hard `require` にしない）。
    既定は逐次 `StreamingAgent` のまま残し、`ParallelStreamingAgent` は **opt-in**。`src/` 本体の `require` は `psr/log` + `bear/tool-use`
    据え置き（D7 不変条件を維持）。
  - **前提改修2件（実装前に潰す・tasks 化）**：
    - **(a) recording 層の id 帰属**：`ToolCallRegistry` の FIFO 対応づけ（D9）は並列実行で壊れる。**tool id キー**へ変更し
      `RecordingDispatcher`/`ToolCallRegistry` をコルーチン安全にする。これは milestones スコープ外だった「複数ツールの `toolCallId`
      対応づけ」（[`milestones.md`](milestones.md) 旧スコープ外）を **in-scope 化**する。
    - **(b) BEAR.Resource のコルーチン安全性**：`Swoole\Runtime::enableCoroutine()` のフック範囲、DI シングルトン/共有可変状態の
      レース。**先行スパイク S-d で基本成立を確認**（`Swoole\Http\Server` → `ResourceInterface` を per-request coroutine + `WaitGroup` で
      並列 dispatch し wall-clock 201ms＝overlap・同一 worker でクラッシュ無し・SSE 逐次配信 OK）。⚠️ 検証は単純リソース2本＝**複雑な DI
      シングルトンのレースは本番リソースで再確認**（残課題・[`tasks-parallel-tools.md`](done/tasks-parallel-tools.md) T5 B / tasks-m3 T0'）。
  - **コスト（受容）**：reason/act ループのフォーク＝`bear/tool-use` 追従ドリフト。バージョン pin と差分監視で管理。
  - **スパイク資産**：scratchpad の `spike_swoole_parallel_tools.php` / `spike_real_agent_seam.php` / `spike_parallel_agent_impl.php`。
    プロジェクト慣例（tasks-m3 T0）に従い**コミットせず破棄**。

- **D30 (M4) `確定` example の CLI クライアントは本ライブラリの PHP 型に一切依存しない**。`example/cli-client/`
  （`Example\CliClient\`）は `NaokiTsuchiya\BEARAgUi\Event\*` を import せず、[`reference/ag-ui-protocol.md`](reference/ag-ui-protocol.md)
  のワイヤ仕様（JSON フィールド）だけを頼りに SSE を読む。理由：実世界の AG-UI クライアント（ブラウザ／別言語 SDK）は
  当然サーバ側 PHP 型を知り得ない。ライブラリ型を再利用すると「self-describing なワイヤ契約」の実証にならず、
  クライアント実装が本ライブラリの内部実装に暗黙結合してしまう。
  - **会話履歴は client 側が正本**（AG-UI はサーバ側ステートレス・D15 の前提を client 視点で反転）。各 run で
    観測した SSE イベント（`TEXT_MESSAGE_*`/`TOOL_CALL_*`）から AG-UI `messages[]` を組み立て、次 run で全件再送する。
    M1 `MessageHistoryMapper`（messages[]→ToolUse Message、サーバ側）の**逆方向**（events→messages[]、client 側）。
  - **SSE 読み取りはチャンク単位で即時レンダリング**（バッファしない）。curl `CURLOPT_WRITEFUNCTION` でチャンク
    到着ごとにコールバックし `data: {json}\n\n` を切り出す。サーバ側 `SseSinkInterface` 実装群（書き込み側）の
    対になる、読み取り側の新規コンポーネント（ライブラリではなく example 内に閉じる）。
  - **本物の resume はスコープ外のまま**（D4 不変）。`RUN_FINISHED{outcome:interrupt}` を受けたら interrupt
    メッセージを表示してターンを終えるのみ。再開できない旨を明示する（v1 の既知の制約を隠さない）。
  - 接続先は `AGUI_BASE_URL` env（既定 `http://127.0.0.1:8080`）。`OPENAI_BASE_URL`（D18）と同じ流儀。

- **D31 (M5) `確定` example の Web UI はビルドツール無しの React（CDN + Babel standalone）を、M3 の
  bear アプリ自身が同一オリジンで配信する**。
  - **ビルドツール無し React**：`react` / `react-dom`（UMD 版）と `@babel/standalone` を CDN スクリプトタグで
    読み込み、`<script type="text/babel">` 内に JSX を書く（React 公式が過去に案内していた「ビルド不要」導入経路
    と同じ手法）。Node/npm は一切不要。1 ファイル完結の静的 HTML（`example/bear/public/chat.html` 等）。
  - **配信は同一オリジン**：静的 HTML は `public/server.php` の `onRequest` から直接返す（BEAR.Resource を経由
    しない＝静的アセットは「ツールリソース」の関心事ではないため、既存の 404 フォールバックと同じ扱いで
    `GET /` を追加するだけ）。これにより **CORS 対応が一切不要**（`/invocations` と同一オリジン）。CORS 自体は
    ADR 0005（デプロイ関心事）でスコープ外のまま——別オリジン配信は本 M5 では選ばない。
  - **SSE は `fetch()` + `ReadableStream` で自前パース**（`EventSource` は使わない）：AG-UI は `POST` + JSON
    body が必須で、ブラウザ標準の `EventSource` は `GET` しか送れないため使えない。`response.body.getReader()`
    → `TextDecoder` → `\n\n` 区切りでフレーム分割 → `JSON.parse()`、という M4 の `SseFrameReader`（PHP 版）と
    同型のロジックを JS で再実装する（プラットフォームが異なるため共有コードにはしない）。
  - **本ライブラリの型に依存しない**（D30 と同じ精神。ブラウザ JS はそもそも PHP 型を知り得ないため自明だが、
    ワイヤ仕様（[`reference/ag-ui-protocol.md`](reference/ag-ui-protocol.md)）のみを頼りにする点を明記する）。
  - **会話履歴はブラウザ側が正本**（D30 と同じ・client 側で `messages[]` を組み立てて次 run に全件再送）。
  - **JS の自動テストは無し**：本リポジトリは PHP 専用のテストツールチェーン（phpunit/mago/phpmd）で、
    Node 環境を前提にしない（D30 の「ビルドツール無し」と整合）。検証は**手動ブラウザ smoke**（M4 の
    手動 smoke チェックリストと同じ 3 シナリオ：複数ターン・並列 run・interrupt run）に一本化する。
  - **本物の resume は引き続きスコープ外**（D4 不変）。
