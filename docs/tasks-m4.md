# M4 実装タスク（詳細）

example③：**PHP CLI クライアント**。M0〜M3 は全て AG-UI イベントを**生成する側**（サーバ）だったが、
M4 は初めて**消費する側**を実物で示す。対象は M3 の BEAR Swoole アプリ（`example/bear/`）。

[`milestones.md`](milestones.md) M4 / [`decisions.md`](decisions.md) D30 を実装タスクに落としたもの。
末尾 `(Dxx)` は根拠。`⚠️` は実装時に実物で確認する点。

**前提（grounding 済み）**:

- M3 の BEAR Swoole アプリ（`POST /invocations` + `GET /ping`）が動いている前提（[`example/bear/README.md`](../example/bear/README.md)）。
  クライアントは env `AGUI_BASE_URL`（既定 `http://127.0.0.1:8080`）で接続先を切り替える（D30・D18 と同じ流儀）。
- **クライアントは本ライブラリの PHP 型に一切依存しない**（D30）。`NaokiTsuchiya\BEARAgUi\Event\*` も
  `Input\*` も import しない。[`reference/ag-ui-protocol.md`](reference/ag-ui-protocol.md) のワイヤ仕様
  （フィールド表）だけを読んで独自にデコードする。実世界の AG-UI クライアント（ブラウザ／別言語）と同じ立場。
- **AG-UI はサーバ側ステートレス**（D15）＝ client が会話履歴の正本。各 run で観測した SSE イベントから
  `messages[]` を組み立て、次 run に**全件再送**する（`MessageHistoryMapper` の逆方向、client 側実装）。
- **本物の resume は v1 スコープ外のまま**（D4）。`RUN_FINISHED{outcome:interrupt}` はターン終了として扱い、
  再開はしない（次の入力でユーザーが別の指示を出せるだけ）。

新規依存：**なし**（`ext-curl` は PHP 標準拡張。追加 Composer パッケージ不要）。

---

## 設計サマリ（M4 で作る姿）

```
example/cli-client/
├── public/                      （無し・CLI のため）
├── bin/agui-chat.php            エントリポイント（引数 or REPL でユーザー入力を受ける）
├── src/
│   ├── SseFrameReader.php       バイト列（チャンク）→ 完成した SSE フレーム（"data: {json}\n\n" 単位）を切り出す
│   ├── AgUiHttpClient.php       curl + CURLOPT_WRITEFUNCTION で POST /invocations をストリーミング読み取り
│   ├── EventRenderer.php        1 イベント（デコード済み連想配列）→ ターミナル出力（即時 echo・バッファしない）
│   ├── ConversationLog.php      観測イベント列 → AG-UI messages[]（次 run に載せる会話履歴）を蓄積
│   └── Cli.php                  bin/ から呼ばれるオーケストレーション（REPL ループ／1 発モード）
└── README.md
```

- **入口は 2 モード**：引数にメッセージを渡せば 1 発 run（スクリプト/手動 smoke 向け）、引数無しなら
  対話 REPL（1 行入力 → 1 run → イベント逐次表示 → 次の入力待ち、を EOF まで繰り返す）
- **`threadId` はプロセス起動時に 1 回生成しセッション固定**、`runId` は毎 run 新規生成（M1 の役割分担どおり
  client が両方発行する。サーバは検証するだけ）
- **結合テストは HTTP を起こさない**（D22 踏襲）：`SseFrameReader` と `ConversationLog` は純粋関数的に
  ユニットテストできる形にする（curl 呼び出しから分離）。実 HTTP の動作確認は手動 smoke

---

## T1. ディレクトリ骨格 ＋ autoload（D30）

- [ ] `example/cli-client/`（`bin/` `src/` `README.md`）を作成
- [ ] ルート `composer.json` `autoload-dev` に追記：`"Example\\CliClient\\": "example/cli-client/src/"`
- [ ] ⚠️ **本体 `autoload`（`NaokiTsuchiya\BEARAgUi\`）には混ぜない**。example は配布物に含めない
- [ ] 新規 Composer 依存は無し。`ext-curl` を使う旨を README に明記（大半の PHP ビルドに標準同梱）

## T2. SSE フレームリーダー（`src/SseFrameReader.php`）

サーバ側 `SseEncoder`（書き込み側）の対になる、読み取り側の新規コンポーネント。**チャンク境界と
SSE フレーム境界は一致しない**（1 チャンクに複数フレーム／1 フレームが複数チャンクに跨ることがある）
前提で実装する。

- [ ] `SseFrameReader::feed(string $chunk): list<string>`：内部バッファに追記し、`\n\n` で終わる
  完成フレームだけを切り出して返す（未完成の残りは次回 `feed()` のためにバッファに保持）
- [ ] `SseFrameReader::decode(string $frame): array|null`：`"data: "` プレフィックスを剥がして
  `json_decode(..., true)`。`data: [DONE]` 等の非 JSON行や空フレームは `null`
- [ ] テスト：①1 チャンク=1 フレーム ②1 チャンクに複数フレーム ③1 フレームが 2 チャンクに分割
  ④末尾の不完全フレームが次の `feed()` で完成する ⑤空行・コメント行（`:` 始まり）の無視

## T3. HTTP クライアント（`src/AgUiHttpClient.php`）

- [ ] `AgUiHttpClient::__construct(string $baseUrl)`（`AGUI_BASE_URL` env から `Cli.php` が注入）
- [ ] `AgUiHttpClient::stream(array $body, callable $onEvent): void`：
  - [ ] curl で `POST {$baseUrl}/invocations`、`Content-Type: application/json`、body は `json_encode($body)`
  - [ ] `CURLOPT_WRITEFUNCTION` でレスポンスチャンクを受け取るたびに `SseFrameReader::feed()` に渡し、
    完成フレームを `decode()` → 非 null なら `$onEvent($decoded)` を都度呼ぶ（**バッファしない**＝
    サーバ側の逐次 flush をクライアント側でも逐次消費することを実証する）
  - [ ] HTTP ステータス 400（parse 失敗）は SSE ではなく単発 JSON ボディ＝`WRITEFUNCTION` 経由でも
    `SseFrameReader` が黙って何も frame を返さないだけになるので、**Content-Type を見て分岐**
    （`application/json` なら生ボディを 1 回 decode してエラー表示、`text/event-stream` なら上記の逐次処理）
- [ ] テスト：curl 呼び出し自体は結合テスト（HTTP 無し方針・D22）の対象外。`WRITEFUNCTION` コールバックへの
  受け渡しロジックは `SseFrameReader` 側のテストでカバー済みとし、本クラスは手動 smoke でのみ検証

## T4. 会話ログ（`src/ConversationLog.php`）

M1 `MessageHistoryMapper`（`messages[]` → ToolUse `Message[]`、サーバ側）の**逆方向**：観測した
AG-UI イベント列から、次 run に載せる AG-UI `messages[]`（ワイヤ形式そのもの、連想配列）を組み立てる。

- [ ] `ConversationLog::appendUser(string $text): void`：`{id, role:"user", content:$text}` を追記
- [ ] `ConversationLog::observe(array $event): void`：1 run 分のデコード済みイベントを順に食わせ、
  内部状態を更新する
  - [ ] `TEXT_MESSAGE_START`→ 新規 assistant メッセージを開く（`id=messageId, role:"assistant", content:''`）
  - [ ] `TEXT_MESSAGE_CONTENT`→ 該当 assistant メッセージの `content` に `delta` を追記
  - [ ] `TEXT_MESSAGE_END`→ 開いている assistant メッセージを確定（追記のみで特別な処理は無し）
  - [ ] `TOOL_CALL_START`→ 直近の assistant メッセージに `toolCalls[]` エントリを追加
    （`{id:toolCallId, type:"function", function:{name:toolCallName, arguments:''}}`）
  - [ ] `TOOL_CALL_ARGS`→ 対応する `toolCalls[].function.arguments` に `delta` を追記
  - [ ] `TOOL_CALL_RESULT`→ 新規 `ToolMessage`（`{id:messageId, role:"tool", content, toolCallId}`）を追記
  - [ ] `RUN_FINISHED`/`RUN_ERROR`→ 状態リセット（開いているメッセージがあれば確定して次 run に備える）
- [ ] `ConversationLog::toMessages(): array`：蓄積した `messages[]`（今まさに送る trigger を含まない、
  **これまでの履歴のみ**）を返す
- [ ] テスト：①テキストのみの 1 往復 ②単一ツール呼び出し込みの 1 往復（`toolCalls` + `ToolMessage` の
  組み立て）③並列ツール（複数 `TOOL_CALL_START` が interleave）④2 ターン目の `messages[]` に 1 ターン目の
  全履歴が正しい順序・形式で載ること

## T5. イベントレンダラー（`src/EventRenderer.php`）

- [ ] `EventRenderer::render(array $event): void`：即時 `echo`（バッファしない・`ob_*` 未使用）
  - [ ] `TEXT_MESSAGE_CONTENT.delta` をそのまま逐次 echo（ストリーミング表示）
  - [ ] `TOOL_CALL_START`→ `[tool] {toolCallName} …` の 1 行
  - [ ] `TOOL_CALL_RESULT`→ `[tool] {toolCallId} -> {content}` の 1 行（長い content は要約表示）
  - [ ] `RUN_FINISHED`（`outcome.type==="interrupt"`）→ `interrupts[].message` を表示し、
    「このツール呼び出しは再開できません（v1 の既知の制約）」の注記を出す（D4・resume 非対応を隠さない）
  - [ ] `RUN_ERROR`→ `message` をエラー表示
- [ ] テスト：各イベント種別 → 期待する出力文字列（`echo` を捕捉して assert）

## T6. オーケストレーション（`src/Cli.php` / `bin/agui-chat.php`）

- [ ] `Cli::run(array $argv): int`：
  - [ ] `AGUI_BASE_URL` env 読み（既定 `http://127.0.0.1:8080`）
  - [ ] `threadId` を起動時に 1 回生成（`bin2hex(random_bytes(8))` 等）
  - [ ] 引数にメッセージがあれば 1 発 run して終了、無ければ標準入力から 1 行ずつ読む REPL
  - [ ] 各ターン：`runId` を新規生成 → `ConversationLog::toMessages()` + 新規 `UserMessage` で
    `RunAgentInput` 形の body を組み立て → `AgUiHttpClient::stream()` → 各イベントを
    `EventRenderer::render()` と `ConversationLog::observe()` の両方に渡す
- [ ] `bin/agui-chat.php`：`require` autoload → `exit((new Cli())->run($argv))`
- [ ] ⚠️ **`Cli` は `tests/Fake` を import しない**（依存方向。テストは別経路で注入・M2/M3 の T8 慣例）

## T7. ガード（境界規則・要承認あり）

- [ ] `src/`（本体）→ `example/` を禁止（既存不変条件、拡張不要）
- [ ] `example/cli-client/` → `tests/` を禁止（M2/M3 と同じ流儀）
- [ ] `example/cli-client/` は `NaokiTsuchiya\BEARAgUi\` の**いかなるサブ名前空間も** import しない
  （D30 の核心＝ライブラリ非依存）。ガードの permit リストに一切追加しないことで自然に強制される
- [ ] ⚠️ **mago guard へのルール追加はユーザー承認案件**（[feedback: linter rules は無断で触らない]）。
  今回は「新規ルールを 1 つ足すだけ」で `NaokiTsuchiya\BEARAgUi\` を permit に含めない形にするため、
  他マイルストーンの前例より単純（許可リストが空＝ライブラリ完全非依存を機械的に強制）

## T8. CI ＋ ドキュメント

- [ ] `phpunit.xml.dist` の `unit` テストスイートに `tests/Example/CliClient/` を含める（既存 include で足りるか確認）
- [ ] `composer tests`（`@sa` + `@test`）で `example/cli-client` も解析・テスト対象に入ることを確認
  （`mago analyze`/`phpmd` のパス拡張は T7 同様に承認案件）
- [ ] `example/cli-client/README.md`：起動方法（M3 bear アプリ + stub-llm を先に立ち上げる旨）、
  `AGUI_BASE_URL` の向け方、1 発モード／REPL モードの使い分け、resume 非対応の明記
- [ ] `milestones.md` M4 の「詳細タスク: `tasks-m4.md`」リンクは本ファイル作成と同時に有効化済み
- [ ] `decisions.md` D30 は追記済み（本ファイル冒頭参照）

## T9. 仕上げ / DoD

- [ ] `composer tests`（mago format/lint/analyze/guard + phpmd + phpunit unit）グリーン
- [ ] `composer crc` グリーン（example は autoload-dev に閉じ `require` 汚染なし。新規 Composer 依存も無し）
- [ ] **手動 smoke**：端末 1 でスタブ LLM、端末 2 で M3 bear アプリ（Swoole）、端末 3 で本 CLI を起動し
  - [ ] 複数ターンの会話（2 往復以上）で 2 ターン目の `messages[]` に 1 ターン目の履歴が正しく載ること
  - [ ] 並列 run（"Weather in Tokyo and the news, please."）で `weather_get`/`news_get` の両方が
    レンダリングされ、テキストがストリーミング表示されること
  - [ ] interrupt run（"Remind me to buy milk."）で interrupt メッセージが表示され、ターンが終了すること
- [ ] 3 連続で unit テストグリーン（flake 無し。ただし本 M4 に時計依存の非決定要素は無い想定）

---

## コミット粒度

作業ブランチ。**各コミット green**、依存順。メッセージは命令形・プレフィックス無し。

| # | コミットメッセージ（案） | 含むタスク | green |
| --- | --- | --- | --- |
| C1 | `Add CLI client skeleton and autoload` | T1 | — |
| C2 | `Add SSE frame reader for the CLI client` | T2 | phpunit |
| C3 | `Add conversation log to reconstruct AG-UI messages client-side` | T4 | phpunit |
| C4 | `Add event renderer and streaming HTTP client` | T3 + T5 | phpunit |
| C5 | `Wire CLI orchestration and entry point` | T6 + T8(CI) | `composer tests` |
| C6 | `Document CLI client and link M4 tasks` | T8(docs) | — |

依存順：**C1 → C2 → C3 → C4 → C5 → C6**。T7（guard 追加）は承認後に別コミット（必要なら）。

---

## スコープ外（M4 では扱わない）

- **本物の interrupt / resume**（ToolUse 側 resume API 待ち。D4 のまま。M4 では「再開できない」表示のみ）
- マルチモーダル入力（画像等）の送信（v1 非対応・D17 のまま）
- TUI（ncurses 的な画面分割）や色付け等のリッチな端末表現。プレーンテキストの逐次表示のみ
- 認証・TLS・CORS（ADR 0005 のデプロイ関心事）
- M2（`example/server/`）を相手にした動作確認（対象は M3 の bear アプリのみ。プロトコルは同一なので
  理論上は M2 サーバにも向くはずだが、DoD の手動 smoke 対象には含めない）
