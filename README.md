# Kurage URL2GPTResearcher (kurl2gr)

更新の止まったサイトに、内容に沿った新着記事を継続的に足すためのWebプロダクト。

サイトのURLを登録すると、AIがそのサイトをクロールして事業内容を要約し、その要約を土台に
[GPT Researcher](https://github.com/assafelovic/gpt-researcher) が外部の最新情報を調査し、
出典付きのレポートを記事へ整えて、顧客サイトに置いた表示専用のPHPに掲載します。

- アプリ入口: https://kurage.exbridge.jp/kurl2gr.php
- 紹介ページ: https://kurl2gr.exbridge.jp/ (en) / https://kurl2gr.exbridge.jp/kurl2gr.html (ja)

## なぜサイトの内容に沿わせるのか

関連のない記事を自動投稿してもサイトが賑やかになるだけで意味がありません。調査は
**必ずサイト概要を土台に**組み立てるので、掲載される記事は事業の延長線上に収まります。
調査結果に無い数字や固有名詞は書かせません。

## 構成

```
app/                FastAPI 本体 (:18358)
  main.py           X認証・テナント分離・サイト/調査/投稿のAPI
  site_service.py   対象サイトの取得と概要生成（SSRFガード込み）
  research_service.py  GPT Researcher の実行と記事化
  llm.py            無料枠=ローカルOllama(rqdb4ai経由) / 課金=DeepSeek
  db.py             SQLite。sites / posts / research_jobs / usage_events
kurl2gr/            rqdb4ai のワーカーが呼ぶジョブ関数
static/             管理画面（サイト登録・調査・手動投稿・掲載切替）
public/
  kurl2gr.php       共通Xログインを確定してバックエンドへ中継するゲートウェイ
  sns.php           顧客サイトへ置く表示専用のSNS/ブログ（PHP 5.6でも動く）
  sns_widget.php    既存ページに貼る新着枠
landing/            紹介ページ (kurl2gr.exbridge.jp)
```

顧客サイトに置くのは **表示専用のPHPだけ** です。データベースもバックエンドも要らず、
投稿・編集は管理画面からのみ行うため、顧客サーバーに書き込み権限を置きません。
発行された `site_key` で自分の記事だけを読みます。

## 調査は非同期

調査は数分かかります。`POST /api/sites/{id}/research` はジョブを作って 202 を返し、
`GET /api/research/{job_id}` で状態を取りに行きます。同期で返すと途中のホップが先に
切れてしまい、成功していても画面には失敗として出ます。

日次のワーカーによる自動投稿は行いません。調査は利用者が実行したときだけ動きます。

## LLMの出し分け

| 枠 | 実行先 |
|---|---|
| 無料枠 | 自社GPU上の Gemma 4（rqdb4ai のホスト別キュー経由でGPU競合を避ける） |
| 課金枠 | DeepSeek |

## 動かす

```bash
python3 -m venv .venv && .venv/bin/pip install -r requirements.txt
# GPT Researcher は Python 3.11+ が要るので別のvenvに入れる
python3.12 -m venv .venv-gr && .venv-gr/bin/pip install -e vendor/gpt-researcher ddgs
cp public/kurl2gr_config.php.example public/kurl2gr_config.php   # 中身を埋める
.venv/bin/uvicorn app.main:app --host 0.0.0.0 --port 18358
```

テスト: `.venv/bin/python -m pytest -q`

## ライセンスと出典

本プロダクトは GPT Researcher の公式日本語版ではなく、Apache-2.0 に基づく独立した
プロダクトです。取り込んでいるOSSと役割は [THIRD_PARTY.md](THIRD_PARTY.md) を参照してください。
掲載内容の正確性や、検索順位・AI回答への掲載を保証するものではありません。

© EXBRIDGE, Inc. — [Kurage](https://kurage.exbridge.jp/)
