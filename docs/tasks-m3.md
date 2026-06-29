# M3 実装タスク（詳細）

example②：**BEAR.Sunday ショーケースアプリ**。M1 のファサード `AgUiRunner::stream()` を BEAR.Sunday アプリから駆動し、
**単一エージェントが BEAR リソースをツールとして呼び、ALPS でツールを統治しつつ、その出力を AG-UI イベントとして
SSE 逐次配信する**例を `example/bear/` に置く。M2（フレームワーク非依存・固定値ツール）には無い
**resource-as-tool ＋ ALPS ＋ BEAR ネイティブ SSE** を初めて実物で示す。

[`milestones.md`](milestones.md) M3 / [`architecture.md`](architecture.md) §2-7 / [`decisions.md`](decisions.md) D23 を
実装タスクに落としたもの。末尾 `(Dxx)` は根拠。`⚠️` は実装時に実物で確認する点。

**前提（grounding 済み・重要）**:

- M1 で `AgUiRunner::stream(RunAgentInput): iterable<AgUiEventInterface>` は完成済み（D23）。**M3 は M1 を一切改造しない**。
- `bear/tool-use` は **resource 駆動の `Dispatcher`** と **`ToolRegistry` / `ToolCollector` / `#[Tool]` 属性**を**既に持つ**。
  ツール名→リソース写像・ツール宣言の導出は本体が担う＝**自前ディスパッチャはゼロ**。
- M1 の **`StreamingAgentFactory`** が recording デコレータ配線（D10）を内蔵。M3 は `AgentFactory`/`AgentPool` も
  **custom factory も書かず**、`StreamingAgentFactory` に「resource 駆動 `Dispatcher` ＋ collect 済みツール」を渡すだけ。
- SSE 配送は M1 の **`SseResponder` + `PhpSapiSseSink` を再利用**（`SseEncoder::encode()` で枠付け、`SseSinkInterface::send(headers, frames)`）。
  M3 は `Invocations::transfer()` を上書きしてそれを呼ぶだけ（専用 Transfer クラス不要・T0 で確定）。
- **M2 は未実装**（`example/` 無し＝tasks-m2 は計画のみ）。OpenAI 変換層は M2/M3 共有のため `example/shared/` に置く（D28）。

新規依存（`require-dev`・本体 `require` は汚さない）：BEAR.Sunday 一式（`bear/package` / `bear/resource` / `ray/di`）と
`openai-php/client ^0.20`（D18）。`bear/tool-use` は本体 `require` に既存。

---

## 設計サマリ（M3 で作る姿）

```
example/
├── shared/                          M2/M3 共有の OpenAI 変換層（Example\Shared\・D28）
│   └── src/Llm/
│       ├── OpenAiStreamingLlmClient.php   OpenAI delta → bear StreamEvent（D19）
│       ├── OpenAiMessageMapper.php        bear Message[] → OpenAI request（D20）
│       └── OpenAiToolMapper.php           bear Tool[] → OpenAI tools
└── bear/                            BEAR.Sunday アプリ（Example\Bear\）
    ├── public/index.php             bootstrap → router → POST /invocations, GET /ping
    ├── src/
    │   ├── Module/AppModule.php          install ToolUseModule + ResourceModule + 束縛集約
    │   ├── Module/AgUiModule.php         AgUiRunner / SseEncoder / SseResponder / AgUiAdapter / factory provider
    │   ├── Resource/Page/Invocations.php onPost: parse → body=stream() の Generator / transfer() 上書きで SSE 配送
    │   ├── Resource/Page/Ping.php        onGet: {status:"Healthy"}
    │   ├── Resource/App/Weather.php      #[Tool('weather_get', confirm:false)] onGet（ALPS safe・通常）
    │   ├── Resource/App/Message.php      #[Tool('message_post', confirm:false)] onPost（ALPS unsafe → ポリシーで締め出し）
    │   ├── Resource/App/Reminder.php     #[Tool('reminder_put', confirm:true)] onPut（ALPS idempotent → confirm→interrupt）
    │   └── Provider/AgentFactoryProvider.php  ToolCollector->collect(uris) → StreamingAgentFactory
    ├── alps/profile.xml             ALPS（descriptor safe/idempotent/unsafe）
    ├── var/{tmp,log}
    └── README.md
```

- **入口は ResourceObject**（薄いハンドラ案は却下＝中身が M2 と同化するため・milestones M3）。
- **配送は `Invocations::transfer()` オーバーライド**（T0 で確定）：body が Generator なら M1 既存の `SseResponder` +
  `PhpSapiSseSink` で配送、配列（ping/400）なら標準 responder に委譲。属性も専用 Transfer クラスも不要。`bear/streamer` は不適。
- **本物/スタブの LLM 切替は `OPENAI_BASE_URL` env のみ**（D18）。M2 の stub-llm をそのまま流用できる。

---

## T0. 先行スパイク（**実施済み・結果を以降へ反映**）

スクラッチに `bear/package`（`bear/streamer` 含む）を入れて検証済み。結論＝**骨格は変えない（body=Generator で進む）**。

- [x] **S-a 解決＝OK**：`ResourceObject::transfer()` は `$responder($this, $server)` に委譲するだけで、レンダリングは
  responder 内の `toString()`（`JsonRenderer`）で**遅延発火**。`onPost` で `$this->body` に `Generator` を置いても標準フローは
  触らない（onPost 後も generator 未実行＝遅延を実機確認）。**`StreamedResourceObject` 拡張は不要**（ADR0001 の懸念は解消）。
- [x] **S-b 解決＝seam は `Invocations::transfer()` オーバーライド**（`#[SseStream]` 属性束縛・専用 `SseTransfer` クラスは**不要**）。
  transfer 内で `is_iterable($body) && !is_array($body)` を分岐：Generator → SSE 配送、配列（ping/400）→ 渡された標準
  responder に委譲。`/ping`・検証失敗の 400 JSON が標準経路に流れることを実機確認。
- [x] **副確認**：`bear/streamer` は `bear/package` 依存で同梱されるが **SSE 不適**（`stream_copy_to_stream` のファイル差し込み用・
  `flush()` 無し・body は配列/stream リソース前提で Generator 非対応）。採用しない（D25 補強）。

## T1. アプリ骨格 ＋ autoload（D7/D18/D28）

- [ ] `example/shared/`（`src/Llm/`）、`example/bear/`（`public/` `src/` `alps/` `var/` `README.md`）を作成
- [ ] ルート `composer.json` `autoload-dev` に追記：`"Example\\Shared\\": "example/shared/src/"`,
  `"Example\\Bear\\": "example/bear/src/"`
- [ ] ⚠️ **本体 `autoload`（`NaokiTsuchiya\BEARAgUi\`）には混ぜない**（example は配布物に含めない）
- [ ] `require-dev` に BEAR.Sunday 一式 ＋ `openai-php/client: ^0.20`（D18）。⚠️ `composer crc` は `require` のみ検査＝漏れない確認
- [ ] `example/bear/var/tmp` `var/log` を用意（`.gitignore` で中身除外）

## T2. 共有 OpenAI 変換層（`example/shared/src/Llm/`、D19/D20/D28）

M2 と同一。**M2 が先に着手済みならそのファイルを `example/shared/` へ移し、M3 が先なら M3 で新規作成**（どちらも共有物）。

- [ ] `OpenAiToolMapper::map(list<Tool>): array`（bear Tool → OpenAI function tools）
- [ ] `OpenAiMessageMapper::map(string $system, list<Message>): array`（bear Message → OpenAI messages・D20）
- [ ] `OpenAiStreamingLlmClient implements StreamingLlmClientInterface`（OpenAI delta → bear `StreamEvent` の state machine・D19）
- [ ] テスト：マッパ各枝 + state machine（M2 計画 T3 のテスト方針＝openai-php に PSR-18 fake を注入し実 SSE パース経路を通す）を流用
- [ ] ⚠️ 詳細仕様は [`tasks-m2.md`](tasks-m2.md) T3 と同一。重複記述せず M2 の決定（D19/D20）に従う

## T3. ツールになる BEAR リソース ＋ ALPS プロファイル（resource-as-tool の主役）

3 ツール構成で「通常呼び出し」「ALPS ポリシーによる締め出し」「confirm→interrupt」を 1 run で同時に見せる（未決点1 の解決＝(b)）。

- [ ] `Resource/App/Weather.php`：`#[Tool(name:'weather_get', confirm:false)]` を `onGet(string $city)` に付与。
  副作用なしの照会（**ALPS で safe** → ポリシー通過＝通常の tool ループ）
- [ ] `Resource/App/Message.php`：`#[Tool(name:'message_post', confirm:false)]` を `onPost(string $to, string $body)` に付与。
  送信＝副作用あり（**ALPS で unsafe** → `safeAndIdempotent` ポリシーで**LLM に提示されない**＝ガバナンスのデモ）
- [ ] `Resource/App/Reminder.php`：`#[Tool(name:'reminder_put', confirm:true)]` を `onPut(string $id, string $text)` に付与。
  冪等な設定（**ALPS で idempotent** → ポリシー通過）。**confirm:true ＝ 実行前に `CONFIRMATION_REQUIRED` を誘発**し、
  M1 アダプタが `RUN_FINISHED{outcome:interrupt}` に写像
- [ ] `alps/profile.xml`：上記の descriptor を `safe`（weather）/`unsafe`（message）/`idempotent`（reminder）で記述。
  `AlpsSemanticDictionary` が読む形式（`simplexml_load_string` 前提のプロファイル XML）
- [ ] ⚠️ ツール名とリソースの対応は `ToolCollector` が `#[Tool]`＋URI から導出（`weather_get` → `app://self/weather` GET 等）。
  命名は `ToolCollector::extractMethodFromToolName` の規約（`{path}_{method}` or `_get`/`_post` サフィックス）に合わせる
- [ ] テスト：各リソースを Injector 経由で叩き、戻り値（`$ro->body` / `code`）と `#[Tool]` メタを確認

## T4. エージェント構築の配線（`Provider/AgentFactoryProvider.php`）

**custom factory は書かない。** bundled `StreamingAgentFactory`（M1）に resource 駆動 `Dispatcher` と collect 済みツールを渡す。

- [ ] `AgentFactoryProvider implements ProviderInterface<InstrumentedAgentFactory>`
  - [ ] DI で `StreamingLlmClientInterface`（= `OpenAiStreamingLlmClient`）, `BEAR\ToolUse\Dispatch\DispatcherInterface`
    （= bear/tool-use の resource 駆動 `Dispatcher`）, `ToolCollectorInterface`, systemPrompt を受ける
  - [ ] **起動時 1 回** `collector->collect([<resource URIs>])` で `list<Tool>` を得る（副作用で `ToolRegistry` も充填）
  - [ ] `new StreamingAgentFactory($client, $dispatcher, $tools, $systemPrompt)` を返す（app 単一）
- [ ] ⚠️ collect 対象 URI（`app://self/weather`, `app://self/message`, `app://self/reminder`）は 1 箇所で定義し Provider と ALPS で共有
- [ ] ⚠️ recording デコレータ（`RecordingStreamingLlmClient`/`RecordingDispatcher`）は `StreamingAgentFactory::newInstance()`
  が per-run で巻く。Provider は**素の** client/dispatcher を渡すだけ（S5）
- [ ] テスト：Provider が tools を collect し registry が充填されること（Fake LLM 注入）

## T5. ALPS 統治の配線（`AgUiModule` / `AppModule`、ADR0004）

- [ ] `AlpsSemanticDictionary` を `alps/profile.xml` のパスで束縛（`toConstructor` に profilePath）
- [ ] `AgUiRunner.$inputProcessors` に **`AlpsToolPolicyInputProcessor::safeAndIdempotent($dictionary)`** と
  **`AlpsContextInputProcessor($dictionary, ...)`** を渡す（`list<InputProcessorInterface>`）
- [ ] 方針＝`safeAndIdempotent`（未決点1 の解決＝(b)）：`weather_get`(safe) と `reminder_put`(idempotent) は通過、
  `message_post`(unsafe) は締め出し。→ ガバナンス（締め出し）と confirm→interrupt が**同じ run で両立**する
- [ ] テスト：ポリシー適用で `enabledTools` から `message_post` が消え、`weather_get`/`reminder_put` が残ること

## T6. 入口リソース（`Resource/Page/Invocations.php` / `Ping.php`、ADR0001）

- [ ] `Ping::onGet()`：`$this->body = ['status' => 'Healthy', 'time_of_last_update' => <ts>]`（標準 JSON・SSE 非対象）
- [ ] `Invocations::onPost()`：
  - [ ] 生 body を `RunAgentInputParser::parse()` で検証（`RunAgentInput|ParseError`）
  - [ ] `ParseError` → `$this->code = 400; $this->body = [...]`（**配列＝Generator を置かない**＝標準経路で 400 JSON）
  - [ ] 成功 → `$this->body = $this->runner->stream($input)`（`iterable<AgUiEventInterface>`・**遅延**）。`code = 200`
  - [ ] body は **生 `php://input` 文字列**を `onPost(string $rawBody)` に渡し、`parse(string)` を単一の検証境界に保つ（D23 維持）。
    BEAR 既定の `HttpMethodParams` は `application/json` を `json_decode`→top-level キーを名前付き引数に展開し、不正 JSON を
    `InvalidRequestJsonException` で throw する。これに載せると M1 パーサの導出が二重化（drift）し 400 経路が `ParseError` でなくなる
  - [ ] ⚠️ 生 body を渡す手段：`HttpMethodParamsInterface` の薄い差し替え（`['rawBody' => file_get_contents('php://input')]` を返す）
    or raw-body プロバイダ注入。ADR0001 §52「ネスト構造はスカラ引数に載らない」とも整合（パーサが構造を所有）
- [ ] ⚠️ run 中の例外は `stream()` 消費時に `RUN_ERROR` イベント（HTTP 200 上）。`onPost` で前倒し検証できるのは parse 失敗のみ

## T7. SSE 配送＝`Invocations::transfer()` オーバーライド（T0 で確定・D25）

属性も専用 `SseTransfer` クラスも作らない。`Invocations` が `transfer()` を上書きし、body 型で分岐する。

- [ ] `Invocations::transfer(TransferInterface $responder, array $server)` を上書き：
  - [ ] `is_iterable($this->body) && !is_array($this->body)`（＝Generator）→ 注入済み `SseResponder` で
    `$this->sseResponder->respond($this->body, new PhpSapiSseSink())`（M1 既存を再利用）
  - [ ] それ以外（ping は別リソース／400 は配列 body）→ `$responder($this, $server)`（渡された標準 responder に委譲）
- [ ] `Invocations` に `SseResponder` を注入（`#[Inject]` or ctor）。`SseResponder`/`SseEncoder`/`PhpSapiSseSink` は M1 既存
- [ ] ⚠️ `Content-Type: text/event-stream` / `Content-Length` 無し / 出力バッファ無効化 / **フレーム毎 flush** は
  `PhpSapiSseSink::send()` の既存挙動を実機 smoke で確認（T13）
- [ ] T0 で確認済み：標準フローは Generator body を触らない（render は responder 内 `toString()` で遅延）＝ S-a/S-b クリア

## T8. DI Module（`Module/AppModule.php` / `Module/AgUiModule.php`、architecture §2）

- [ ] `AppModule`：`install(new ToolUseModule())` ＋ BEAR `ResourceModule` ＋ `AgUiModule`。実 `StreamingLlmClientInterface`
  （`OpenAiStreamingLlmClient`＋env で base URL 切替）を束縛。`DispatcherInterface` は `ToolUseModule` 既定（resource 駆動 `Dispatcher`）
- [ ] `AgUiModule`：
  - [ ] `InstrumentedAgentFactory` → `AgentFactoryProvider`（T4・app 単一）
  - [ ] `SseEncoder` / `SseResponder` / `AgUiAdapter` / `MessageHistoryMapper`（すべて app 単一・ステートレス）
  - [ ] `AgUiRunner`（app 単一）を `factory + historyMapper + adapter + inputProcessors(T5)` で構成
  - [ ] `SseResponder`（+ `SseEncoder`）を `Invocations` に注入できるよう束縛（配送は `Invocations::transfer()` 上書き＝T7）
- [ ] ⚠️ `ToolCallRegistry` / agent は `AgUiRunner::stream()` 内で per-run `new`＝**DI スコープ束縛しない**（architecture §2 改訂後）

## T9. ブートストラップ（`example/bear/public/index.php`）— 標準 BEAR に乗せる

手書きルーティングは作らない。bear/package 標準のブートストラップ＋ `WebRouter` を使う（Swoole 等は別ランタイム＝adapter 差し替え・スコープ外）。

- [ ] `public/index.php` は標準ブートストラップ（`$app = Injector::getInstance(...); $app->httpHandler...`）。`WebRouter` が
  `GET /ping`→`Ping`、`POST /invocations`→`Invocations` をパスでルート（明示ルート定義 or 規約）
- [ ] body の生文字列渡しは `HttpMethodParamsInterface` の薄い差し替え（T6）で実現。標準 JSON 展開には載せない
- [ ] リソース request → `$ro->transfer($responder, $_SERVER)`（BEAR 標準）。`Invocations` は `transfer()` 上書きで SSE、他は標準
- [ ] real 接続エラー（キー無効等）は run 開始後＝HTTP 200 + `RUN_ERROR`（D11）。parse 失敗のみ 400（`ParseError`）

## T10. テスト（`tests/Integration/ExampleBear/`、D13/D22 流用）

- [ ] **プロセス内結合テスト**（HTTP を起こさない）：テスト用 Injector で `StreamingLlmClientInterface` を
  `tests/Fake/FakeStreamingLlmClient`（D13）に差し替え、`Invocations` リソースを Injector 経由で叩く
  - [ ] body の `iterable<AgUiEventInterface>` を drain し、フレーム列（`RUN_STARTED → … → RUN_FINISHED{success}` /
    ツールループ `TOOL_CALL_START→ARGS→END→RESULT`（`weather_get`）/ `reminder_put` で `RUN_FINISHED{interrupt}` /
    実行中エラー → `RUN_ERROR`）を検証
  - [ ] 検証：不正 JSON / 空 user content → `Invocations` が 400（body は Generator でない）
- [ ] **resource 駆動 dispatch の結合**：Fake LLM が `weather_get` tool_call を返す canned で、実 `Dispatcher` が
  `Weather` リソースを叩き結果が `TOOL_CALL_RESULT` に乗ることを確認（resource-as-tool の核を自動テスト）
- [ ] **手動 smoke**：端末1 で stub-llm（M2 流用）、端末2 で `php -S` で bear app、端末3 から curl → SSE 逐次到達を肉眼確認（D22：HTTP/SSE 逐次は自動化しない）
- [ ] ⚠️ S-a が NG（StreamedResourceObject 化）ならテストの body 取り出し方を合わせる

## T11. ガード（境界規則・要承認あり）

- [ ] `src/`（本体）→ `example/` を禁止
- [ ] `example/bear/` → `tests/` を禁止（Module/Provider が Fake を掴まない）
- [ ] `example/shared/` は `bear/tool-use` 型のみに依存（BEAR.Sunday 非依存＝M2 でも使えるよう保つ）
- [ ] ⚠️ **`mago guard` ルール追加はユーザー承認案件**（[feedback: linter rules は無断で触らない]）。要否・内容を確認してから

## T12. CI ＋ ドキュメント

- [ ] `phpunit.xml.dist` に integration suite（`tests/Integration/`）が example/bear を含むよう確認（M2 と共通）
- [ ] `composer tests`（`@sa` + `@test`）で `example/shared` `example/bear` も解析・テスト対象に入るか確認。⚠️ `mago analyze`/`phpmd`
  の対象パス拡張は T11 同様に承認案件
- [ ] `example/bear/README.md`：2 起動モード（stub 経由＝キー不要 / 本物 OpenAI）、curl 例、登場 3 ツール
  （`weather_get`=safe・通常 / `message_post`=unsafe・ポリシーで締め出し / `reminder_put`=idempotent・confirm→interrupt）、
  `safeAndIdempotent` ポリシーの効果、interrupt は real のみ
- [ ] `milestones.md` M3 の「詳細タスク: `tasks-m3.md`」リンクを有効化（現在「未作成」表記）
- [ ] `decisions.md` に D25〜D28 を追記（本ファイル末尾）

## T13. 仕上げ / DoD

- [ ] `composer tests`（mago format/lint/analyze/guard + phpmd + phpunit unit/integration）グリーン
- [ ] `composer crc` グリーン（example は autoload-dev に閉じ `require` 汚染なし）
- [ ] **手動 smoke**：実 BEAR アプリ起動 → `/invocations` が SSE 逐次返す（フレーム順・tool ループ・interrupt）を肉眼確認（DoD）
- [ ] 3 連続で integration グリーン（flake 無し）

---

## コミット粒度

作業ブランチ。**各コミット green**、依存順。メッセージは命令形・プレフィックス無し。**T0 スパイクは破棄**（コミットしない）。

| # | コミットメッセージ（案） | 含むタスク | green |
| --- | --- | --- | --- |
| C1 | `Add example/shared OpenAI streaming client for examples` | T1 + T2 | phpunit |
| C2 | `Add BEAR tool resources and ALPS profile` | T3 | phpunit |
| C3 | `Wire instrumented agent factory and ALPS processors for BEAR app` | T4 + T5 | phpunit |
| C4 | `Add Invocations/Ping resources and SSE transfer` | T6 + T7 + T8 | phpunit |
| C5 | `Add BEAR example front controller and integration tests` | T9 + T10 + T12(CI) | `composer tests` |
| C6 | `Document BEAR showcase example and link M3 tasks` | T12(docs) | — |

依存順：**C1 → C2 → C3 → C4 → C5 → C6**。T11（guard 追加）は承認後に別コミット。

---

## 本マイルストーンで確定した決定（decisions.md へ反映予定）

- **D25 M3 の入口は ResourceObject、SSE 配送は `Invocations::transfer()` オーバーライド**（T0 スパイクで確定）。`Invocations`
  が `onPost` で body に `AgUiRunner::stream()` の generator を置き、`transfer()` 上書きで body 型分岐：Generator → M1 既存の
  `SseResponder` + `PhpSapiSseSink` で配送、配列（ping/400）→ 標準 responder へ委譲。レンダリングは responder 内 `toString()`
  で遅延発火するため標準フローは generator を触らない＝専用 `SseTransfer` クラスも `#[SseStream]` 属性も不要。`bear/streamer` は
  ファイル差し込み用（flush 無し）で **SSE 不適**＝採用しない。
- **D26 ツールは `bear/tool-use` の resource 駆動 `Dispatcher` / `ToolRegistry` / `ToolCollector` / `#[Tool]` をそのまま使用**
  （自前ディスパッチャゼロ）。エージェントは bundled `StreamingAgentFactory`（recording 内蔵）に resource `Dispatcher`＋
  collect 済みツールを渡すだけ＝**custom factory も `AgentFactory`/`AgentPool` も不要**。collect は起動時 1 回（Provider）。
- **D27 ALPS 統治を M3 で実物化**。`AlpsSemanticDictionary(profilePath)` を `alps/profile.xml` から構築し、
  `AlpsToolPolicyInputProcessor::safeAndIdempotent()` ＋ `AlpsContextInputProcessor` を `AgUiRunner.$inputProcessors` へ。
  3 ツール（safe `weather_get` / unsafe `message_post` 締め出し / idempotent `reminder_put` confirm→interrupt）で
  ガバナンスと interrupt を 1 run 両立（未決点1=(b)）。**subagent（`AgentPool`）は不採用**。ALPS ツール供給自体は `bear/tool-use` 本体の責務。
- **D28 example の OpenAI 変換層は `example/shared/`（`Example\Shared\`）に置き M2/M3 で共有**（example→example の結合を避ける）。
  BEAR.Sunday 非依存に保ち、M2 の素サーバからも使える。

---

## スコープ外（M3 では扱わない）

- `bear/streamer` を使う pull 配送（将来チャレンジ・D25）
- AgentCore デプロイ・認証・CORS・並行スケーリング（ADR0005）
- state-as-resource / `STATE_SNAPSHOT` / `STATE_DELTA`（ADR0003）
- マルチエージェント / subagent（`AgentPool`・D27）
- 本物の interrupt / resume（ToolUse 側 resume API 待ち。confirm は `RUN_FINISHED{interrupt}` で終了）
- HTTP 経由の自動 e2e（手動 smoke で代替・D22）
