"""kurl2gr.exbridge.jp のLPを組み立てる（英語=index.html / 日本語=kurl2gr.html）。

意匠は kgeo のLPに合わせる（--abyss / --foam / --panel / --teal のライト・
ダーク両対応）。Kurageシリーズで見た目を揃えるため、独自に作り直さない。

まだ提供していない部分は「準備中」と明示し、使えるように見せない。
"""

from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(Path(__file__).resolve().parent))
import inject_ecosystem  # noqa: E402
LANDING = ROOT / "landing"
GA_ID = "G-BP0650KDFR"

TRACKING = f"""<!-- Google tag (gtag.js) -->
<script async src="https://www.googletagmanager.com/gtag/js?id={GA_ID}"></script>
<script>
  window.dataLayer = window.dataLayer || [];
  function gtag(){{dataLayer.push(arguments);}}
  gtag('js', new Date());
  gtag('config', '{GA_ID}');
</script>
<script>
(function () {{
    var s = document.createElement('script');
    s.src = 'https://aiknowledgecms.exbridge.jp/simpletrack.php'
        + '?url=' + encodeURIComponent(location.href)
        + '&ref=' + encodeURIComponent(document.referrer);
    document.head.appendChild(s);
}})();
</script>"""

STYLE = """  :root {
    --abyss:#12202f; --abyss-soft:#55697a; --foam:#f5fbfb; --panel:#e7f3f2; --panel-line:#cde5e2;
    --teal:#12a99f; --teal-deep:#0a726b; --violet:#6f5fd8; --gold:#c98a1e; --gold-bg:#fbf2db; --gold-line:#ecd9a8;
    --shadow:0 14px 40px rgba(10,40,45,.10);
  }
  @media (prefers-color-scheme:dark){:root{
    --abyss:#eaf3f3; --abyss-soft:#9fb3ba; --foam:#0c1720; --panel:#12242a; --panel-line:#1f3a3f;
    --teal:#2bd4c6; --teal-deep:#1c9e93; --violet:#9186ea; --gold:#f2c766; --gold-bg:#241b08; --gold-line:#4c3c17;
    --shadow:0 14px 40px rgba(0,0,0,.38);
  }}
  * { box-sizing:border-box; margin:0; padding:0; }
  html { scroll-behavior:smooth; }
  body { color:var(--abyss); background:var(--foam); line-height:1.85; overflow-x:hidden;
    font-family:"Zen Kaku Gothic New","Hiragino Sans","Yu Gothic",Meiryo,sans-serif; }
  a { color:var(--teal-deep); text-decoration:none; }
  img { max-width:100%; }
  .wrap { max-width:1000px; margin:0 auto; padding:0 20px; }
  header.top { border-bottom:1px solid var(--panel-line); padding:14px 0; position:sticky; top:0;
    background:color-mix(in srgb, var(--foam) 92%, transparent); backdrop-filter:blur(8px); z-index:10; }
  header.top .wrap { display:flex; align-items:center; gap:12px; }
  .brand { display:flex; align-items:center; gap:10px; font-weight:800; font-size:16px; }
  .brand-mark { width:26px; height:26px; border-radius:8px; background:var(--teal);
    color:#fff; display:flex; align-items:center; justify-content:center; font-size:13px; font-weight:900; }
  .brand small { display:block; font-size:10.5px; color:var(--abyss-soft); font-weight:600; }
  .spacer { flex:1; }
  .lang { font-size:12px; font-weight:700; color:var(--abyss-soft); border:1px solid var(--panel-line);
    border-radius:999px; padding:4px 12px; }
  section { padding:44px 0; }
  .hero { padding:60px 0 34px; display:grid; grid-template-columns:1.25fr .75fr; gap:40px; align-items:center; }
  .hero-card { background:var(--panel); border:1px solid var(--panel-line); border-radius:26px;
    padding:24px; text-align:center; box-shadow:var(--shadow); }
  .hero-card img { width:190px; height:190px; object-fit:cover; object-position:50% 8%; border-radius:20px; }
  .hero-card h2 { font-size:15px; margin:14px 0 4px; text-align:center; }
  .hero-card p { font-size:12.5px; color:var(--abyss-soft); }
  @media (max-width:760px){ .hero { grid-template-columns:1fr; gap:22px; }
    .hero-card { order:-1; } .hero-card img { width:130px; height:130px; } }
  .eyebrow { display:inline-block; font-size:12px; font-weight:800; letter-spacing:.06em;
    color:var(--teal-deep); background:var(--panel); border:1px solid var(--panel-line);
    border-radius:999px; padding:5px 14px; margin-bottom:16px; }
  h1 { font-size:clamp(27px,4.2vw,42px); font-weight:800; line-height:1.35; letter-spacing:-.01em; }
  h1 em { font-style:normal; color:var(--teal-deep); }
  .lead { font-size:15.5px; color:var(--abyss-soft); max-width:620px; margin:16px 0 24px; }
  .lead b { color:var(--abyss); }
  .cta { display:flex; flex-wrap:wrap; gap:10px; }
  .btn { display:inline-block; font-size:14.5px; font-weight:800; padding:12px 24px; border-radius:10px;
    background:var(--teal); color:#fff; box-shadow:var(--shadow); }
  .btn.ghost { background:transparent; color:var(--teal-deep); border:1px solid var(--panel-line); box-shadow:none; }
  h2 { font-size:clamp(20px,2.8vw,27px); font-weight:800; text-align:center; margin-bottom:6px; }
  .sub { text-align:center; color:var(--abyss-soft); font-size:14px; margin-bottom:26px; }
  .steps { display:grid; grid-template-columns:repeat(auto-fit,minmax(230px,1fr)); gap:16px; }
  .step { background:var(--panel); border:1px solid var(--panel-line); border-radius:16px; padding:22px; position:relative; }
  .step .n { position:absolute; top:-13px; left:20px; width:28px; height:28px; border-radius:50%;
    background:var(--teal); color:#fff; display:flex; align-items:center; justify-content:center;
    font-size:13px; font-weight:900; }
  .step h3 { font-size:15px; font-weight:800; margin:8px 0 5px; }
  .step p { font-size:13px; color:var(--abyss-soft); }
  .box { background:var(--panel); border:1px solid var(--panel-line); border-radius:18px; padding:26px; }
  .box ul { padding-left:22px; font-size:14px; }
  .box li { margin-bottom:8px; }
  .box b { color:var(--teal-deep); }
  .gate { background:var(--gold-bg); border:1px solid var(--gold-line); border-radius:18px; padding:26px; }
  .gate b { color:var(--gold); }
  table.spec { width:100%; border-collapse:collapse; font-size:13.5px; }
  table.spec th, table.spec td { text-align:left; padding:10px 12px; border-bottom:1px solid var(--panel-line); }
  table.spec th { color:var(--abyss-soft); font-size:12px; }
  .table-wrap { overflow-x:auto; }
  .badge { display:inline-block; font-size:12px; font-weight:800; background:var(--gold-bg);
    border:1px solid var(--gold-line); color:var(--gold); border-radius:999px; padding:4px 14px; margin-bottom:10px; }
  .oss-note small { display:block; font-size:12px; color:var(--abyss-soft); margin-top:10px; }
  footer { border-top:1px solid var(--panel-line); padding:28px 0 44px; color:var(--abyss-soft); font-size:13px; }
"""

SITEMAP_PHP = """<?php
// kurl2gr.exbridge.jp のサイトマップ。lastmod はLPファイルの更新時刻から出す。
header('Content-Type: application/xml; charset=utf-8');
date_default_timezone_set('Asia/Tokyo');

$base = 'https://kurl2gr.exbridge.jp';
$pages = array(
    array('file' => 'index.html', 'loc' => $base . '/', 'priority' => '1.0'),
    array('file' => 'kurl2gr.html', 'loc' => $base . '/kurl2gr.html', 'priority' => '0.9'),
);
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"'
   . ' xmlns:xhtml="http://www.w3.org/1999/xhtml">' . "\\n";
foreach ($pages as $page) {
    $path = __DIR__ . '/' . $page['file'];
    $lastmod = date('Y-m-d', file_exists($path) ? filemtime($path) : time());
    echo "  <url>\\n";
    echo '    <loc>' . htmlspecialchars($page['loc'], ENT_QUOTES, 'UTF-8') . "</loc>\\n";
    echo '    <xhtml:link rel="alternate" hreflang="en" href="' . $base . '/"/>' . "\\n";
    echo '    <xhtml:link rel="alternate" hreflang="ja" href="' . $base . '/kurl2gr.html"/>' . "\\n";
    echo '    <lastmod>' . $lastmod . "</lastmod>\\n";
    echo '    <changefreq>weekly</changefreq>' . "\\n";
    echo '    <priority>' . $page['priority'] . "</priority>\\n";
    echo "  </url>\\n";
}
echo '</urlset>' . "\\n";
"""

TEXT = {
    "ja": {
        "lang": "ja", "canonical": "https://kurl2gr.exbridge.jp/kurl2gr.html",
        "other": ("./", "English"),
        "title": "Kurage URL2GPTResearcher | 止まったサイトに、新着が戻る",
        "desc": "サイトのURLを入れるだけ。AIが事業内容を理解し、関連する最新情報を調べて記事にし、"
                "あなたのサイトに自動で掲載します。更新の止まったコーポレートサイトを動かし続ける仕組みです。",
        "eyebrow": "KURAGE ECOSYSTEM ・ サイト更新の自動化",
        "h1": "止まったサイトに、<em>新着が戻る</em>。",
        "lead": "日本のコーポレートサイトの多くは、数年単位で更新が止まっています。"
                "人にもAIにも「動いていない会社」に見えてしまう。"
                "<b>Kurage URL2GPTResearcher</b> は、あなたのサイトの内容をAIが理解し、"
                "その事業に関連する最新情報を調べて記事にし、自動で掲載し続けます。",
        "hero_name": "Kurageさん",
        "hero_desc": "エクスブリッジのクラゲAI VTuber。あなたのサイトの新着記事を書き続けます。",
        "steps_h": "3つの工程", "steps_sub": "URLを入れるところから、記事が載るまで。",
        "steps": [
            ("サイトを読む", "公開URLをクロールし、AIが「何の会社で、どんな話題を扱っているか」を要約します。"
                          "ここが後のリサーチの土台になります。"),
            ("関連情報を調べる", "サイトの事業領域に沿って、外部の最新情報を検索・収集し、"
                             "出典付きのレポートにまとめます。キーワードを指定した調査もできます。"),
            ("記事にして載せる", "調査結果を読みやすい記事に整え、あなたのサイトへ掲載します。"
                             "手動での投稿もできます。"),
        ],
        "why_h": "なぜサイトの内容に沿わせるのか",
        "why": "関連のない記事をいくら自動投稿しても、サイトが賑やかになるだけで意味がありません。"
               "<b>必ずサイト概要を土台にして調べる</b>ので、掲載される記事はあなたの事業の延長線上にあります。"
               "調査結果に無い数字や固有名詞は書かせません。",
        "install_h": "設置は1ファイル",
        "install": [
            "あなたのサイトに置くのは<b>表示用のPHPを1つ</b>だけ。データベースもバックエンドも要りません。",
            "記事の実体はこちらが保持し、発行された鍵で<b>あなたの記事だけ</b>を読みます。",
            "既存ページに貼る<b>新着枠</b>も用意しています。RSSも配信します。",
            "投稿・編集はこちらの管理画面だけで行うため、<b>サイト側に書き込み権限を置きません</b>。",
            "設置前でも<b>管理画面で記事の全文を読めます</b>。まず中身を見てから、掲載するか決められます。",
            "対象サイトは<b>1件</b>です。2件目以降はオプションのため、ご相談ください。",
        ],
        "llm_h": "実行するAI",
        "llm": [("サイトの理解・記事化", "自社GPU上の Gemma 4、または DeepSeek"),
                ("情報の収集", "GPT Researcher（検索して読み、出典を残す）"),
                ("自動投稿", "日次のワーカーによる自動投稿は行いません。調査はあなたが実行したときだけ動きます")],
        "llm_note": "調査エンジンは <a href=\"https://github.com/assafelovic/gpt-researcher\" target=\"_blank\" rel=\"noopener\">GPT Researcher</a>（Apache-2.0）。"
                    "検索して読み、出典付きでまとめる多エージェント構成です。",
        "price_h": "料金", "price_badge": "お試し 1回200円 — 月額プランは準備中",
        "price": "まずは<b>1回200円</b>、または <b>20,000 URLAI</b> でお試しいただけます（調査1回ぶん）。"
                 "PayPal決済とBase上のURLAI送金に対応し、<b>調査が成立したときだけ</b>消費します。"
                 "継続してお使いいただく場合は <b>月額3,900円（税別）</b> のプランを準備中です。"
                 "ご利用は他のKurageプロダクトと同じくXログインです。",
        "oss_h": "OSSの上に",
        "oss": "調査の中核に <a href=\"https://github.com/assafelovic/gpt-researcher\" target=\"_blank\" rel=\"noopener\">GPT Researcher</a>（Apache-2.0）を"
               "コミット固定で利用しています。",
        "oss_small": "本サービスは GPT Researcher の公式日本語版ではなく、Apache-2.0 に基づく独立したプロダクトです。"
                     "掲載内容の正確性や、検索順位・AI回答への掲載を保証するものではありません。",
        "footer": "Kurage URL2GPTResearcher — <a href=\"https://exbridge.jp/\">株式会社エクスブリッジ</a>",
    },
    "en": {
        "lang": "en", "canonical": "https://kurl2gr.exbridge.jp/",
        "other": ("kurl2gr.html", "日本語"),
        "title": "Kurage URL2GPTResearcher | Bring a stalled site back to life",
        "desc": "Give it your site URL. AI reads what your business is about, researches related news, "
                "writes an article, and publishes it on your own site — continuously.",
        "eyebrow": "KURAGE ECOSYSTEM ・ AUTOMATED SITE UPDATES",
        "h1": "Bring a stalled site <em>back to life</em>.",
        "lead": "Most corporate sites in Japan have not been updated in years. To people and to AI alike, "
                "they look like a company that stopped moving. <b>Kurage URL2GPTResearcher</b> reads what your "
                "site is about, researches what is new in that field, writes it up, and keeps publishing.",
        "hero_name": "Kurage",
        "hero_desc": "EXBRIDGE's jellyfish AI VTuber. Keeps writing new articles for your site.",
        "steps_h": "Three steps", "steps_sub": "From a URL to a published article.",
        "steps": [
            ("Read the site", "Crawls your public URL and summarises what the business is and which topics it covers. "
                              "That summary grounds everything that follows."),
            ("Research", "Searches and collects current information in that field and compiles a sourced report. "
                         "You can also steer it with a keyword."),
            ("Publish", "Turns the findings into a readable article and publishes it on your site. "
                        "Manual posting is available too."),
        ],
        "why_h": "Why it stays close to your site",
        "why": "Auto-posting unrelated articles only makes a site noisy. Research is <b>always grounded in your "
               "site's own summary</b>, so what gets published sits on the same line as your business. "
               "Numbers and names that are not in the research are never invented.",
        "install_h": "One file to install",
        "install": [
            "You place <b>a single display-only PHP file</b>. No database, no backend on your server.",
            "Articles live on our side; a issued key lets your page read <b>only your own posts</b>.",
            "A <b>latest-posts block</b> for existing pages is included, and RSS is served.",
            "Posting and editing happen only in our admin screen, so <b>no write access sits on your site</b>.",
            "You can <b>read the full article in the admin screen</b> before installing anything, and decide then.",
            "<b>One site</b> per account. Additional sites are an option — please ask.",
        ],
        "llm_h": "What runs it",
        "llm": [("Understanding & writing", "Gemma 4 on our own GPU, or DeepSeek"),
                ("Gathering information", "GPT Researcher (searches, reads, keeps the sources)"),
                ("Automatic posting", "There is no daily worker. Research runs only when you start it")],
        "llm_note": "The research engine is <a href=\"https://github.com/assafelovic/gpt-researcher\" target=\"_blank\" rel=\"noopener\">GPT Researcher</a> (Apache-2.0), "
                    "a multi-agent setup that searches, reads and cites.",
        "price_h": "Pricing", "price_badge": "Try it for \u00a5200 a run — monthly plan in preparation",
        "price": "Start with <b>\u00a5200</b> or <b>20,000 URLAI</b> per research. PayPal and URLAI transfers "
                 "on Base are supported, and a credit is <b>consumed only when the research succeeds</b>. "
                 "For continuous use, a <b>\u00a53,900 / month (excl. tax)</b> plan is in preparation. "
                 "Access is through X login, as with other Kurage products.",
        "oss_h": "Built on OSS",
        "oss": "<a href=\"https://github.com/assafelovic/gpt-researcher\" target=\"_blank\" rel=\"noopener\">GPT Researcher</a> (Apache-2.0) is used as the research core, pinned to a fixed commit.",
        "oss_small": "This is not an official Japanese edition of GPT Researcher, but an independent product under Apache-2.0. "
                     "No guarantee is made about the accuracy of published content, search rankings, or inclusion in AI answers.",
        "footer": "Kurage URL2GPTResearcher — <a href=\"https://exbridge.jp/\">EXBRIDGE Inc.</a>",
    },
}


def page(lang: str) -> str:
    t = TEXT[lang]
    steps = "\n".join(
        f'      <div class="step"><div class="n">{i}</div><h3>{h}</h3><p>{p}</p></div>'
        for i, (h, p) in enumerate(t["steps"], 1)
    )
    install = "\n".join(f"        <li>{x}</li>" for x in t["install"])
    llm_rows = "\n".join(
        f"        <tr><td><b>{name}</b></td><td>{body}</td></tr>" for name, body in t["llm"]
    )
    schema = (
        '<script type="application/ld+json">'
        '{"@context":"https://schema.org","@type":"Product","name":"Kurage URL2GPTResearcher",'
        f'"description":"{t["desc"]}","brand":{{"@type":"Brand","name":"Kurage"}},'
        f'"url":"{t["canonical"]}","image":"https://kurl2gr.exbridge.jp/assets/ogp.png"}}'
        "</script>"
    )
    return f"""<!doctype html>
<html lang="{t['lang']}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{t['title']}</title>
<meta name="description" content="{t['desc']}">
<meta name="robots" content="index, follow">
<link rel="canonical" href="{t['canonical']}">
<link rel="alternate" hreflang="ja" href="https://kurl2gr.exbridge.jp/kurl2gr.html">
<link rel="alternate" hreflang="en" href="https://kurl2gr.exbridge.jp/">
<link rel="alternate" hreflang="x-default" href="https://kurl2gr.exbridge.jp/">
<meta property="og:type" content="website">
<meta property="og:title" content="{t['title']}">
<meta property="og:description" content="{t['desc']}">
<meta property="og:url" content="{t['canonical']}">
<meta property="og:image" content="https://kurl2gr.exbridge.jp/assets/ogp.png">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:site_name" content="Kurage URL2GPTResearcher">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:image" content="https://kurl2gr.exbridge.jp/assets/ogp.png">
{schema}
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Zen+Kaku+Gothic+New:wght@400;700;900&display=swap" rel="stylesheet">
<style>
{STYLE}</style>
{TRACKING}
</head>
<body>
<header class="top">
  <div class="wrap">
    <span class="brand"><span class="brand-mark">R</span>
      <span>Kurage URL2GPTResearcher<small>Research → Publish, automatically</small></span></span>
    <span class="spacer"></span>
    <a class="lang" href="{t['other'][0]}">{t['other'][1]}</a>
  </div>
</header>

<main>
<section class="wrap hero">
  <div>
  <span class="eyebrow">{t['eyebrow']}</span>
  <h1>{t['h1']}</h1>
  <p class="lead">{t['lead']}</p>
  <div class="cta">
    <a class="btn" href="https://kurage.exbridge.jp/kurl2gr.php{'' if lang == 'ja' else '?lang=en'}">{'𝕏 でログインしてはじめる' if lang == 'ja' else 'Sign in with 𝕏 to start'}</a>
    <a class="btn ghost" href="#steps">{'仕組みを見る' if lang == 'ja' else 'See how it works'}</a>
  </div>
  </div>
  <div class="hero-card">
    <img src="assets/kurage_avatar.webp" alt="{t['hero_name']}">
    <h2>{t['hero_name']}</h2>
    <p>{t['hero_desc']}</p>
  </div>
</section>

<section id="steps"><div class="wrap">
  <h2>{t['steps_h']}</h2><p class="sub">{t['steps_sub']}</p>
  <div class="steps">
{steps}
  </div>
</div></section>

<section><div class="wrap">
  <h2>{t['why_h']}</h2>
  <div class="box"><p style="font-size:14.5px">{t['why']}</p></div>
</div></section>

<section><div class="wrap">
  <h2>{t['install_h']}</h2>
  <div class="box"><ul>
{install}
  </ul></div>
</div></section>

<section><div class="wrap">
  <h2>{t['llm_h']}</h2>
  <div class="box">
    <div class="table-wrap"><table class="spec">
{llm_rows}
    </table></div>
    <p class="sub" style="margin:14px 0 0; text-align:left; font-size:13px">{t['llm_note']}</p>
  </div>
</div></section>

<section><div class="wrap">
  <h2>{t['price_h']}</h2>
  <div class="gate">
    <span class="badge">{t['price_badge']}</span>
    <p style="font-size:14.5px">{t['price']}</p>
  </div>
</div></section>

<section><div class="wrap">
  <h2>{t['oss_h']}</h2>
  <div class="box oss-note"><p style="font-size:14.5px">{t['oss']}</p>
    <small>{t['oss_small']}</small></div>
</div></section>
</main>

<footer><div class="wrap">{t['footer']}</div></footer>
</body>
</html>
"""


def main() -> int:
    LANDING.mkdir(parents=True, exist_ok=True)
    (LANDING / "index.html").write_text(page("en"), encoding="utf-8")
    (LANDING / "kurl2gr.html").write_text(page("ja"), encoding="utf-8")
    (LANDING / "robots.txt").write_text(
        "User-agent: *\nAllow: /\nSitemap: https://kurl2gr.exbridge.jp/sitemap.php\n",
        encoding="utf-8")
    # sitemap は .php にする（lastmod をファイルの更新時刻から自動で出すため）。
    (LANDING / "sitemap.php").write_text(SITEMAP_PHP, encoding="utf-8")
    print("生成: index.html(en) / kurl2gr.html(ja) / robots.txt / sitemap.php")
    # LPを作り直すとシリーズ紹介の差し込みが消えるので、その場で入れ直す。
    inject_ecosystem.main()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
