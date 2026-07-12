# Example CLI client — plain HTTP+SSE consumer of AG-UI (M4, D30)

M0〜M3 はすべて AG-UI イベントを**生成する側**(サーバ)でした。この CLI は初めて**消費する側**を
実物で示します。対象は M3 の BEAR Swoole アプリ([`example/bear/`](../bear/))です。

**本ライブラリの PHP 型には一切依存しません**(`NaokiTsuchiya\BEARAgUi\` を import しない・D30)。
実世界の AG-UI クライアント(ブラウザ・別言語 SDK)は当然サーバ側 PHP 型を知り得ないため、
[`docs/reference/ag-ui-protocol.md`](../../docs/reference/ag-ui-protocol.md) のワイヤ仕様(JSON
フィールド)だけを頼りに SSE を自前でデコードします。`mago guard` がこの制約を機械的に強制します
(`Example\CliClient\` の許可リストは `@native`/`@self` のみ)。

会話履歴は **client 側が正本**です(AG-UI はサーバ側ステートレス)。観測した SSE イベントから
AG-UI `messages[]` を毎ターン組み立て直し、次の run に全件再送します。

## 前提: 依存を先に起動する

端末 1 — スタブ LLM(API キー不要・決定論的):

```console
php -S 127.0.0.1:8081 -t example/stub-llm/public example/stub-llm/public/index.php
```

端末 2 — M3 の BEAR Swoole アプリ:

```console
OPENAI_BASE_URL=http://127.0.0.1:8081/v1 php example/bear/public/server.php
```

詳細は [`example/bear/README.md`](../bear/README.md) を参照してください。

上記 2 つと本 CLI の起動・3 シナリオ実行・後片付けを 1 コマンドでまとめた便利スクリプトもあります
(`composer tests` の対象外・単なる手動 smoke のラッパー):

```console
example/cli-client/bin/smoke-test.sh
```

## 起動(端末 3)

接続先は `AGUI_BASE_URL` env で指定します(既定 `http://127.0.0.1:8080`。`OPENAI_BASE_URL` と
同じ流儀)。

**REPL モード**(引数無し。標準入力を 1 行ずつ読み、EOF まで繰り返す):

```console
php example/cli-client/bin/agui-chat.php
```

**1 発モード**(引数にメッセージを渡すと 1 run だけ実行して終了。スクリプト/手動 smoke 向け):

```console
php example/cli-client/bin/agui-chat.php "Weather in Tokyo and the news, please."
```

別ホストに向けるには:

```console
AGUI_BASE_URL=http://127.0.0.1:9000 php example/cli-client/bin/agui-chat.php
```

## 表示されるもの

- `TEXT_MESSAGE_CONTENT.delta` — 都度 `echo`(バッファしないストリーミング表示)
- `TOOL_CALL_START` / `TOOL_CALL_RESULT` — `[tool] ...` の 1 行サマリ
- `RUN_FINISHED{outcome:interrupt}` — `interrupts[].message` を表示してターン終了
- `RUN_ERROR` — `[error] ...` の 1 行

## 既知の制約: resume 非対応

`reminder_put` のような confirm 付きツールが呼ばれると、run は実行前に
`RUN_FINISHED{outcome:{type:"interrupt"}}` で終わります。**本 CLI は再開(resume)をサポートしません**
(ToolUse 側の resume API 待ち・D4)。interrupt メッセージを表示したらそのターンは終わり、
次の入力でユーザーが別の指示を出せるだけです。

## 構造

```
bin/agui-chat.php      エントリポイント(REPL / 1発モード)
bin/smoke-test.sh       手動 smoke の便利スクリプト(サーバ起動〜3 シナリオ〜後片付け)
src/SseFrameReader.php  バイト列 → 完成した SSE フレーム → JSON デコード
src/AgUiHttpClient.php  curl + CURLOPT_WRITEFUNCTION でストリーミング POST
src/EventRenderer.php   デコード済みイベント → ターミナル出力
src/ConversationLog.php 観測イベント列 → 次 run に載せる messages[]
src/Cli.php             オーケストレーション(REPL ループ / 1発モード)
```
