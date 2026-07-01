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
- M1 の **`StreamingAgentFactory`** が recording デコレータ配線（D10）を内蔵。⚠️ **D29 で改訂**：M3 は複数ツールの
  **Swoole 並列実行**を行うため、`StreamingAgentFactory` ではなく自前 **`ParallelStreamingAgentFactory`**（[`tasks-parallel-tools.md`](tasks-parallel-tools.md) T3）を使う。
  `AgentFactory`/`AgentPool` を使わない点は不変。逐次が必要な箇所のみ `StreamingAgentFactory` を選べる（opt-in）。
- SSE 配送は M1 の **`SseResponder`** を再利用するが、⚠️ **D29 で sink は `SwooleSseSink`**（`$response->write()` + `flush()`・
  [`tasks-parallel-tools.md`](tasks-parallel-tools.md) T5）に差し替える。`PhpSapiSseSink` は非 Swoole 経路用に残置。
  M3 は `Invocations::transfer()` を上書きしてそれを呼ぶだけ（専用 Transfer クラス不要・T0 で確定）。
- **M2 は未実装**（`example/` 無し＝tasks-m2 は計画のみ）。OpenAI 変換層は M2/M3 共有のため `example/shared/` に置く（D28）。
- ⚠️ **ランタイムは Swoole 前提**（D29）。`WaitGroup` は per-request coroutine 文脈を要するため `php -S`/標準 SAPI では動かない。
  **先行スパイク S-d で検証済み＝OK**（`Swoole\Http\Server` → BEAR リソース並列＋SSE 逐次配信が成立・下記 T0'）。残る未検証は複雑 DI のレースのみ（T5 B）。

新規依存（`require-dev`・本体 `require` は汚さない）：BEAR.Sunday 一式（`bear/package` / `bear/resource` / `ray/di`）と
`openai-php/client ^0.20`（D18）、`ext-swoole`（D29・`suggest` にも明記）。`bear/tool-use` は本体 `require` に既存。

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
    ├── public/server.php            ⚠️ D29：Swoole\Http\Server bootstrap → POST /invocations, GET /ping（旧 index.php/php -S）
    ├── src/
    │   ├── Module/AppModule.php          install ToolUseModule + ResourceModule + 束縛集約
    │   ├── Module/AgUiModule.php         AgUiRunner / SseEncoder / SseResponder / SwooleSseSink / AgUiAdapter / factory provider
    │   ├── Resource/Page/Invocations.php onPost: parse → body=stream() の Generator / transfer() 上書きで SSE 配送
    │   ├── Resource/Page/Ping.php        onGet: {status:"Healthy"}
    │   ├── Resource/App/Weather.php      #[Tool('weather_get', confirm:false)] onGet（ALPS safe・plain＝並列①）
    │   ├── Resource/App/News.php         #[Tool('news_get', confirm:false)] onGet（ALPS safe・plain＝並列②・D29 で追加）
    │   ├── Resource/App/Message.php      #[Tool('message_post', confirm:false)] onPost（ALPS unsafe → ポリシーで締め出し）
    │   ├── Resource/App/Reminder.php     #[Tool('reminder_put', confirm:true)] onPut（ALPS idempotent → confirm→interrupt）
    │   └── Provider/AgentFactoryProvider.php  ToolCollector->collect(uris) → ParallelStreamingAgentFactory（D29）
    ├── alps/profile.xml             ALPS（descriptor safe/idempotent/unsafe）
    ├── var/{tmp,log}
    └── README.md
```

- **入口は ResourceObject**（薄いハンドラ案は却下＝中身が M2 と同化するため・milestones M3）。
- **配送は `Invocations::transfer()` オーバーライド**（T0 で確定）：body が Generator なら M1 既存の `SseResponder` +
  ⚠️ **`SwooleSseSink`**（D29・旧 `PhpSapiSseSink`）で配送、配列（ping/400）なら標準 responder に委譲。属性も専用 Transfer クラスも不要。`bear/streamer` は不適。
- ⚠️ **ランタイムは Swoole 前提**（D29）：複数 plain ツールを `WaitGroup` で並列実行。起動経路は先行スパイク **S-d（T0'）** で確定してから実装。
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

## T0'. 先行スパイク S-d（**実施済み＝OK・結果を以降へ反映**・D29）

worktree `spike/sd-swoole` に `bear/resource` + `ext-swoole 6.2` で最小 BEAR アプリ（`Swoole\Http\Server` → `ResourceInterface`）を組んで検証。
**結論＝骨格は変えない（Swoole で BEAR を駆動でき、per-request coroutine 並列＋SSE 逐次配信が成立）**。T7/T9/T10/T13 の記述はこの結果と整合。

- [x] **S-d.1 起動経路＝OK**：`onRequest(Swoole\Http\Request,Response)` から worker で1回組んだ `Injector`（`ResourceModule`）の
  `ResourceInterface::get('app://self/weather')` を叩けた。`bear/package` の WebRouter/SAPI 抜きで BEAR リソースが解決（`/ping` も 200）
- [x] **S-d.2 per-request coroutine＝OK**：`onRequest` 内で `go()` + `Swoole\Coroutine\WaitGroup` が動作（`enable_coroutine=true`）
- [x] **S-d.3 並列＝OK**：weather+news（各 usleep 200ms）を並行 dispatch し **wall-clock 201〜203ms**（逐次なら 400ms）＝実 overlap。
  同一 worker（pid 一致）で 2 リソースが並行実行され**クラッシュ/破損なし**。⚠️ ただし検証は**単純リソース2本**＝**複雑な DI シングルトン/共有
  可変状態のレースは未検証**（本番リソースで再確認＝T5 B に残す。NG 時は per-coroutine スコープ/リソース複製）
- [x] **S-d.4 SSE flush＝OK**：`Swoole\Http\Response::write()` で **2 波に分かれて到達**（0ms＝RUN_STARTED+START群 / 201ms＝RESULT群）
  ＝末尾一括でなく**逐次配信**。`$res->end()` で close。⚠️ 小さな write の TCP コアレッシング（news START が 201ms 波に混入）を観測＝
  本番は `SwooleSseSink` で `TCP_NODELAY`/確実な即時送出を確認する（`flush()` は Swoole では write 単位送出のため必須ではない）
- [x] ⚠️ 反映済み：起動経路は **`public/server.php`（`Swoole\Http\Server`）**（T9）、sink は **`SwooleSseSink`**（T7・tasks-parallel T5）。スパイクは破棄

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

「通常呼び出し」「ALPS ポリシーによる締め出し」「confirm→interrupt」に加え、⚠️ **D29 で「複数 plain ツールの並列実行」を追加実証**する。
そのため **plain（confirm なし・ポリシー通過）ツールを 2 つ**用意する（weather だけでは並列が見せられない＝旧 3 ツール構成の不足）。

- [ ] `Resource/App/Weather.php`：`#[Tool(name:'weather_get', confirm:false)]` を `onGet(string $city)` に付与。
  副作用なしの照会（**ALPS で safe** → ポリシー通過＝**plain・並列対象①**）
- [ ] `Resource/App/News.php`（**D29 で追加**）：`#[Tool(name:'news_get', confirm:false)]` を `onGet(string $topic)` に付与。
  副作用なしの照会（**ALPS で safe** → ポリシー通過＝**plain・並列対象②**）。weather と 1 ターンで同時呼び出しさせ `WaitGroup` 並列を実証
- [ ] `Resource/App/Message.php`：`#[Tool(name:'message_post', confirm:false)]` を `onPost(string $to, string $body)` に付与。
  送信＝副作用あり（**ALPS で unsafe** → `safeAndIdempotent` ポリシーで**LLM に提示されない**＝ガバナンスのデモ）
- [ ] `Resource/App/Reminder.php`：`#[Tool(name:'reminder_put', confirm:true)]` を `onPut(string $id, string $text)` に付与。
  冪等な設定（**ALPS で idempotent** → ポリシー通過）。**confirm:true ＝ 実行前に `CONFIRMATION_REQUIRED` を誘発**し、
  M1 アダプタが `RUN_FINISHED{outcome:interrupt}` に写像
- [ ] ⚠️ **デモ run を分割**（D29・confirm→interrupt と並列波は同 run で両立しない＝interrupt は後続 plain 波を実行前に終わらせるため）：
  - **並列 run**：`weather_get` ＋ `news_get` を 1 ターンで → `WaitGroup` で並列 dispatch（wall-clock ≈ 1×latency）
  - **interrupt run**：`reminder_put` を呼ばせ confirm→interrupt（並列とは別 run）
  - ガバナンス（`message_post` 締め出し）は両 run で常時観測
- [ ] `alps/profile.xml`：descriptor を `safe`（weather・news）/`unsafe`（message）/`idempotent`（reminder）で記述。
  `AlpsSemanticDictionary` が読む形式（`simplexml_load_string` 前提のプロファイル XML）
- [ ] ⚠️ ツール名とリソースの対応は `ToolCollector` が `#[Tool]`＋URI から導出（`weather_get` → `app://self/weather` GET 等）。
  命名は `ToolCollector::extractMethodFromToolName` の規約（`{path}_{method}` or `_get`/`_post` サフィックス）に合わせる
- [ ] テスト：各リソースを Injector 経由で叩き、戻り値（`$ro->body` / `code`）と `#[Tool]` メタを確認

## T4. エージェント構築の配線（`Provider/AgentFactoryProvider.php`）

⚠️ **D29 で改訂**：bundled `StreamingAgentFactory` ではなく自前 **`ParallelStreamingAgentFactory`**（[`tasks-parallel-tools.md`](tasks-parallel-tools.md) T3）に
resource 駆動 `Dispatcher` と collect 済みツールを渡す。`AgentFactory`/`AgentPool` を使わない点は不変。

- [ ] `AgentFactoryProvider implements ProviderInterface<InstrumentedAgentFactory>`
  - [ ] DI で `StreamingLlmClientInterface`（= `OpenAiStreamingLlmClient`）, `BEAR\ToolUse\Dispatch\DispatcherInterface`
    （= bear/tool-use の resource 駆動 `Dispatcher`）, `ToolCollectorInterface`, systemPrompt を受ける
  - [ ] **起動時 1 回** `collector->collect([<resource URIs>])` で `list<Tool>` を得る（副作用で `ToolRegistry` も充填）
  - [ ] `new ParallelStreamingAgentFactory($client, $dispatcher, $tools, $systemPrompt)` を返す（app 単一・D29）
- [ ] ⚠️ collect 対象 URI（`app://self/weather`, `app://self/news`, `app://self/message`, `app://self/reminder`）は 1 箇所で定義し Provider と ALPS で共有
- [ ] ⚠️ recording デコレータ（`RecordingStreamingLlmClient`/`RecordingDispatcher`）は `ParallelStreamingAgentFactory::newInstance()`
  が per-run で巻く。Provider は**素の** client/dispatcher を渡すだけ（S5）。⚠️ recording 層は **id キー化＋コルーチン安全**（tasks-parallel T1）が前提
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
    `$this->sseResponder->respond($this->body, $sink)`。⚠️ **D29：`$sink` は `SwooleSseSink`**（`Swoole\Http\Response` を `write()`+`flush()`・
    [`tasks-parallel-tools.md`](tasks-parallel-tools.md) T5）。`PhpSapiSseSink` は非 Swoole 経路用に残置
  - [ ] それ以外（ping は別リソース／400 は配列 body）→ `$responder($this, $server)`（渡された標準 responder に委譲）
- [ ] `Invocations` に `SseResponder` を注入（`#[Inject]` or ctor）。`SseResponder`/`SseEncoder` は M1 既存、sink は D29 で `SwooleSseSink` 追加
- [ ] ⚠️ **Swoole では request/response が `Swoole\Http\*`**：T6 の生 body 取得（`php://input`）と sink の出力先は `onRequest` から渡る
  `Swoole\Http\Request`/`Response` に依存＝**S-d（T0'）の起動経路の結論に従う**。`Content-Type: text/event-stream` / `Content-Length` 無し /
  **フレーム毎 flush** は `SwooleSseSink::send()` で担保し T13 smoke で確認
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

## T9. ブートストラップ（`example/bear/public/server.php`）— ⚠️ D29：Swoole HTTP サーバに乗せる

⚠️ **D29 で全面改訂**（旧：`php -S` ＋ `WebRouter` の標準 SAPI）。`WaitGroup` 並列は per-request coroutine を要するため、
**長時間稼働の `Swoole\Http\Server`** から BEAR を駆動する。**起動経路の骨格は先行スパイク S-d（T0'）の結論に従う**。

- [ ] `public/server.php`：`Swoole\Http\Server` を起動。`Injector::getInstance(...)` で **app を1回組み（worker 内で使い回す）**、
  `onRequest(Swoole\Http\Request $req, Swoole\Http\Response $res)` でリクエストを受ける（`onRequest` は coroutine 文脈＝並列の前提）
- [ ] ルーティング：`GET /ping`→`Ping`、`POST /invocations`→`Invocations`（パスで分岐。標準 `WebRouter` を使うか自前分岐かは S-d の結論で確定）
- [ ] body の生文字列は `Swoole\Http\Request::getContent()` から取得し T6 の検証境界（`parse(string)`）へ。⚠️ `php://input`/`$_SERVER` 依存を排除
- [ ] リソース request → `$ro->transfer($responder, $server)`。`Invocations` は `transfer()` 上書きで `SwooleSseSink`（`$res` を write/flush）、他は標準
- [ ] ⚠️ `Swoole\Runtime::enableCoroutine()` を有効化（ブロッキング I/O をフック）。範囲とレースは S-d.3 で確認済み前提
- [ ] real 接続エラー（キー無効等）は run 開始後＝HTTP 200 + `RUN_ERROR`（D11）。parse 失敗のみ 400（`ParseError`）

## T10. テスト（`tests/Integration/ExampleBear/`、D13/D22 流用）

- [ ] **プロセス内結合テスト**（HTTP を起こさない）：テスト用 Injector で `StreamingLlmClientInterface` を
  `tests/Fake/FakeStreamingLlmClient`（D13）に差し替え、`Invocations` リソースを Injector 経由で叩く
  - [ ] body の `iterable<AgUiEventInterface>` を drain し、フレーム列（`RUN_STARTED → … → RUN_FINISHED{success}` /
    ツールループ `TOOL_CALL_START→ARGS→END→RESULT`（`weather_get`）/ `reminder_put` で `RUN_FINISHED{interrupt}` /
    実行中エラー → `RUN_ERROR`）を検証
  - [ ] ⚠️ **D29 並列の結合**：Fake LLM が 1 ターンで `weather_get` ＋ `news_get` を返す canned で、両 `TOOL_CALL_*` が
    **各 id で正しくペア**になること（順不同許容＝並列のため・registry id キー化 tasks-parallel T1 の確認）。Swoole コルーチン文脈で実行
  - [ ] 検証：不正 JSON / 空 user content → `Invocations` が 400（body は Generator でない）
- [ ] **resource 駆動 dispatch の結合**：Fake LLM が `weather_get` tool_call を返す canned で、実 `Dispatcher` が
  `Weather` リソースを叩き結果が `TOOL_CALL_RESULT` に乗ることを確認（resource-as-tool の核を自動テスト）
- [ ] **手動 smoke**：端末1 で stub-llm（M2 流用）、端末2 で **Swoole サーバ**（`php public/server.php`・D29）で bear app、
  端末3 から curl → SSE 逐次到達を肉眼確認（D22：HTTP/SSE 逐次は自動化しない）。**並列 run**（weather+news）と **interrupt run**（reminder）を別々に流す
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
- [ ] `example/bear/README.md`：2 起動モード（stub 経由＝キー不要 / 本物 OpenAI）、curl 例、登場 4 ツール
  （`weather_get`/`news_get`=safe・**plain＝並列対象** / `message_post`=unsafe・ポリシーで締め出し / `reminder_put`=idempotent・confirm→interrupt）、
  `safeAndIdempotent` ポリシーの効果、⚠️ **D29：Swoole 必須・並列ポリシー（plain 並列 / confirm 直列）・並列 run と interrupt run は別 run**、interrupt は real のみ
- [ ] `milestones.md` M3 の「詳細タスク: `tasks-m3.md`」リンクを有効化（現在「未作成」表記）
- [ ] `decisions.md` に D25〜D28 を追記（本ファイル末尾）。D29 は追記済み

## T13. 仕上げ / DoD

- [ ] `composer tests`（mago format/lint/analyze/guard + phpmd + phpunit unit/integration）グリーン
- [ ] `composer crc` グリーン（example は autoload-dev に閉じ `require` 汚染なし）
- [ ] **手動 smoke**：実 BEAR アプリを **Swoole サーバ**（`php public/server.php`・D29）で起動 → `/invocations` が SSE 逐次返す
  （フレーム順・tool ループ・**weather+news の並列**・reminder の interrupt）を肉眼確認（DoD）
- [ ] 3 連続で integration グリーン（flake 無し）
- [ ] ⚠️ **S-d（T0'）完了済み**であること＝BEAR on Swoole 起動・per-request coroutine・リソースのコルーチン安全性が検証済み（D29）

---

## コミット粒度

作業ブランチ。**各コミット green**、依存順。メッセージは命令形・プレフィックス無し。**T0/T0'（S-a/S-b/S-d）スパイクは破棄**（コミットしない）。

⚠️ **前提（D29）**：本 M3 commit 群の前に、ライブラリ側 [`tasks-parallel-tools.md`](tasks-parallel-tools.md) の C1〜C3
（registry id キー化 / `ParallelStreamingAgent` / `ParallelStreamingAgentFactory`）が **先に landing 済み**であること。さらに **S-d（T0'）完了**が T1 着手条件。

| # | コミットメッセージ（案） | 含むタスク | green |
| --- | --- | --- | --- |
| C1 | `Add example/shared OpenAI streaming client for examples` | T1 + T2 | phpunit |
| C2 | `Add BEAR tool resources and ALPS profile` | T3（4 ツール・news 追加） | phpunit |
| C3 | `Wire parallel agent factory and ALPS processors for BEAR app` | T4 + T5 | phpunit |
| C4 | `Add Invocations/Ping resources and SSE transfer` | T6 + T7 + T8 | phpunit |
| C5 | `Add BEAR Swoole server and integration tests` | T9 + T10 + T12(CI) | `composer tests` |
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
- **D29（[`decisions.md`](decisions.md) に追記済み・本ファイルは反映）複数 plain ツールの Swoole 並列実行を追加**。これにより
  上記を一部**改訂**：D25 の sink は `PhpSapiSseSink`→**`SwooleSseSink`**／D26 の factory は bundled `StreamingAgentFactory`→
  **`ParallelStreamingAgentFactory`**（`AgentFactory`/`AgentPool` 不使用は不変）／D27 の「1 run 両立」は **並列 run と interrupt run に分割**
  （plain 2 本=`weather_get`/`news_get` を並列、`reminder_put` の confirm→interrupt は別 run）。ランタイムは Swoole 前提（起動経路は S-d で確定）。

---

## スコープ外（M3 では扱わない）

- `bear/streamer` を使う pull 配送（将来チャレンジ・D25）
- AgentCore デプロイ・認証・CORS・並行スケーリング（ADR0005）
- state-as-resource / `STATE_SNAPSHOT` / `STATE_DELTA`（ADR0003）
- マルチエージェント / subagent（`AgentPool`・D27・D29 でも不採用据え置き）
- 本物の interrupt / resume（ToolUse 側 resume API 待ち。confirm は `RUN_FINISHED{interrupt}` で終了）
- HTTP 経由の自動 e2e（手動 smoke で代替・D22）
- 上流 `bear/tool-use` への `dispatchBatch` seam（D29 は自前ループで先行・将来 PR）
- ツール間 arguments interleave のストリーミング並列（bear 単数 `currentToolId` 制約・D19/D29）
