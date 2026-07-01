# ADR 0007: AsyncResourceClient — BEAR.Resource リクエストの Swoole 非同期実行

> BEAR.Resource のリソースリクエストを Swoole コルーチン上で非同期/並行に実行する構想（仮称 `AsyncResourceClient`）の実現可能性を、spike による実機検証つきで整理する。
> 本 ADR は ADR 0000 の「デプロイ先 = AWS Bedrock AgentCore Runtime、Swoole + ARM64 構成を活用」という前提の上に立つ。

---

## Status

Proposed（scratchpad spike で実現可能性を実機検証済み。本体への組込みは未着手）

## Context

AG-UI の 1 ラン中には、複数のツール呼び出し（= BEAR では複数のリソースリクエスト）が発生しうる。これらが互いに独立であれば、直列実行はそのままレイテンシの加算になる。AgentCore は Swoole + ARM64 を主ターゲットとしており（ADR 0000）、ランタイムにコルーチンが存在するため、独立リクエストを並行実行して応答時間を縮める余地がある。

そこで「リソースリクエストを Swoole で非同期実行する `AsyncResourceClient`」を自作すべきか、を判断する必要がある。判断材料として、以下を実機 spike で確認した。

### 調査で判明した既存の土台

BEAR.Resource 本体（1.33.0）は、すでに async を見越した二層構造を持つ。

- `Resource`（fluent interface 用に mutable、**NOT coroutine-safe** と明記）
- `ResourceClient`（stateless・**coroutine-safe**、「Async modules が override してこのクラスにバインドする」と明記）
- `ResourceInterface::newRequest()`（「atomically / coroutine-safe」なリクエスト生成）

また `bearsunday/BEAR.Async`（0.3.0）が参照実装として存在し、`SwooleAsync`（WaitGroup ベースの並行実行）、`RequestBatch` / `DeferredRequest`、`PdoPoolModule`（Swoole `PDOPool` ラッパ）、`AsyncLinker`（`#[Embed]` グラフのレベル単位並列化）を提供する。

### Spike による検証結果

環境: PHP 8.5.5 (NTS) / ext-swoole 6.2.0 / Docker。`bear/resource ^1.0` + `bear/async ^0.3` は依存衝突なくインストールできた。

| # | 検証内容 | 結果（3〜4 リクエスト × 300ms） |
|---|---|---|
| 01 | Swoole コルーチンのブロッキング並行化 | 912ms → 303ms（**3.0x**）、結果合流 OK |
| 02 | 実 BEAR.Resource を `ResourceClient` + `SwooleAsync::execute()` で並行 | 915ms → 302ms（**3.0x**）、各リクエスト別 coroutine |
| 03 | 実 HTTP I/O（BEAR `HttpRequestCurl` / `curl_exec`） | 905ms → 304ms（**3.0x**）。`SWOOLE_HOOK_NATIVE_CURL` で**無改変のまま**並行化 |
| 04 | Ray.Aop インターセプタ + DI シングルトンのコルーチン安全性 | 引数の混線ゼロ、interceptor が yield しても in/out が同一 coroutine、可変シングルトンに lost update なし |
| 05 | 実 DB I/O（Swoole `PDOPool` / MySQL） | 1219ms → 306ms（**4.0x**）、"Packets out of order" なし＝プール機能 |

## Decision

**`AsyncResourceClient` を新規実装しない。** 次の構成を採る。

1. `bear/async` を依存に追加する。
2. async モードでは `ResourceInterface` のバインドを `ResourceClient`（coroutine-safe）へ差し替える（`AsyncSwooleModule` 相当）。
3. 複数リクエストの明示的な並行実行が必要な箇所では、`ResourceClient::newRequest()` で遅延 Request を生成し、`BEAR\Async\Adapter\SwooleAsync::execute()` / `RequestBatch` に束ねて合流する。
4. `#[Embed]` グラフの並列化は `AsyncLinker` に委譲する（アプリ境界でモード選択、既存リソースコードは無改変）。
5. DB アクセスは `PdoPoolModule`（Swoole `PDOPool`）でコルーチンごとに専用接続を借りる。

「自作の薄いラッパ」を被せる場合も、中身は上記プリミティブの組み合わせに留める。

## Consequences

### Positive
- 既存リソースコード・DI・AOP をほぼ無改変で活かせる（spike 02/03/04 で確認）。
- 独立 I/O の並行化で実測 3〜4x の短縮余地。
- 自作範囲が最小（バインド差し替え + 薄い並行 API）で保守負担が小さい。

### 制約・前提
- **Swoole ランタイム前提**。並行効果は HTTP サーバ or `Coroutine::create`/`run()` 内でのみ得られる。
- **ブロッキング I/O はフック必須**。curl は `SWOOLE_HOOK_NATIVE_CURL`、DB は `PDOPool`。フックされない I/O（例: SQLite のファイル I/O）は並行化されない。
- **DB は接続プール必須**。単一接続をコルーチンで共有すると "Packets out of order"。
- **AOP/DI シングルトンはステートレス維持が原則**。Swoole は単一スレッド協調スケジューラのため、ext-parallel のような真のメモリ競合は起きないが、yield をまたいで可変状態を共有する設計（Cache/Transaction 系インターセプタ等）は個別検証が要る。
- **ext-parallel 経路（PHP-FPM 向け）は未検証**。parallel は ZTS ビルド必須で、本環境（NTS）では利用不可。

### 未決 / 次アクション
- BEAR.AgUi 本体への組込みは、複数ツール=複数リソースの並行実行ニーズが顕在化してから着手する（現状はツール実行 / LLM ストリーミングが中心で、リソース並行の需要は未顕在）。
- ステートフルインターセプタのコルーチン安全性の棚卸し。
- プールサイズ / 枯渇時の挙動・タイムアウト・エラー伝播の本番設計。
- ext-parallel 経路は ZTS 環境を用意できたら別途検証する。

## References
- BEAR.Async: https://github.com/bearsunday/BEAR.Async
- 検証 spike（scratchpad・揮発）: `async-spike/`（01〜05、本 ADR の結果表の出所）
- 関連: ADR 0000（AG-UI を載せる位置づけ / Swoole + ARM64 前提）
