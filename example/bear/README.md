# Example BEAR showcase — resource-as-tool ＋ ALPS ＋ Swoole 並列

BEAR.Sunday スタイルのアプリから `AgUiRunner` を駆動する M3 のショーケースです。
M2(フレームワーク非依存・固定値ツール)には無い 4 点を実物で示します:

1. **resource-as-tool** — `#[Tool]` を付けた BEAR リソースがそのままエージェントの
   ツールになる。ツール名→リソース写像・宣言導出は `bear/tool-use` の
   `ToolCollector` / `ToolRegistry` / resource 駆動 `Dispatcher` が担う(自前ディスパッチャゼロ・D26)
2. **ALPS 統治** — [`alps/profile.xml`](alps/profile.xml) の descriptor type が
   `AlpsToolPolicyInputProcessor::safeAndIdempotent()` を駆動し、`unsafe` な
   `message_post` は **LLM に一度も提示されない**(D27)。`AlpsContextInputProcessor` が
   ツール/引数の意味論をコンテキストとして注入する
3. **BEAR ネイティブ SSE** — 入口は素の `ResourceObject`(`Invocations`)。`onPost()` が
   parse し、成功時は `AgUiRunner::stream()` の遅延 generator を `$body` に置くだけ。
   `transfer()` の上書きが body 型で分岐し、generator は `SseResponder` + `SwooleSseSink` で
   逐次配信、配列(400)は標準 JSON 経路に落ちる(D25)
4. **Swoole 並列ツール実行** — confirm 不要のツールが 1 ターンに複数呼ばれると
   `ParallelStreamingAgent` が `Swoole\Coroutine\WaitGroup` で**並列 dispatch** する(D29)。
   `weather_get` + `news_get`(各 200ms の擬似レイテンシ)が **wall-clock ≈ 1×200ms** で返る

## 前提: Swoole 必須

`WaitGroup` は per-request コルーチン文脈を要するため、このアプリは
`php -S` ではなく **`Swoole\Http\Server`**(`public/server.php`)で動きます(要 `ext-swoole`)。
並列実行はライブラリとしては **opt-in** です — 既定は逐次の `StreamingAgentFactory` のままで、
このアプリが `ParallelStreamingAgentFactory` を選んでいます(`Provider/AgentFactoryProvider`)。

並列ポリシー(`ParallelStreamingAgent`):

- **confirm なし(plain)** → `WaitGroup` で並列 dispatch
- **confirm あり** → 直列。`CONFIRMATION_REQUIRED` を yield し、承認されたら**並列波の前に直列実行**、
  拒否は cancelled。AG-UI 経路では adapter が `RUN_FINISHED{outcome:interrupt}` に写像して run を終える(D4)
- `tool_result` イベントは常に **pending 順**(confirm 無しなら逐次版と同一イベント列)

## 登場ツール(= BEAR リソース)

| ツール | リソース | ALPS type | ポリシー通過 | デモでの役割 |
| --- | --- | --- | --- | --- |
| `weather_get` | `app://self/weather` (GET) | `safe` | ✓ | 並列対象①(200ms 擬似レイテンシ) |
| `news_get` | `app://self/news` (GET) | `safe` | ✓ | 並列対象②(200ms 擬似レイテンシ) |
| `message_post` | `app://self/message` (POST) | `unsafe` | **✗ 締め出し** | ガバナンスのデモ(LLM に非提示) |
| `reminder_put` | `app://self/reminder` (PUT) | `idempotent` | ✓(confirm:true) | confirm→interrupt のデモ |

## 起動

端末 1 — スタブ LLM(API キー不要・決定論的):

```console
php -S 127.0.0.1:8081 -t example/stub-llm/public example/stub-llm/public/index.php
```

端末 2 — 本アプリ(Swoole・`OPENAI_BASE_URL` をスタブへ):

```console
OPENAI_BASE_URL=http://127.0.0.1:8081/v1 php example/bear/public/server.php
```

本物の OpenAI を使うなら `OPENAI_API_KEY=sk-...` だけ渡します(env は M2 と同一・D18)。
`AGUI_HOST` / `AGUI_PORT`(既定 `127.0.0.1:8080`)で listen 先を変えられます。

## 叩き方(端末 3)

デモ run は 2 つに分かれます(D29: interrupt は後続の並列波を実行前に終わらせるため、
並列と interrupt は同じ run で両立しません)。スタブはユーザー文中のキーワードでシナリオを選びます。

**並列 run** — `weather_get` + `news_get` が 1 ターンで呼ばれ、WaitGroup で並列実行:

```console
curl -N -X POST http://127.0.0.1:8080/invocations \
    -H 'Content-Type: application/json' \
    -d '{"threadId":"t-1","runId":"r-1","messages":[{"id":"m-1","role":"user","content":"Weather in Tokyo and the news, please."}]}'
```

両ツールの `TOOL_CALL_RESULT` が(各 200ms 待つのに)**ほぼ同時に・開始から約 200ms で**届き、
`RUN_FINISHED{success}` で閉じます。逐次なら 400ms かかるところです。

**interrupt run** — `reminder_put`(confirm:true)が呼ばれ、実行前に run が interrupt 終了:

```console
curl -N -X POST http://127.0.0.1:8080/invocations \
    -H 'Content-Type: application/json' \
    -d '{"threadId":"t-1","runId":"r-2","messages":[{"id":"m-1","role":"user","content":"Remind me to buy milk."}]}'
```

`RUN_FINISHED{outcome:{type:"interrupt",...}}` で終わり、`Reminder` リソースは実行されません
(本物の resume は ToolUse 側 API 待ち・D4)。

**ガバナンス** — どちらの run でも、LLM へのリクエストに載る tools は
`weather_get` / `news_get` / `reminder_put` の 3 つだけです。`message_post` は
`safeAndIdempotent` ポリシーが常に締め出します(実装とテスト:
[`tests/Integration/ExampleBear/InvocationsTest.php`](../../tests/Integration/ExampleBear/InvocationsTest.php))。

`GET /ping` は標準 JSON(SSE 非対象)、パース失敗は 400 JSON(全 ParseError 集約・D24)です。

## チャット UI(ブラウザ・M5)

上記 2 プロセス(スタブ LLM + 本アプリ)を起動したら、ブラウザで
<http://127.0.0.1:8080/> を開くだけでチャット UI が使えます([`public/chat.html`](public/chat.html))。
デモ入力は curl の 2 シナリオと同じです("Weather in Tokyo and the news, please." が並列 run、
"Remind me to buy milk." が interrupt run)。

- **ビルド不要** — react / react-dom(UMD)と @babel/standalone を CDN スクリプトタグで読み、
  JSX を `<script type="text/babel">` に直書きした 1 ファイル完結の静的ページです(D31)。
  Node/npm・ビルドパイプラインは一切使いません
- **同一オリジン配信** — `server.php` の `GET /` が `chat.html` をそのまま返すため、
  `/invocations` への `fetch()` に CORS 対応は不要です(毎リクエスト読み直すので
  HTML を編集したらリロードだけで反映されます)
- **SSE は `fetch()` + `ReadableStream` の自前パース** — AG-UI は POST + JSON body 必須のため
  `EventSource` は使えません。M4 CLI クライアントの `SseFrameReader` / `ConversationLog`(PHP)と
  同型のロジックを JS で再実装しています(プラットフォームが違うためコードは共有しません)
- **会話履歴はブラウザが正本** — サーバはステートレス(D15)。観測した SSE イベントから
  AG-UI `messages[]` を組み立て、毎 run 全件を再送します(devtools のネットワークタブで
  2 ターン目以降のリクエスト body に全履歴が載っているのが確認できます)
- **resume 非対応** — interrupt run は interrupt メッセージを表示してターンを終えるだけで、
  再開はできません(v1 の既知の制約・D4)

## 構造(どこを読むか)

```
public/server.php               Swoole\Http\Server → ResourceInterface(worker で Injector を 1 回構築)
public/chat.html                ビルド不要 React チャット UI(M5・D31。GET / が配信)
src/Module/AppModule.php        ResourceModule + ToolUseModule + AgUiModule + env LLM
src/Module/AgUiModule.php       ALPS 辞書 / processors / AgUiRunner / SSE 部品の束縛
src/Provider/AgentFactoryProvider.php  起動時 1 回 collect → ParallelStreamingAgentFactory
src/Resource/Page/Invocations.php      onPost=parse→stream、transfer() 上書きで SSE 配送
src/Resource/App/*.php          #[Tool] 付きツールリソース 4 本
src/Transfer/SwooleResponder.php       標準(JSON)経路 + SSE sink への Swoole Response 供給
alps/profile.xml                ツール統治の ALPS プロファイル
```
