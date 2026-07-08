# Stub LLM — OpenAI-compatible canned server

API キー不要・決定論的に AG-UI サーバの end-to-end デモを回すための、
OpenAI 互換スタブです(D21)。`POST /v1/chat/completions` **単一エンドポイント**のみを
提供し、`Authorization` ヘッダの値は見ません(任意のキーで通ります)。

## 何を返すか

受信した `messages` 末尾の `role` でターンを判定する、単一の canned 会話です:

| ターン | 判定 | 返すもの |
| --- | --- | --- |
| 1 | 末尾 `role !== "tool"` | テキスト delta + `get_time` tool_call(`call_demo_1`、引数 `{"timezone":"UTC"}` を **2 チャンクに分割**)+ `finish_reason: "tool_calls"` |
| 2 | 末尾 `role === "tool"` | **受信した tool_result の content(実時刻)を埋め込んだ**最終テキスト + `finish_reason: "stop"` |

ターン 2 が canned 文字列ではなくツール結果を echo するのは、
エージェントループが「ツール結果で閉じる」ことを本物どおりに示すためです。
チャンクの `model` は受信リクエストの `model` をそのまま反射します。

## 起動

```console
php -S 127.0.0.1:8081 -t example/stub-llm/public example/stub-llm/public/index.php
```

example サーバから使うには `OPENAI_BASE_URL` を向けるだけです(コード変更なし・D18):

```console
OPENAI_BASE_URL=http://127.0.0.1:8081/v1 php -S 127.0.0.1:8080 \
    -t example/server/public example/server/public/index.php
```

## SSE の逐次到達を肉眼確認する

チャンク間遅延は `STUB_DELAY_MS` env(既定 `0`)で制御します:

```console
STUB_DELAY_MS=200 php -S 127.0.0.1:8081 -t example/stub-llm/public example/stub-llm/public/index.php
```

SSE 直列化は本体の `Sse/` を流用せず、素の `echo + flush` です
(`data: {json}\n\n` × チャンク数 + `data: [DONE]\n\n`)。
