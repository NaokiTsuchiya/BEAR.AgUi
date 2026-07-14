# M5 実装タスク（詳細）

example④：**ビルドツール無し React チャット UI**。M4（CLI）に続くもう一つの消費側の実物。対象は M3 の
BEAR Swoole アプリ（`example/bear/`）。ブラウザから同じ AG-UI エンドポイントを叩く。

[`milestones.md`](milestones.md) M5 / [`decisions.md`](decisions.md) D31 を実装タスクに落としたもの。
末尾 `(Dxx)` は根拠。`⚠️` は実装時に実物で確認する点。

**前提（grounding 済み）**:

- M3 の BEAR Swoole アプリが動いている前提（[`example/bear/README.md`](../example/bear/README.md)）。
- **ビルドツール無し React**（D31）：`react`/`react-dom`（UMD 版）＋ `@babel/standalone` を CDN スクリプト
  タグで読み込み、`<script type="text/babel">` に直接 JSX を書く。Node/npm・package.json は一切不要
- **配信は同一オリジン**（D31）：`public/server.php` の `onRequest` に `GET /` を追加し、静的 HTML を
  直接返す（BEAR.Resource を経由しない＝静的アセットはツールリソースの関心事ではない）。これにより
  `/invocations` と同一オリジンになり **CORS 対応が一切不要**
- **SSE は `fetch()` + `ReadableStream` を自前パース**（D31）。`EventSource` は使わない（AG-UI は `POST`+body
  必須で `EventSource` は `GET` しか送れないため）。M4 の `SseFrameReader`（PHP 版）と同型のロジックを JS で
  再実装する（プラットフォームが異なるため共有コード化はしない）
- **ライブラリ非依存・会話履歴はブラウザ側が正本**（D30 と同じ精神を JS に移植・そもそも自明）
- **JS の自動テストは無し**（D31）。本リポジトリは PHP 専用のツールチェーン。検証は手動ブラウザ smoke に一本化

新規依存：**なし**（PHP 側は既存 `example/bear` に 1 ルート追加のみ。JS ライブラリは CDN 読み込みで
リポジトリに追加しない）。

---

## 設計サマリ（M5 で作る姿）

```
example/bear/
└── public/
    ├── server.php        （既存）GET / を追加してチャット UI の HTML を返す
    └── chat.html          新規：1 ファイル完結の React チャット UI（CDN 読み込み・ビルド不要）
```

- **1 ファイル完結**：`chat.html` に `<style>`・`<script type="text/babel">` を全てインライン。別 .js/.css
  ファイルに分割しない（M4 が PHP クラスへ分割したのとは対照的——ブラウザの単一ファイル配布という制約に
  素直に従う。静的アセットのビルドパイプラインは持たない方針と整合）
- **チャット UI**：メッセージ一覧（user/assistant 吹き出し）＋ 入力欄＋送信ボタン。React の関数コンポーネント
  ＋ `useState`/`useRef` で状態管理（Redux 等の追加ライブラリは使わない）

---

## T1. 静的配信ルートの追加（`example/bear/public/server.php`）

- [ ] `onRequest` に `GET /` の分岐を追加：`chat.html` を `file_get_contents()` で読み `Content-Type: text/html`
  で返す（`$response->header('Content-Type', 'text/html; charset=utf-8'); $response->end($html);`）
- [ ] ⚠️ ファイル読み込みは起動時 1 回（worker 起動時にメモリへ載せる）でも毎リクエスト読み直しでもどちらでも
  可だが、デモ用途なので**毎リクエスト読み直し**を選び「HTML を編集して即座にブラウザで確認できる」を優先する
  （ホットリロード相当。BEAR.Resource の DI キャッシュとは無関係な単純な静的配信のため問題ない）
- [ ] 既存の `GET /ping`・`POST /invocations`・404 フォールバックの分岐順は変更しない（`GET /` を追加するだけ）
- [ ] テスト：この変更はプロセス起動を伴う手動確認が前提（`server.php` は既存も自動テスト対象外＝tasks-m3 T10
  の慣例どおり）。ユニットテストは追加しない

## T2. React チャット UI（`example/bear/public/chat.html`）

### T2-a. ページ骨格 ＋ CDN 読み込み

- [ ] `<head>` に `react@18`/`react-dom@18`（UMD, production build）と `@babel/standalone` を CDN スクリプト
  タグで読み込む（例：`unpkg.com` or `esm.sh`。バージョンはピン留め）
- [ ] `<body>` に `<div id="root"></div>` と `<script type="text/babel" data-presets="react">` を配置
- [ ] 最小限のインライン `<style>`（吹き出し・入力欄のレイアウトのみ。CSS フレームワークは使わない）

### T2-b. SSE フレームリーダー（JS 版・M4 T2 の移植）

- [ ] `class SseFrameReader { feed(chunk: Uint8Array): string[]; decode(frame: string): object|null }` 相当を
  JS 関数として実装（クラスでも関数でもよいが、M4 の PHP `SseFrameReader` とロジックを一対一対応させる）
- [ ] `TextDecoder` でバイト列→文字列にデコードしつつ内部バッファへ蓄積、`\n\n` 区切りでフレーム分割
- [ ] `data: ` プレフィックスを剥がして `JSON.parse`。空行・コメント行（`:` 始まり）は無視

### T2-c. HTTP クライアント（JS 版・M4 T3 の移植）

- [ ] `async function streamRun(body, onEvent)`：`fetch('/invocations', {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(body)})`
  → `response.body.getReader()` でチャンクを読みつつ `SseFrameReader` に `feed()` → 完成フレームを `decode()` →
  非 null なら `onEvent(decoded)` を都度呼ぶ（バッファしない・逐次）
- [ ] レスポンスの `Content-Type` を見て分岐：`application/json`（400 応答）ならボディ全体を 1 回 `JSON.parse`
  してエラー表示、`text/event-stream` なら上記の逐次処理（M4 T3 と同じ判断基準）

### T2-d. 会話ログ（JS 版・M4 T4 の移植）

- [ ] `class ConversationLog { appendUser(text); observe(event); toMessages(): array }` を M4 の
  `ConversationLog`（PHP）と同じ状態遷移で実装：
  - [ ] `TEXT_MESSAGE_START`/`CONTENT`/`END` → assistant メッセージの開始・追記・確定
  - [ ] `TOOL_CALL_START`/`ARGS` → 直近 assistant メッセージの `toolCalls[]` へ追加・引数追記
  - [ ] `TOOL_CALL_RESULT` → `ToolMessage`（`{id, role:"tool", content, toolCallId}`）を追記
  - [ ] `RUN_FINISHED`/`RUN_ERROR` → 開いているメッセージがあれば確定してリセット

### T2-e. React コンポーネント

- [ ] `App` コンポーネント：`messages`（表示用の吹き出しリスト）、`input`（入力欄の値）、`busy`（送信中フラグ）
  を `useState` で保持
- [ ] 送信ハンドラ：`ConversationLog.appendUser()` → `threadId`（`useRef` でセッション固定・初回生成）＋
  新規 `runId` ＋ `ConversationLog.toMessages()` で body を組み立て → `streamRun()` を呼び、各イベントを
  `ConversationLog.observe()` と画面反映の両方に渡す
- [ ] レンダリング：
  - [ ] `TEXT_MESSAGE_CONTENT.delta` を該当 assistant 吹き出しに逐次追記（React の state 更新で再レンダリング）
  - [ ] `TOOL_CALL_START`/`TOOL_CALL_RESULT` → 小さな「🔧 tool_name …」「🔧 tool_name → result」行として表示
  - [ ] `RUN_FINISHED{outcome:interrupt}` → `interrupts[].message` をシステムメッセージとして表示し、
    「このツール呼び出しは再開できません（v1 の既知の制約）」の注記を出す（D4・M4 と同じ扱い）
  - [ ] `RUN_ERROR` → エラーメッセージを赤字表示

## T3. ドキュメント

- [ ] `example/bear/README.md` に M5 のセクションを追記：起動方法（M3 サーバ + stub-llm を先に立ち上げ、
  ブラウザで `http://127.0.0.1:8080/` を開くだけ）、ビルド不要である旨、resume 非対応の明記
- [ ] `milestones.md` M5 の「詳細タスク: `tasks-m5.md`」リンクは本ファイル作成と同時に有効化済み
- [ ] `decisions.md` D31 は追記済み（本ファイル冒頭参照）

## T4. 仕上げ / DoD

- [ ] `composer tests`（mago format/lint/analyze/guard + phpmd + phpunit）グリーン
  （変更点は `server.php` の追記のみ＝既存 PHP 品質ゲートの対象。`chat.html` は PHP ではないため
  mago/phpmd の対象外＝設定変更不要）
- [ ] `composer crc` グリーン（新規 Composer 依存なし＝影響なし）
- [ ] **手動ブラウザ smoke**：端末 1 でスタブ LLM、端末 2 で M3 bear アプリ（Swoole）を起動し、
  ブラウザで `http://127.0.0.1:8080/` を開いて
  - [ ] 複数ターンの会話（2 往復以上）で会話が正しく蓄積されること（サーバはステートレスなので
    毎 run 全履歴を送っていることを devtools のネットワークタブで確認）
  - [ ] 並列 run（"Weather in Tokyo and the news, please."）で両ツールの呼び出しが UI に表示され、
    テキストが逐次表示されること
  - [ ] interrupt run（"Remind me to buy milk."）で interrupt メッセージが表示され、ターンが終了すること

---

## コミット粒度

作業ブランチ。**各コミット green**、依存順。メッセージは命令形・プレフィックス無し。

| # | コミットメッセージ（案） | 含むタスク | green |
| --- | --- | --- | --- |
| C1 | `Serve a static chat UI page from the BEAR Swoole server` | T1 | 手動確認のみ |
| C2 | `Add build-tool-free React chat UI` | T2 | 手動ブラウザ smoke |
| C3 | `Document the web chat UI and link M5 tasks` | T3 | — |

依存順：**C1 → C2 → C3**。

---

## スコープ外（M5 では扱わない）

- **本物の interrupt / resume**（ToolUse 側 resume API 待ち。D4 のまま）
- マルチモーダル入力（画像等）の送信（v1 非対応・D17 のまま）
- CSS フレームワーク・状態管理ライブラリ（Redux 等）・ルーティングライブラリの導入
- 別オリジン配信・CORS 対応（ADR 0005 のデプロイ関心事。D31 で同一オリジン配信を選択済み）
- ビルドパイプライン（webpack/vite 等）の導入。将来チームがビルド環境を持ちたくなった場合の話は別途
- JS の自動テスト（Node 環境の追加）。手動ブラウザ smoke に一本化（D31）
- M2（`example/server/`）を相手にした動作確認（対象は M3 の bear アプリのみ、M4 と同じ理由）
