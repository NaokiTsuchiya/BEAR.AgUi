# CLAUDE.md — BEAR.Sunday × AG-UI サポート 実装ハンドオフ

このリポジトリは、AG-UI（Agent-User Interaction Protocol）を BEAR.Sunday で
サポートするための実装。設計判断はすべて `docs/adr/0000-0006-ag-ui-support.md`
に ADR として記録済み。`proto/` に**動作実証済みの standalone プロトタイプ**がある。

このファイルは Claude Code が実装を引き継ぐための指示書。**まず `docs/adr/` と
`proto/README.md` を読むこと。** 設計の「なぜ」はそこにある。ここには「次に何を
どの順で実装するか」だけを書く。

---

## 現在地（What is done）

chat 側で設計と standalone プロトタイプまで完了している。プロトタイプは
`proto/` 配下にあり、`php proto/verify.php` で全チェックが通る（PHP 8.3、依存なし）。

**実証済みの事実（再検証不要、前提として使ってよい）:**

1. ToolUse(PR #22) の `runStream(): Generator<AgentEvent>` → `AgUiAdapter` →
   `SseResponder` の **Generator 3段パイプは遅延ストリーミングする**。
2. `AgentEvent`(text_delta/tool_start/tool_result/completed/confirmation_required/error)
   → AG-UI イベントの**変換と境界生成（TEXT_MESSAGE_START/END, RUN_STARTED/FINISHED）が
   正しい**。
3. **INTERRUPT が双方向に往復する**（`send(true/false)` が ToolUse 生成器へ届き、
   approve→tool実行 / deny→スキップ）。
4. 実 HTTP（`php -S`）で SSE が**逐次配信される**（200ms間隔のトークンが約200ms間隔で到達）。
5. `/ping` ヘルスチェックと、入力検証失敗時の **HTTP 400** が動く。

**プロトタイプの構成（`proto/` 以下、本実装の下敷き）:**

```
src/Input/RunAgentInput.php       入力境界（ADR 0001）
src/Adapter/AgUiAdapter.php       ★変換の核心（ADR 0006）
src/Event/Events.php              AG-UI イベント値オブジェクト（ADR 0002）
src/Event/AgUiEventInterface.php
src/Sse/SseEncoder.php            1イベント→data:{json}\n\n（ADR 0002）
src/Sse/SseSinkInterface.php      write+flush 抽象（ADR 0005）
src/Sse/PhpSapiSseSink.php        CLI/FPM 実装（ADR 0005）
src/Sse/SseResponder.php          Generator 逐次 write（ADR 0002）
proto/ToolUseStub.php             ★本実装では削除する足場
proto/verify.php                  3シナリオ検証（消さずに移植・拡張）
proto/server.php                  実 SSE サーバ（参考）
```

`proto/ToolUseStub.php` は ToolUse 本体と LLM を持ち込まずに変換ロジックを
検証するための**足場**。本実装では削除し、実 `BEAR\ToolUse\Runtime\AgentEvent`
に差し替える。フィールド名は PR #22 から正確に写してあるので、`AgUiAdapter` は
**無変更で bind する**はず。

---

## 実装タスク（着手順）

順序は依存関係と「失敗が早く分かる順」で並べてある。各タスクは対応 ADR を持つ。

### T1. パッケージ化と ToolUse 実体への接続 ★最初にやる
- `proto/` のコードを正式な composer パッケージ構成（`myvendor/bear-agui` 想定）へ移す。
  `composer.json`（PSR-4: `BEAR\AgUi\` → `src/`）、`require: bear/tool-use`（PR #22 の
  ブランチ or マージ後タグ）。
- `proto/ToolUseStub.php` を削除し、`use BEAR\ToolUse\Runtime\AgentEvent` に差し替え。
- `AgUiAdapter` がスタブ無しでコンパイル・テストできることを確認（`verify.php` を
  PHPUnit へ移植）。
- **Done条件**: `verify.php` 相当のテストが実 `AgentEvent` で緑。

### T2. RunAgentInput → runStream 起動のマッピング（ADR 0001/0004）
- `RunAgentInput::lastUserMessage()` → `runStream($msg, $options)` の起動を実装。
- `RunAgentInput::declaredToolNames()` → `AgentOptions::withTools($names)` を結線。
- ALPS ツール制御は **PR #22 の `AlpsToolPolicyInputProcessor::safeOnly()` 等を
  `AgentOptions` に渡すだけ**。自前実装しないこと（ADR 0004 方針変更メモ参照）。
- **Done条件**: 実エージェントを runStream で起動し、AG-UI イベントが出る統合テスト。

### T3. BEAR リソースへの結線 ★設計上の最重要未検証点（ADR 0002）
- AG-UI エンドポイントを BEAR リソース化（`onPost(RunAgentInput): static`）。
  `$this->body = $adapter->run($agentStream)`（Generator）を代入。
- **核心問題**: 通常の Renderer/Transfer は `$ro->body` を文字列化しようとするので、
  Generator が途中で握り潰されないよう、**このリソース専用に SSE Transfer を
  DI 結線して通常レンダラをバイパス**する必要がある。
  - 案A: `body instanceof \Traversable` を見て分岐する Renderer/Transfer。
  - 案B: streamable を示すマーカーインターフェースを ResourceObject に実装し、
    型で経路を分ける（ADR 0002 の検討では BEAR 思想的にこちらが筋が良い）。
  - BEAR.Streamer の `StreamModule` / Responder 結線が**結線作法の参考**になる
    （ただしロジックは流用しない。Streamer は有限・畳み込み前提で別物）。
- **Done条件**: 実 BEAR アプリで `/invocations` を叩くと SSE が逐次返る。これが
  通れば ADR 0002 が完全に Accepted になる。

### T4. Swoole Sink（ADR 0005）
- `SwooleSseSink implements SseSinkInterface` を追加。`echo/flush` を
  `$response->write()` に、`close()` を `$response->end()` に置換するだけ。
  `SseResponder` / `AgUiAdapter` は無変更。
- **Done条件**: Swoole HTTP Server 上で SSE 逐次配信。

### T5. state-as-resource（ADR 0003）
- `app://self/thread/{threadId}/state` リソースを実装。外部ストア（DynamoDB or
  libSQL、T7 と統一）に永続化。
- `onGet` = 全体表現 → `STATE_SNAPSHOT`。`onPatch` = 受領した JSON Patch を適用・
  保存し、同じパッチを `STATE_DELTA` として echo（差分の再計算はしない）。
- `RunAgentInput.state`（信頼できない入力）の取り込みは `onPut` に集約し、
  `@JsonSchema` + サイズ制限 + 制御シーケンスのエスケープでサニタイズ。
- **Done条件**: snapshot/delta が AG-UI クライアントと同期する。

### T6. AgentCore デプロイ（ADR 0005）
- ARM64 コンテナ、Host `0.0.0.0` / Port `8080`、`POST /invocations` + `GET /ping`。
- 認証は AgentCore の OAuth/SigV4 に委譲。`WWW-Authenticate` は接続レベルエラー（401）。
- セッションは `X-Amzn-Bedrock-AgentCore-Runtime-Session-Id` を信頼境界として
  `threadId` に対応づけ。
- **Done条件**: AgentCore Runtime 上で AG-UI クライアントが疎通。

### T7以降（後続・スコープ外でもよい）
- TOOL_CALL_ARGS 引数ストリーミング（低レベル `StreamEvent::TOOL_USE_DELTA` 経由）。
- 複数ツール連続/並行時の toolCallId 対応づけ（現状 `lastToolCallId` 単純保持を改善）。
- メッセージ履歴の完全マッピング（現状は最後の user メッセージのみ）。
- WebSocket `/ws`（ADR 0005 第二段階、INTERRUPT の本格双方向）。
- state リソースの並行更新の競合解決（バージョン/楽観ロック）。

---

## 設計の不変条件（壊さないこと）

- **ToolUse 本体を改造しない。** AG-UI 対応は ToolUse の外側のアダプタと SSE 層で完結させる。
- **レイヤーを混同しない。** 変換は `AgUiAdapter`、フレーム化は `SseEncoder`、I/O は
  `SseSink`、起動マッピングは入力境界。ToolUse の `OutputProcessor` は
  「制御・フィルタ・注入・観測」専用で、**AG-UI イベントへの型変換に使わない**
  （`StreamEvent`→`StreamEvent` の型保全契約があるため）。
- **SseResponder は body を畳まない。** 1イベントずつ pull→frame→write。
  `stream_copy_to_stream` 的な「1本化してから出す」はやらない（無限ストリーム前提）。
- **エラーの二分法**（ADR 0001）: 接続前の検証失敗 = HTTP 400、実行中の失敗 =
  SSE 内 `RUN_ERROR`（HTTP は 200）。
- **state はリソース**（ADR 0003）。特別な状態同期機構を作らない。snapshot=onGet、
  delta=onPatch の echo。

## 落とし穴（chat 側で踏んだ/予見した地雷）

- php-fpm の逐次 flush は出力バッファ・nginx proxy_buffering・FastCGI buffering が
  絡んで鬼門。`X-Accel-Buffering: no` 等で対処したが、**確実な逐次性は Swoole 側の
  性質**。SSE を本気でやるなら Swoole 前提が素直（ADR 0005）。
- `tool_start` は `toolName` しか持たない。引数が要るなら低レベルへ降りる（T7）。
- PR #22 は 3,400 行の大物で ToolUse 自体まだ 0.1.x。**依存インターフェースを最小に
  絞る**（`StreamingAgentInterface` / `AgentEvent` / `AgentOptions` / 各 Processor のみ）
  ことで土台の揺れに追従しやすくする。

## 検証コマンド

```bash
php proto/verify.php                       # 変換ロジック3シナリオ（移植元）
php -S 127.0.0.1:8131 proto/server.php     # 実 SSE サーバ
curl -sN -X POST http://127.0.0.1:8131/invocations \
  -H 'Content-Type: application/json' \
  -d '{"threadId":"t","runId":"r","messages":[{"role":"user","content":"hi"}]}'
```
