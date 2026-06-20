# BEAR.AgUi プロトタイプ

PR #22（bearsunday/BEAR.ToolUse）をベースに、AG-UI を BEAR.Sunday で
サポートするための最小プロトタイプ。**ToolUse 本体は無改造**で、その
`StreamingAgent` が返す `Generator<AgentEvent>` を AG-UI イベントストリームに
変換し、SSE で逐次配信する薄い層だけを実装している。

## レイヤー構成

```
HTTP POST /invocations  (RunAgentInput JSON)
  │
  ├─ Input/RunAgentInput        ADR 0001: 入力境界。接続前に構造検証 → 失敗は HTTP 400
  │
  ├─ Adapter/AgUiAdapter        ★核心。AgentEvent(ToolUse) → AgUiEvent 変換
  │     ├─ RUN_STARTED / RUN_FINISHED を生成（ライフサイクル境界）
  │     ├─ TEXT_MESSAGE_START / _END を合成（ToolUseはtext_deltaしか出さない）
  │     └─ INTERRUPT を yield し、send(bool) を ToolUse 生成器へ中継（HITL）
  │
  ├─ Event/*                    16種のAG-UIイベント値オブジェクト（必要分を実装）
  │
  └─ Sse/                       ADR 0002 / 0005: 出力境界
        ├─ SseEncoder           1イベント → "data: {json}\n\n"（純粋変換）
        ├─ SseSinkInterface     write+flush の抽象（CLI/FPM ⇔ Swoole を差し替え）
        ├─ PhpSapiSseSink       php-fpm/cli 実装（echo + flush + バッファ無効化）
        └─ SseResponder         Generator を1個ずつ pull→frame→write（畳まない）
```

BEAR.Streamer との決定的な違い: Streamer は複数の有限ストリームを
`stream_copy_to_stream` で**1本に畳んでから** `echo fread()` する（有限前提）。
本実装は**畳まず**、`foreach ($events as $e)` で1イベントずつ即 write する
（無限イベント列対応）。

## 検証済みの未検証点（proto/verify.php）

3つの設計上の不確実性を、実行で潰した:

1. **Generator 3段パイプは遅延ストリーミングする**
   ToolUse生成 → adapter変換 → responder書出し が交互に進む（バッファ無し）。
   ログで「生成」と「書出し」が interleave することを確認。

2. **AgentEvent → AG-UI の境界生成が正しい**
   `text_delta` のみから `TEXT_MESSAGE_START/CONTENT/END` を正しく合成。
   tool 呼び出しを挟むと message が閉じ、その後のテキストは新 messageId で開く。

3. **INTERRUPT が双方向に往復する**
   `INTERRUPT` を yield → HTTP層が `send(true/false)` → ToolUse 生成器に到達。
   approve なら tool 実行、deny なら tool スキップ（`send(false)` キャンセル）。

## 実HTTP配信の確認（proto/server.php）

`php -S` で `/invocations` を立て、200ms間隔でトークンを出す擬似エージェントを
curl で受けると、各 `TEXT_MESSAGE_CONTENT` が約200ms間隔で**逐次到達**する
（バッファされず真にインクリメンタル）。`/ping` ヘルスチェックと、`messages`
欠落時の HTTP 400 検証も確認済み。

## 実行方法

```bash
php proto/verify.php          # 中核ロジックの検証（3シナリオ）
php -S 127.0.0.1:8131 proto/server.php   # 実SSEサーバ
# 別端末:
curl -sN -X POST http://127.0.0.1:8131/invocations \
  -H 'Content-Type: application/json' \
  -d '{"threadId":"t","runId":"r","messages":[{"role":"user","content":"hi"}]}'
```

## 本配線への移行（プロトタイプから本番へ）

1. `proto/ToolUseStub.php` を削除し、`use BEAR\ToolUse\Runtime\AgentEvent` に
   差し替える（フィールド名は写してあるのでアダプタは無変更で binding する）。

2. `AgUiAdapter::run()` に渡す `Generator` を、実際の
   `OptionAwareStreamingAgentInterface::runStream($msg, AgentOptions)` の戻り値に
   する。`RunAgentInput::declaredToolNames()` → `AgentOptions::withTools()` で
   クライアント宣言ツールを反映（PR #22）。

3. ALPS ツール制御（ADR 0004）は **自前実装不要**。PR #22 の
   `AlpsToolPolicyInputProcessor::safeOnly()` 等を `AgentOptions` に渡すだけ。

4. Swoole（AgentCore/ARM64, ADR 0005）では `SwooleSseSink implements
   SseSinkInterface` を1つ追加し、`echo/flush` を `$response->write()` に置換。
   `SseResponder` と `AgUiAdapter` は無変更。

5. BEAR リソースに載せる場合は、`onPost(RunAgentInput $input)` で
   `$this->body = $adapter->run($agentStream)`（Generator）を代入し、その
   リソース専用に SSE Responder を Transfer として DI 結線（通常レンダラを
   バイパス）。← ここが BEAR 統合で唯一残る結線作業。

## まだ実装していない（スコープ外）

- STATE_SNAPSHOT / STATE_DELTA（ADR 0003: state-as-resource として別リソース）
- TOOL_CALL_ARGS の引数ストリーミング（低レベル StreamEvent::TOOL_USE_DELTA 経由）
- メッセージ履歴の完全マッピング（今は最後のuserメッセージのみ）
- WebSocket /ws（ADR 0005 第二段階）
```
