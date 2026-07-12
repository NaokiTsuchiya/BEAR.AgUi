# Example AG-UI server — フレームワーク非依存の最小サーバ

`AgUiRunner` の使用例です(M2 / ADR 0005)。素の PHP(`php -S`)で
`POST /invocations`(SSE)と `GET /ping` を提供し、OpenAI 互換 LLM で
エージェントループを回して AG-UI イベントを逐次配信します。

- LLM 接続は `openai-php/client`。**本物/スタブの切替は `OPENAI_BASE_URL` env のみ**(D18)
- OpenAI ⇔ bear/tool-use の変換層は [`example/shared/`](../shared/)(`Example\Shared`、M3 と共有・D28)
- 入力検証は `RunAgentInputParser::parse()` の `Result` で行い、失敗は
  **400 + 全 ParseError 集約**(D24)。run 開始後の失敗は開いた 200 上の `RUN_ERROR`(D11/D23)

## 起動モード

### 1. スタブ経由(API キー不要・決定論的)

端末 1 — スタブ LLM:

```console
php -S 127.0.0.1:8081 -t example/stub-llm/public example/stub-llm/public/index.php
```

端末 2 — 本サーバ(`OPENAI_BASE_URL` をスタブへ):

```console
OPENAI_BASE_URL=http://127.0.0.1:8081/v1 php -S 127.0.0.1:8080 \
    -t example/server/public example/server/public/index.php
```

### 2. 本物の OpenAI

```console
OPENAI_API_KEY=sk-... php -S 127.0.0.1:8080 \
    -t example/server/public example/server/public/index.php
```

| env | 既定 | 意味 |
| --- | --- | --- |
| `OPENAI_BASE_URL` | `https://api.openai.com/v1` | スタブに向けるとキー不要 |
| `OPENAI_API_KEY` | (なし) | 本物 OpenAI 接続時のみ必要 |
| `OPENAI_MODEL` | `gpt-4o-mini` | チャンクの model にも反射 |

## 叩き方

```console
curl -s http://127.0.0.1:8080/ping
# {"status":"Healthy","time_of_last_update":1783473167}

curl -N -X POST http://127.0.0.1:8080/invocations \
    -H 'Content-Type: application/json' \
    -d '{"threadId":"t-1","runId":"r-1","messages":[{"id":"m-1","role":"user","content":"What time is it?"}]}'
```

スタブ経由の応答(ツールループ全周が 1 回の run で流れます):

```text
data: {"type":"RUN_STARTED","threadId":"t-1","runId":"r-1"}
data: {"type":"TEXT_MESSAGE_START","messageId":"msg-…","role":"assistant"}
data: {"type":"TEXT_MESSAGE_CONTENT","messageId":"msg-…","delta":"Let me check "}
data: {"type":"TEXT_MESSAGE_CONTENT","messageId":"msg-…","delta":"the current time."}
data: {"type":"TEXT_MESSAGE_END","messageId":"msg-…"}
data: {"type":"TOOL_CALL_START","toolCallId":"call_demo_1","toolCallName":"get_time"}
data: {"type":"TOOL_CALL_ARGS","toolCallId":"call_demo_1","delta":"{\"timezone\":\"UTC\"}"}
data: {"type":"TOOL_CALL_END","toolCallId":"call_demo_1"}
data: {"type":"TOOL_CALL_RESULT","messageId":"msg-…","toolCallId":"call_demo_1","content":"2026-07-08T01:12:47+00:00","role":"tool"}
data: {"type":"TEXT_MESSAGE_START","messageId":"msg-…","role":"assistant"}
data: {"type":"TEXT_MESSAGE_CONTENT","messageId":"msg-…","delta":"The current time is "}
data: {"type":"TEXT_MESSAGE_CONTENT","messageId":"msg-…","delta":"2026-07-08T01:12:47+00:00"}
data: {"type":"TEXT_MESSAGE_CONTENT","messageId":"msg-…","delta":"."}
data: {"type":"TEXT_MESSAGE_END","messageId":"msg-…"}
data: {"type":"RUN_FINISHED","threadId":"t-1","runId":"r-1","outcome":{"type":"success"}}
```

`TOOL_CALL_RESULT` の実時刻を最終テキストが echo している点が、
ループが「ツール結果で閉じた」証明です(D21)。

## 登場ツール

| ツール | confirm | 挙動 |
| --- | --- | --- |
| `get_time` | なし | 実時刻を返す(`timezone` 引数対応、不正/欠落時は UTC) |
| `ask_confirmation` | **あり** | 呼ばれると `RUN_FINISHED{outcome:interrupt}` で run 終了 |

**interrupt は本物 LLM 経路でのみ発現**します(モデルが `ask_confirmation` を
選んだとき)。スタブは happy path(`get_time` ループ)だけを再現します。

## エラー応答

| 状況 | 応答 |
| --- | --- |
| 不正 JSON / 必須フィールド欠落 | **400** `{"code":"VALIDATION_ERROR","errors":[{"message":…}, …]}`(全エラー集約・D24) |
| `Content-Type` が `application/json` 以外 | **415** |
| 未知のパス/メソッド | **404** |
| run 開始後の失敗(キー無効等) | **HTTP 200 のまま** `RUN_ERROR` フレーム(D11) |

## 制約(v1)

- 並行ツールの引数 interleave は非対応(順次のみ・D19)。OpenAI は実際には
  順次送出するため実用上の問題はありません
- `php -S` は開発用です。本番の逐次配信(バッファリング制御)は
  fpm+nginx 等の SAPI 構成の関心事です(D22)
