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

- **D14 `確定` `AgUiRunner` は組み上げ済み agent ではなく `InstrumentedAgentFactory` を受ける**
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

- **D15 (Q-M1-1) `確定` マルチターン会話履歴は repo 側カバーで対応**（feedback と両建て・enrichment と同パターン）。
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

- **D16 (Q-M1-2) `確定` 未宣言ツール名は lenient 交差で扱う（エラーにしない）**。
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

- **D17 (Q-M1-3) `確定` `UserMessage.content` は text 抽出で正規化、マルチモーダル入力は v1 非対応**。
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

- **D19 (M2) `確定` OpenAI delta → bear `StreamEvent` は state machine 変換**。
  - open block（`none`/`text`/`tool(index)`）を追跡し、境界で `CONTENT_BLOCK_STOP` を差し込む。`delta.content`→`TEXT_DELTA`、
    `tool_calls[].id` 初出→`TOOL_USE_START`、`tool_calls[].function.arguments`→`TOOL_USE_DELTA`、`finishReason`→
    `MESSAGE_STOP`。
  - finish_reason マッピング：`tool_calls`/`function_call`→**`tool_use`**（唯一クリティカル＝ループ継続トリガ）、
    その他（`stop`/`length`/`content_filter`）→`end_turn`。根拠：`StreamingAgent` は `tool_use`+pending 以外を
    terminal complete に落とす（コード確認済み）。
  - ⚠️ 並行ツールは**順次のみ**対応（index 跨ぎ arguments interleave は非対応）。bear `StreamContentAccumulator` 自体が
    単数 `currentToolId`＝同じ制約。OpenAI は実際には順次送出するため実用上問題なし。README に明記。

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
