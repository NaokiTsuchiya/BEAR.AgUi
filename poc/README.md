# BEAR.Sunday × AG-UI — Claude Code ハンドオフパッケージ

chat 側で設計（ADR）と動作実証済みプロトタイプまで完了したものを、Claude Code で
実装を継続するための一式。

## 読む順序

1. **`CLAUDE.md`** — 実装の指示書。現在地・実証済み事実・タスク（着手順）・不変条件・落とし穴。
   Claude Code はまずこれを読む。
2. **`docs/adr/0000-0006-ag-ui-support.md`** — 全設計判断の根拠（ADR 0000〜0006）。
   「なぜそうするか」はここ。
3. **`proto/README.md`** — プロトタイプの構成と検証結果、本配線への移行手順。

## 中身

```
handoff/
├── CLAUDE.md                          実装指示書（最初に読む）
├── docs/adr/
│   └── 0000-0006-ag-ui-support.md     ADR 0000〜0006
└── proto/                             動作実証済み standalone プロトタイプ
    ├── README.md
    ├── autoload.php
    ├── src/                           本実装の下敷きになる部品
    │   ├── Input/RunAgentInput.php
    │   ├── Adapter/AgUiAdapter.php
    │   ├── Event/
    │   └── Sse/
    └── proto/
        ├── verify.php                 3シナリオ検証（PHPUnitへ移植する）
        ├── server.php                 実SSEサーバ（参考）
        └── ToolUseStub.php            本実装では削除する足場
```

## クイック検証

```bash
cd proto
php proto/verify.php       # => ALL CHECKS PASSED（PHP 8.3、依存なし）
```

## ひとことで現在地

変換層（AgentEvent → AG-UI イベント）と SSE 配信は standalone で実証済み。
残る最重要未検証点は **BEAR リソースへの結線**（`$ro->body` に Generator を入れ、
通常レンダラをバイパスして SSE Transfer まで生で届ける）で、これは実 BEAR が要る。
詳細は `CLAUDE.md` の T3。
