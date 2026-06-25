# M2 実装タスク（詳細）

example①：素 PHP HTTP サーバ ＋ プロセス内結合テスト。
M1 のファサード `AgUiRunner` を、**フレームワーク非依存の最小サーバ**として駆動する使用例を `example/` に置き、
**OpenAI 互換 LLM** で実際にエージェントループを回せるショーケースにする。あわせて OpenAI 互換の**スタブサーバ**を
同梱し、API キー無し・決定論的に end-to-end を回せるようにする。

[`milestones.md`](milestones.md) M2 / [`architecture.md`](architecture.md) §3-7 / [`decisions.md`](decisions.md) D7 を
実装タスクに落としたもの。末尾 `(Dxx)` は根拠。`⚠️` は実装時に実物で確認する点。前提：M1（`AgUiRunner` /
`DefaultInstrumentedAgentFactory` / `MessageHistoryMapper` / `tests/Fake`）完了。

新規依存：`openai-php/client ^0.20` を **`require-dev`** に追加（example/stub 用。本体 `require` は汚さない）(D18)。

---

## 設計サマリ（M2 で確定した姿）

```
example/
├── server/                         AG-UI サーバ（本ライブラリの使用例）
│   ├── public/index.php            POST /invocations（SSE）, GET /ping
│   ├── src/
│   │   ├── Bootstrap.php           env → AgUiRunner 組み立て
│   │   ├── OpenAiStreamingLlmClient.php   OpenAI delta → bear StreamEvent 変換（D19/D20）
│   │   ├── OpenAiMessageMapper.php        bear Message[] ↔ OpenAI messages
│   │   ├── OpenAiToolMapper.php           bear Tool[] → OpenAI tools
│   │   ├── DemoDispatcher.php             DispatcherInterface 直実装（BEAR.Resource 非依存）
│   │   └── Tool/
│   │       ├── GetTimeTool.php            confirm なし・固定値
│   │       └── AskConfirmationTool.php    confirm:true（real 経路で interrupt 誘発）
│   └── README.md
└── stub-llm/                       OpenAI 互換スタブ（API キー不要・決定論的）(D21)
    ├── public/index.php            POST /v1/chat/completions のみ
    ├── src/
    │   ├── CannedConversation.php  ターン判定 + canned delta 列生成
    │   └── OpenAiSseWriter.php     chunk → "data: {json}\n\n" 直列化
    └── README.md
```

- **エンドポイント**：`POST /invocations` + `GET /ping`（mode クエリ無し・本物/スタブは `OPENAI_BASE_URL` で切替）(D18)
- **LLM 切替は env のみ**：`OPENAI_BASE_URL` をスタブに向ければキー不要、本物 OpenAI に向ければ実 LLM。コードは不変
- **結合テストは HTTP を起こさない**：`AgUiRunner` をプロセス内で `tests/Fake` + recording sink で駆動（D13 流用）(D22)

---

## T1. example ディレクトリ骨格 ＋ autoload（D7/D18）

- [ ] `example/server/` `example/stub-llm/` を作成（`public/` `src/` `README.md`）
- [ ] ルート `composer.json` の `autoload-dev` に追記：`"Example\\Server\\": "example/server/src/"`, `"Example\\StubLlm\\": "example/stub-llm/src/"`
  - [ ] ⚠️ **本体 `autoload`（`NaokiTsuchiya\BEARAgUi\`）には混ぜない**。example は配布物に含めない
- [ ] `require-dev` に `openai-php/client: ^0.20` を追加（D18）
- [ ] ⚠️ `composer crc` は `require` のみ検査＝example の OpenAI 依存は漏れない（不変条件）。確認すること

## T2. OpenAI 互換スタブサーバ（`example/stub-llm/`、D21）

API キー無しで `OPENAI_BASE_URL` を向けるだけの決定論サーバ。`POST /v1/chat/completions` の **1 エンドポイントのみ**
（openai-php は他 endpoint を叩かない＝起動時 preflight 無し・確認済み）。

- [ ] `public/index.php`：`POST /v1/chat/completions` 以外 → 404 JSON。`Authorization` ヘッダは**値を見ない**（任意キー許可）
- [ ] `CannedConversation::respond(array $requestBody): iterable<array>`：受信 `messages` 末尾の `role` でターン判定
  - [ ] 末尾 `role !== 'tool'`（＝ターン1）→ text delta + `get_time` tool_call（**arguments を 2 チャンクに分割**）+ `finish_reason:"tool_calls"`
  - [ ] 末尾 `role === 'tool'`（＝ターン2）→ **受信した tool_result の content（実時刻）を読み**最終テキストに埋め込む delta + `finish_reason:"stop"`（C：canned 文字列にせずツール結果を echo＝ループが結果を閉じることを genuine に示す）
  - [ ] tool_call id は固定（`call_demo_1`）、`get_time` の引数は `{"timezone":"UTC"}`
- [ ] `OpenAiSseWriter`：各 chunk を `data: {json}\n\n` で書き（**素の `echo + flush`**・本体 `Sse/` は流用しない＝A）、末尾に `data: [DONE]\n\n`。`chat.completion.chunk` object・`choices[0].delta` 形式（fixture 準拠）
- [ ] ⚠️ chunk の `model` は受信 `model` をそのまま反射（OpenAI 互換のため）
- [ ] チャンク間遅延：env `STUB_DELAY_MS`（既定 `0`）。デモ時に `200` 等で逐次到達を肉眼確認（B）
- [ ] テスト：ターン1/ターン2 をそれぞれ叩き、期待 chunk 列（content / tool_calls / finish_reason）をアサート

## T3. OpenAI クライアント変換層（`example/server/src/`、D19/D20）

`StreamingLlmClientInterface::chatStream(string $system, list<Message> $messages, list<Tool> $tools): Generator<int, StreamEvent>`
を openai-php で実装。**読み側＝OpenAI delta → bear StreamEvent**、**書き側＝bear → OpenAI request**。

### T3-a. `OpenAiToolMapper`（書き・一方向）

- [ ] `map(list<Tool> $tools): array`：各 `Tool{name, description, inputSchema}` → `{type:function, function:{name, description, parameters: inputSchema}}`
- [ ] テスト：1 ツールの変換

### T3-b. `OpenAiMessageMapper`（書き）

- [ ] `map(string $system, list<Message> $messages): array`（OpenAI messages 配列）
  - [ ] `$system` 非空 → 先頭 `{role:system, content:$system}`
  - [ ] `{role:user, content:[{type:text}]}` → `{role:user, content: text 連結}`
  - [ ] `{role:user, content:[{type:tool_result,...}]}` → **各 result を** `{role:tool, tool_call_id, content: stringify}` に展開
  - [ ] `{role:assistant, content:[text + tool_use blocks]}` → `{role:assistant, content: text 連結 or null, tool_calls:[{id, type:function, function:{name, arguments: json_encode(input)}}]}`
  - [ ] ⚠️ bear `user` ロールは 2 用途（text / tool_result）。`content[].type` に `tool_result` を含むかで判別（D20）
  - [ ] ⚠️ `ToolResult.content` は `mixed`。string はそのまま、非 string は `json_encode`（OpenAI tool content は string 必須）
- [ ] テスト：①user text ②assistant w/ tool_use ③tool_results 展開（複数） ④system 前置

### T3-c. `OpenAiStreamingLlmClient`（読み・state machine、D19）

`open block = none | text | tool(index)` を追跡し、チャンクごとに bear StreamEvent を yield。

- [ ] `delta.content` 非空 → `tool` open 中なら `CONTENT_BLOCK_STOP` → `TEXT_DELTA{text}`、open=text
- [ ] `delta.toolCalls[]` 各 tc：
  - [ ] `tc.id !== null`（先頭チャンク）→ open!=none なら `CONTENT_BLOCK_STOP` → `TOOL_USE_START{id, name}`、open=tool(tc.index)
  - [ ] `tc.function.arguments !== ''` → `TOOL_USE_DELTA{input: arguments}`
- [ ] `finishReason !== null` → open!=none なら `CONTENT_BLOCK_STOP` → `MESSAGE_STOP{stopReason: map(finishReason)}`
- [ ] finish_reason マッピング：`tool_calls`/`function_call` → **`tool_use`**（唯一クリティカル）、その他（`stop`/`length`/`content_filter`）→ `end_turn`（bear ループは tool_use 以外を terminal complete に落とす・確認済み）
- [ ] ⚠️ **並行ツールは順次のみ対応**（index 跨ぎ arguments interleave は非対応＝bear `StreamContentAccumulator` も単数 `currentToolId`）。README に明記（D19）
- [ ] テスト（**実 API も HTTP も叩かない**・E）：openai-php の `withHttpClient()` に **stub の `CannedConversation` をプロセス内で呼ぶ PSR-18 fake**（`tests/Support/StubHttpClient`）を注入し、openai-php の実 SSE パース経路を通す
  - [ ] (b) happy path：stub canned 会話を end-to-end で流し、StreamEvent 列を検証（stub を単一ソースに再利用）
  - [ ] (a) マッピング枝（stub の単一形状に無いもの）は canned にパターン追加 or `CreateStreamedResponse` を直接構築して補う：
    - [ ] ①text-only → MESSAGE_STOP{end_turn}
    - [ ] ②単一 tool（arguments 分割）→ TOOL_USE_START/DELTA*/CONTENT_BLOCK_STOP/MESSAGE_STOP{tool_use}
    - [ ] ③text→tool→（finish）でブロック境界に CONTENT_BLOCK_STOP
    - [ ] ④finish_reason 各種 → stopReason マッピング

## T4. デモツールとディスパッチャ（`example/server/src/`、D21）

- [ ] `GetTimeTool`：`Schema\Tool` 定義（name `get_time`, confirm なし, inputSchema `{type:object, properties:{timezone:{type:string}}, required:[]}`）
- [ ] `AskConfirmationTool`：name `ask_confirmation`, **confirm:true**, inputSchema `{message:string}`（stub では呼ばれない。real で interrupt を誘発）
- [ ] `DemoDispatcher implements DispatcherInterface`：`dispatch(ToolCall): ToolResult` を name で分岐
  - [ ] `get_time` → `ToolResult::success($id, <実時刻文字列>)`（C：実時刻でよい。T7 結合テストは Fake 経由でこの経路を通らず自動テストの決定論性に影響しない。stub がこの結果を echo するためデモは coherent）
  - [ ] 未知 name → `ToolResult::error($id, "Unknown tool: {$name}")`
- [ ] テスト：dispatch の name 分岐

## T5. ブートストラップ（`example/server/src/Bootstrap.php`、D14/D18）

- [ ] `Bootstrap::buildRunner(): AgUiRunner`
  - [ ] env 読み：`OPENAI_API_KEY`（未設定は real 接続時のみ問題）、`OPENAI_BASE_URL`（既定 `https://api.openai.com/v1`）、`OPENAI_MODEL`（既定 `gpt-4o-mini`）
  - [ ] openai-php Factory：`->withApiKey(...)->withBaseUri($baseUrl)->make()`
  - [ ] systemPrompt は**最小**（例：`"You are a helpful assistant. Use the provided tools when relevant."`）。stub には無影響、real OpenAI でツールを呼ばせる最低限（D）
  - [ ] `OpenAiStreamingLlmClient`（上記 client + mappers）＋ `DemoDispatcher` ＋ `[GetTimeTool, AskConfirmationTool]` ＋ systemPrompt で `DefaultInstrumentedAgentFactory` を構築
  - [ ] `AgUiRunner` を `factory + MessageHistoryMapper + SseEncoder + NullLogger` で組む
- [ ] ⚠️ `Bootstrap` は **`tests/Fake` を import しない**（依存方向・guard T8）。テストは別経路で Fake 注入

## T6. フロントコントローラ（`example/server/public/index.php`、ADR0001）

- [ ] ルーティング：`GET /ping` → 200 JSON `{status:"Healthy", time_of_last_update:<ts>}`、`POST /invocations` → SSE、他 → 404 JSON
- [ ] `Content-Type` ガード：`/invocations` で `application/json` 以外 → 415 JSON
- [ ] pre-flight（接続レベル・**`sink->open()` 前**）：`RunAgentInput::fromJson($body)` 失敗 → **400** `{code:VALIDATION_ERROR, message}`
- [ ] `Bootstrap::buildRunner()` → `$runner->run($input, new PhpSapiSseSink())`。`run()` 内 pre-flight 例外（空 user content）も 400 に写像
- [ ] real 接続エラー（キー無効等）は run 開始後＝**HTTP 200 + `RUN_ERROR`**（D11・status は後から変えない）
- [ ] poc `poc/proto/proto/server.php` は残置（参考）。本 index.php が後継

## T7. プロセス内結合テスト（`tests/Integration/Example/`、D13/D22）

**HTTP を起こさず** `AgUiRunner` を直接駆動。LLM は `tests/Fake/FakeStreamingLlmClient`、sink は recording。決定論的。

- [ ] `RecordingSseSink implements SseSinkInterface`（`tests/Support/`）：`write()` されたフレームを配列に蓄積
- [ ] 単一ターン（テキスト）：`RUN_STARTED → TEXT_MESSAGE_START → CONTENT* → TEXT_MESSAGE_END → RUN_FINISHED{success}` の順序
- [ ] ツールループ：`TOOL_CALL_START → ARGS → END → RESULT` のペアリング＋後続テキストが新 id（D9/D10）
- [ ] interrupt：confirmable ツール → `RUN_FINISHED{outcome:interrupt}`（D4）
- [ ] error：実行中失敗 → `RUN_ERROR`（HTTP 200 相当・フレーム内）
- [ ] 検証：不正 JSON → 400 / 空 user content → 400（run 前に例外）
- [ ] ⚠️ ここでは example/server の `OpenAiStreamingLlmClient` ではなく `FakeStreamingLlmClient` を使う（OpenAI 変換は T3 のユニットでカバー、結合は AgUiRunner 配線を見る）

## T8. ガード（境界規則・要承認事項あり）

- [ ] `src/` → `example/` を**禁止**（本体が example を参照しない不変条件）
- [ ] `example/` → `tests/` を**禁止**（Bootstrap が Fake を掴まない）
- [ ] `example/server/` → `bear/tool-use` 型は **変換層（OpenAi*）と Tool/Dispatcher のみ**で OK（HTTP 層 index.php には漏らさない）
- [ ] ⚠️ **`mago guard` のルール追加はユーザー承認案件**（[feedback: linter rules は無断で触らない]）。ルール追記の要否・内容を確認してから実施。代替として「example を guard 対象外にする」案も提示

## T9. CI 配線

- [ ] `phpunit.xml.dist` に `<testsuite name="integration">`（`tests/Integration/`）を追加。既存 unit と分離 or `@group` で切る
- [ ] `composer tests`（`@sa` + `@test`）で example/src と stub/src も静的解析・テスト対象に入ることを確認
- [ ] ⚠️ `mago analyze` / `phpmd` の対象パス：現状 `src/` のみ（composer.json）。example を解析対象に含めるか確認（含めるなら設定変更＝T8 同様に承認）

## T10. ドキュメント

- [ ] `example/server/README.md`：2 起動モード（スタブ経由＝キー不要 / 本物 OpenAI）、curl 例、登場ツール、interrupt は real のみ・スタブは happy path、の明記
- [ ] `example/stub-llm/README.md`：何を返すか（ターン1=tool_call / ターン2=最終テキスト）、`OPENAI_BASE_URL` の向け方
- [ ] `milestones.md` M2 に「詳細タスク: [`tasks-m2.md`](tasks-m2.md)」リンク追加（M1 と同並び）。「Fake で決定論」の文言は「**結合テストが** Fake で決定論」に補正、「サーバ起動して end-to-end」は「プロセス内で AgUiRunner を駆動」に補正
- [ ] `decisions.md` に D18〜D22 を追記（本ファイル末尾の決定を正式化）

## T11. 仕上げ / DoD

- [ ] `composer tests`（mago format/lint/analyze/guard + phpmd + phpunit unit + integration）グリーン
- [ ] `composer crc` グリーン（example は autoload-dev に閉じ `require` 汚染なし）
- [ ] **手動 smoke**（README チェックリスト）：端末1 でスタブ、端末2 で server を `php -S` 起動、端末3 から curl → SSE が逐次到達（フレーム順・tool ループ全周）を肉眼確認
- [ ] 3 連続で integration テストグリーン（flake 無し）

---

## コミット粒度

作業ブランチ。**各コミット green**、依存順。メッセージは命令形・プレフィックス無し。

| # | コミットメッセージ（案） | 含むタスク | green |
| --- | --- | --- | --- |
| C1 | `Add OpenAI-compatible stub LLM server for examples` | T1 + T2 | phpunit（stub unit） |
| C2 | `Add OpenAI message and tool mappers` | T3-a + T3-b | phpunit |
| C3 | `Add OpenAI streaming client adapter` | T3-c | phpunit |
| C4 | `Add demo tools and dispatcher` | T4 | phpunit |
| C5 | `Add example AG-UI server bootstrap and front controller` | T5 + T6 | phpunit |
| C6 | `Add example integration tests with recording sink` | T7 + T9 | `composer tests` |
| C7 | `Document example servers and link M2 tasks` | T10 | — |

依存順：**C1 → C2 → C3 → C4 → C5 → C6 → C7**。T8（guard 追加）は承認後に別コミット（必要なら）。

---

## 本マイルストーンで確定した決定（decisions.md へ反映予定）

- **D18 example の LLM クライアントは `openai-php/client ^0.20`**（OpenAI 互換＝base URL 差替で OpenRouter/Ollama/vLLM 等に届く・streaming + tool_calls 検証済み・依存 7・MIT）。`require-dev` に閉じ本体 `require` を汚さない。エンドポイントは AgentCore 規約踏襲の `POST /invocations` + `GET /ping`、LLM 切替は `OPENAI_BASE_URL` env のみ（mode クエリ廃止）。Symfony AI Platform は experimental・依存大のため見送り（M3 で多プロバイダ抽象の例として再検討可）
- **D19 OpenAI delta → bear `StreamEvent` は state machine 変換**。open block（none/text/tool(index)）を追跡し境界に `CONTENT_BLOCK_STOP`。finish_reason は `tool_calls`→`tool_use`、他→`end_turn`（bear ループは tool_use 以外を terminal complete 化）。並行ツールは順次のみ対応（index 跨ぎ interleave 非対応＝bear 自体の制約と同じ）
- **D20 bear `Message` → OpenAI request 変換**。bear `user` ロールの 2 用途（text / tool_result）を `content[].type` で判別し、tool_result は `role:tool` 複数メッセージへ展開。`ToolResult.content`（mixed）は非 string を `json_encode`
- **D21 example はスタブ LLM を同梱**。OpenAI 互換 `POST /v1/chat/completions` 単一エンドポイント、`messages` 末尾 role でターン判定する単一会話（ターン1=`get_time` tool_call / ターン2=受信 tool_result を echo した最終テキスト）。API キー不要で happy path 全周を再現。SSE 直列化は本体 `Sse/` を流用せず stub 独自の `echo+flush`（A）、チャンク遅延は `STUB_DELAY_MS` env（既定 0・B）。interrupt は real 経路（model が confirmable ツールを選択）でのみ発現
- **D22 M2 結合テストは HTTP を起こさない**。`AgUiRunner` をプロセス内で `tests/Fake`（D13）+ recording sink で駆動し SSE フレーム列を検証。HTTP/SSE の本番逐次配信は `php -S` では本番（fpm+nginx）を再現できないため**手動 smoke** に降格（時計依存の自動テストは flake 源として置かない）

---

## スコープ外（M2 では扱わない）

- BEAR.Sunday / DI / リソース化（M3）
- 実 LLM のマルチプロバイダ抽象（M3 で Symfony AI 等を検討可）
- 認証・CORS・並行接続スケーリング（ADR 0005 デプロイ関心事）
- スタブのシナリオ多重化（`STUB_SCENARIO` 等）— 単一 canned で十分。必要時に追加
- Swoole 等の別 SAPI（S4 差し替え点として残置）
- HTTP 経由の自動 e2e テスト（手動 smoke で代替・D22）
