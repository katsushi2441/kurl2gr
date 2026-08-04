# Third-party software

## GPT Researcher

- Upstream: https://github.com/assafelovic/gpt-researcher
- Pinned commit: `409b8b60b477d50f2f844bc9598d1bcb7b056eee` (tag **v3.5.1**)
- 注意: main と v3.6.0 は `actions/query_processing.py` で `Any` を import しておらず、
  読み込み時に NameError になる(2026-08-04 実測)。動作する最新の安定タグ v3.5.1 に留めている。
- 要 Python 3.11+（システムの3.10では入らないため `.venv-gr` を3.12で別に作る）
- License: Apache-2.0
- Role: 深いリサーチの実行エンジン（planner/execution/publisher の多エージェント構成、
  DuckDuckGo等の検索とページ取得、引用付きレポートの生成）

## AIxSNS (sns.php)

- Origin: `/home/kojima/work/aixec/webapps/sns.php`（Kurage内製）
- Role: 顧客サイトへ設置する表示専用のSNS/ブログ画面。投稿データは kurl2gr が保持する。

Kurage URL2GPTResearcher の独自コードは上記を装っておらず、名称とライセンスを明記して利用します。
