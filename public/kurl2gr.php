<?php
// Kurage URL2GPTResearcher (kurl2gr) — kurage.exbridge.jp 上の公開入口。
// 1) 共通Xログイン(AIxSNS OAuth2 / auth_common.php)で認証を確定し、
// 2) 検証済みXユーザー名を X-KURL2GR-User + 共有トークンでバックエンド(:18358)へ渡す
//    リバースプロキシとして動く。バックエンドは外部から直接叩けない。
// 未ログインには、LP相当の紹介＋ログイン導線を返す（kgeoと同じ型）。
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_common.php';
require_once __DIR__ . '/kurl2gr_config.php';
require_once __DIR__ . '/kurl2gr_billing.php';
date_default_timezone_set('Asia/Tokyo');

$THIS_FILE = 'kurl2gr.php';

// 言語判定: ?lang=en/ja で切替＆Cookieに保存（kgeo/url2pubと同方式）。
$lang = 'ja';
if (isset($_GET['lang'])) {
    $lang = ($_GET['lang'] === 'en') ? 'en' : 'ja';
    setcookie('kurl2gr_lang', $lang, time() + 31536000, '/');
    $_COOKIE['kurl2gr_lang'] = $lang;
} elseif (isset($_COOKIE['kurl2gr_lang']) && $_COOKIE['kurl2gr_lang'] === 'en') {
    $lang = 'en';
}

if (isset($_GET['login'])) {
    header('Location: ' . url2ai_auth_login_url('/' . $THIS_FILE));
    exit;
}
if (isset($_GET['logout'])) {
    header('Location: ' . url2ai_auth_logout_url('/' . $THIS_FILE));
    exit;
}

$auth = url2ai_auth_bootstrap();
$logged_in = !empty($auth['logged_in']);
$session_user = $logged_in ? trim((string)$auth['session_user']) : '';
$is_admin = $logged_in && !empty($auth['is_admin']);
// 管理者は利用者を選んで代理操作できる（詰まった利用者の手当て用）。
// 管理者以外は空のまま。バックエンド側でも再検証される。
$act_as = '';
if ($is_admin && isset($_GET['as'])) {
    $candidate = trim((string)$_GET['as']);
    if ($candidate !== '' && preg_match('/^[A-Za-z0-9_]{1,200}$/', $candidate)) {
        $act_as = $candidate;
    }
}
$GLOBALS['act_as'] = $act_as;
if (empty($_SESSION['kurl2gr_csrf'])) {
    $_SESSION['kurl2gr_csrf'] = bin2hex(random_bytes(24));
}
$csrf = (string)$_SESSION['kurl2gr_csrf'];

function kurl2gr_error($status, $detail) {
    http_response_code((int)$status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, max-age=0');
    echo json_encode(array('detail' => $detail), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// 通すのはこの一覧だけ。バックエンドの公開API(/public/*)はここからは通さない
// （顧客サイトの sns.php が site_key で直接読むもので、ログイン利用者の経路ではない）。
function kurl2gr_route_allowed($path, $method) {
    if ($path === '/billing/status') { return $method === 'GET'; }
    if (in_array($path, array('/billing/paypal', '/billing/urlai'), true)) { return $method === 'POST'; }
    if (in_array($path, array('/health', '/api/usage', '/api/admin/users'), true)) {
        return $method === 'GET';
    }
    if ($path === '/api/sites') { return in_array($method, array('GET', 'POST'), true); }
    if (preg_match('#^/api/sites/[a-f0-9]{12}/overview$#', $path)) { return $method === 'POST'; }
    if (preg_match('#^/api/sites/[a-f0-9]{12}/research$#', $path)) {
        return in_array($method, array('GET', 'POST'), true);
    }
    if (preg_match('#^/api/sites/[a-f0-9]{12}/posts$#', $path)) {
        return in_array($method, array('GET', 'POST'), true);
    }
    if (preg_match('#^/api/research/[a-f0-9]{12}$#', $path)) { return $method === 'GET'; }
    if (preg_match('#^/api/posts/[0-9]{1,12}/publish\?published=(true|false)$#', $path)) {
        return $method === 'POST';
    }
    return false;
}

function kurl2gr_act_as_header() {
    $act_as = isset($GLOBALS['act_as']) ? (string)$GLOBALS['act_as'] : '';
    return $act_as === '' ? array() : array('X-KURL2GR-Act-As: ' . $act_as);
}

function kurl2gr_proxy($method, $path, $user, $billing_mode = null) {
    // 調査はバックエンドで非同期に走り、ここは即座に返る。長い待ちは持たない。
    @set_time_limit(120);
    $headers = array_merge(array(
        'Accept: application/json',
        'Content-Type: application/json',
        'X-KURL2GR-Token: ' . KURL2GR_API_TOKEN,
        'X-KURL2GR-User: ' . $user,
    ), kurl2gr_act_as_header());
    $ch = curl_init(rtrim(KURL2GR_API_BASE, '/') . $path);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 110,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
    ));
    if (in_array($method, array('POST', 'PUT'), true)) {
        $raw = file_get_contents('php://input');
        if (strlen($raw) > 100000) { kurl2gr_error(413, '入力が大きすぎます'); }
        if ($raw !== '') {
            json_decode($raw);
            if (json_last_error() !== JSON_ERROR_NONE) { kurl2gr_error(400, 'JSONを確認してください'); }
        }
        // 空ボディでもPOSTFIELDSを設定する。未設定だとContent-Lengthが付かず411になる。
        curl_setopt($ch, CURLOPT_POSTFIELDS, $raw);
    }
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    if ($body === false || $error !== '') {
        kurl2gr_error(502, 'Kurage URL2GPTResearcher APIへ接続できません');
    }
    // 課金は調査ジョブ単位。開始で引き当て、失敗で戻す（本文からジョブIDを読む）。
    if ($billing_mode !== null && $status >= 200 && $status < 300) {
        $parsed = json_decode((string)$body, true);
        $job_id = is_array($parsed) && isset($parsed['id']) ? (string)$parsed['id'] : '';
        $job_state = is_array($parsed) && isset($parsed['status']) ? (string)$parsed['status'] : '';
        if ($billing_mode === 'reserve' && !kurl2gr_bill_reserve($user, $job_id)) {
            kurl2gr_error(402, 'PAYMENT_REQUIRED');
        }
        if ($billing_mode === 'settle' && $job_state === 'failed') {
            kurl2gr_bill_refund($user, $job_id);
        }
        if ($billing_mode === 'settle' && $job_state === 'done') {
            kurl2gr_bill_settle($job_id);
        }
    }
    http_response_code($status ?: 502);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, max-age=0');
    echo $body;
    exit;
}

// 画面のCSS/JSはこのファイル経由で配る（/static を公開サーバーに置かないため）。
if (isset($_GET['asset'])) {
    $assets = array('styles.css' => 'assets/kurl2gr.css', 'app.js' => 'assets/kurl2gr.js');
    $name = (string)$_GET['asset'];
    if (!isset($assets[$name])) { http_response_code(404); exit; }
    $path = realpath(__DIR__ . '/' . $assets[$name]);
    $root = realpath(__DIR__ . '/assets');
    // ローカル開発ツリーではデプロイ用assetsの代わりに ../static を参照する。
    if (!$path || !$root) {
        $fallback = array('styles.css' => '../static/styles.css', 'app.js' => '../static/app.js');
        $path = realpath(__DIR__ . '/' . $fallback[$name]);
        $root = realpath(__DIR__ . '/../static');
    }
    if (!$path || !$root || strpos($path, $root . DIRECTORY_SEPARATOR) !== 0) { http_response_code(404); exit; }
    header('Content-Type: ' . (substr($name, -3) === '.js' ? 'application/javascript; charset=utf-8' : 'text/css; charset=utf-8'));
    header('Cache-Control: public, max-age=3600');
    readfile($path);
    exit;
}

if (isset($_GET['api'])) {
    if (!$logged_in || $session_user === '') { kurl2gr_error(401, 'Xログインが必要です'); }
    if (strlen($session_user) > 200 || preg_match('/[\x00-\x1F\x7F]/', $session_user)) {
        kurl2gr_error(401, 'ログイン情報を確認できません');
    }
    $path = rawurldecode((string)$_GET['api']);
    $method = strtoupper((string)(isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET'));
    if (!kurl2gr_route_allowed($path, $method)) { kurl2gr_error(404, 'Unknown route'); }
    if ($method !== 'GET') {
        $sent = (string)(isset($_SERVER['HTTP_X_CSRF_TOKEN']) ? $_SERVER['HTTP_X_CSRF_TOKEN'] : '');
        if (!$sent || !hash_equals($csrf, $sent)) { kurl2gr_error(403, 'CSRF検証に失敗しました'); }
    }
    if ($path === '/billing/status') {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, max-age=0');
        echo json_encode(array(
            // 管理者(xb_bittensor)は課金対象外。
            'admin_bypass' => $is_admin,
            'credits' => kurl2gr_bill_credits($session_user),
            'price_jpy' => KURL2GR_PRICE_JPY,
            'price_urlai' => KURL2GR_PRICE_URLAI,
            'urlai_receiver' => KURL2GR_URLAI_RECEIVER,
            'paypal_client_id' => KURL2GR_PAYPAL_CLIENT_ID,
            // 月額プランは未実装。画面では案内だけ出す。
            'monthly_jpy' => KURL2GR_MONTHLY_JPY,
            'monthly_available' => false,
        ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    if (in_array($path, array('/billing/paypal', '/billing/urlai'), true)) {
        $input = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($input)) { kurl2gr_error(400, 'JSONを確認してください'); }
        if ($path === '/billing/paypal') {
            list($ok, $message) = kurl2gr_bill_grant_paypal(
                $session_user, isset($input['order_id']) ? $input['order_id'] : ''
            );
        } else {
            list($ok, $message) = kurl2gr_bill_grant_urlai(
                $session_user, isset($input['wallet']) ? $input['wallet'] : ''
            );
        }
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, max-age=0');
        echo json_encode(array(
            'ok' => $ok, 'message' => $message,
            'credits' => kurl2gr_bill_credits($session_user),
        ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    if ($method === 'POST' && preg_match('#^/api/sites/[a-f0-9]{12}/research$#', $path)) {
        // 管理者は課金ゲートを通さない。それ以外は開始時に1クレジット引き当て。
        if ($is_admin) { kurl2gr_proxy($method, $path, $session_user); }
        if (kurl2gr_bill_credits($session_user) < 1) { kurl2gr_error(402, 'PAYMENT_REQUIRED'); }
        kurl2gr_proxy($method, $path, $session_user, 'reserve');
    }
    if ($method === 'GET' && preg_match('#^/api/research/[a-f0-9]{12}$#', $path)) {
        // 進捗の取得ついでに、失敗したジョブの引き当てを戻す。
        kurl2gr_proxy($method, $path, $session_user, $is_admin ? null : 'settle');
    }
    kurl2gr_proxy($method, $path, $session_user);
}

// UI文言（日本語 / English）。ロジックは1本、文言だけ差し替える。
$T_ALL = array(
'ja' => array(
  'title' => 'Kurage URL2GPTResearcher | サイトの内容に沿った新着記事を自動で増やす',
  'meta_desc' => 'サイトのURLを入れるだけ。AIが事業内容を理解し、関連する最新情報を調べて記事にして、あなたのサイトに自動で掲載します。更新の止まったコーポレートサイトを動かし続ける仕組みです。',
  'og_locale' => 'ja_JP',
  'brand_sub' => 'サイト更新の自動化ワークスペース',
  'nav_login' => '𝕏 でログイン',
  'nav_about' => 'kurl2grについて',
  'eyebrow' => 'AIリサーチ ・ サイト更新の自動化',
  'h1' => '止まったサイトに、<em>新着が戻る。</em>',
  'lead' => '日本のコーポレートサイトの多くは、数年単位で更新が止まっています。人にもAIにも「動いていない会社」に見えてしまう。<b>Kurage URL2GPTResearcher</b> は、あなたのサイトの内容をAIが理解し、その事業に<b>関連する最新情報を調べて記事にし</b>、あなたのサイトへ自動で掲載し続けます。',
  'cta_login' => '𝕏 でログインしてはじめる',
  'cta_note' => '共通Xアカウントでログインするだけ。お試しは1回200円です。',
  'hero_name' => 'Kurageさん',
  'hero_desc' => 'エクスブリッジのクラゲAI VTuber。あなたのサイトの新着記事を書き続けます。',
  'feat_title' => '3つの工程',
  'feat_sub' => 'URLを入れるところから、記事が載るまで。',
  'chip1' => '① サイトを読む', 'chip1s' => '公開URLをクロールし、事業内容と話題を要約',
  'chip2' => '② 関連情報を調べる', 'chip2s' => '外部の最新情報を検索・収集し、出典付きで整理',
  'chip3' => '③ 記事にして載せる', 'chip3s' => '読みやすい記事にして、あなたのサイトへ掲載',
  'chip4' => '勝手には投稿しない', 'chip4s' => '日次ワーカーによる自動投稿はなし。実行はあなたの操作から',
  'price_title' => '料金 — お試しは1回200円',
  'price_sub' => '使った分だけ。月額プランは準備中です。',
  'price1' => 'お試しは <b>1調査 200円</b> または <b>20,000 URLAI</b>。',
  'price2' => 'PayPal決済とBase上のURLAI送金に対応。<b>調査が成立したときだけ</b>消費します。',
  'price3' => '継続してお使いの場合は <b>月額3,900円（税別）</b> のプランを準備中です。',
  'price4' => '日次ワーカーによる自動投稿は行いません。調査はあなたが実行したときだけ動きます。',
  'install_title' => '設置は1ファイル',
  'install_sub' => '顧客サイトに置くのは表示専用のPHPだけ。',
  'install1' => 'あなたのサイトに置くのは<b>表示用のPHPを1つ</b>だけ。データベースもバックエンドも要りません。',
  'install2' => '記事の実体はこちらが保持し、発行された鍵で<b>あなたの記事だけ</b>を読みます。',
  'install3' => '既存ページに貼る<b>新着枠</b>も用意しています。RSSも配信します。',
  'install4' => '投稿・編集はこの管理画面だけで行うため、<b>サイト側に書き込み権限を置きません</b>。',
  'oss_title' => 'OSSの上に',
  'oss_p' => '調査の中核に <a href="https://github.com/assafelovic/gpt-researcher" target="_blank" rel="noopener">GPT Researcher</a>（Apache-2.0）をコミット固定で利用しています。検索して読み、出典付きでまとめる多エージェント構成です。',
  'oss_note' => 'Kurage URL2GPTResearcher は GPT Researcher の公式日本語版ではなく、Apache-2.0 に基づく独立したプロダクトです。掲載内容の正確性や、検索順位・AI回答への掲載を保証するものではありません。',
  'footer_product' => '株式会社エクスブリッジのプロダクト',
  'footer_contact' => 'お問い合わせ',
),
'en' => array(
  'title' => 'Kurage URL2GPTResearcher | Keep your site publishing, automatically',
  'meta_desc' => 'Give it your site URL. AI reads what your business is about, researches related news, writes an article, and publishes it on your own site — continuously.',
  'og_locale' => 'en_US',
  'brand_sub' => 'Automated site-update workspace',
  'nav_login' => 'Sign in with 𝕏',
  'nav_about' => 'About kurl2gr',
  'eyebrow' => 'AI RESEARCH ・ AUTOMATED SITE UPDATES',
  'h1' => 'Bring a stalled site <em>back to life.</em>',
  'lead' => 'Most corporate sites in Japan have not been updated in years. To people and to AI alike, they look like a company that stopped moving. <b>Kurage URL2GPTResearcher</b> reads what your site is about, <b>researches what is new in that field</b>, writes it up, and keeps publishing on your site.',
  'cta_login' => 'Sign in with 𝕏 to start',
  'cta_note' => 'Just sign in with your X account. A trial run is \u00a5200.',
  'hero_name' => 'Kurage',
  'hero_desc' => 'EXBRIDGE\'s jellyfish AI VTuber. Keeps writing new articles for your site.',
  'feat_title' => 'Three steps',
  'feat_sub' => 'From a URL to a published article.',
  'chip1' => '1. Read the site', 'chip1s' => 'Crawls your public URL and summarises the business and its topics',
  'chip2' => '2. Research', 'chip2s' => 'Searches and collects current information, with sources',
  'chip3' => '3. Publish', 'chip3s' => 'Turns findings into a readable article on your site',
  'chip4' => 'It never posts on its own', 'chip4s' => 'No daily worker. Research runs only when you start it',
  'price_title' => 'Pricing — \u00a5200 per run to try',
  'price_sub' => 'Pay for what you use. A monthly plan is in preparation.',
  'price1' => 'A trial run is <b>\u00a5200</b> or <b>20,000 URLAI</b> per research.',
  'price2' => 'PayPal and URLAI transfers on Base are supported. A credit is <b>consumed only when the research succeeds</b>.',
  'price3' => 'For continuous use, a <b>\u00a53,900 / month (excl. tax)</b> plan is in preparation.',
  'price4' => 'There is no daily worker. Research runs only when you start it.',
  'install_title' => 'One file to install',
  'install_sub' => 'What sits on your site is a display-only PHP file.',
  'install1' => 'You place <b>a single display-only PHP file</b>. No database, no backend on your server.',
  'install2' => 'Articles live on our side; an issued key lets your page read <b>only your own posts</b>.',
  'install3' => 'A <b>latest-posts block</b> for existing pages is included, and RSS is served.',
  'install4' => 'Posting and editing happen only in this admin screen, so <b>no write access sits on your site</b>.',
  'oss_title' => 'Built on OSS',
  'oss_p' => '<a href="https://github.com/assafelovic/gpt-researcher" target="_blank" rel="noopener">GPT Researcher</a> (Apache-2.0) is used as the research core, pinned to a fixed commit. It is a multi-agent setup that searches, reads and cites.',
  'oss_note' => 'This is not an official Japanese edition of GPT Researcher, but an independent product under Apache-2.0. No guarantee is made about the accuracy of published content, search rankings, or inclusion in AI answers.',
  'footer_product' => 'A product of EXBRIDGE, Inc.',
  'footer_contact' => 'Contact',
),
);
$T = $T_ALL[$lang];

if (!$logged_in):
?><!doctype html>
<html lang="<?php echo $lang; ?>"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo htmlspecialchars($T['title'], ENT_QUOTES, 'UTF-8'); ?></title>
<meta name="description" content="<?php echo htmlspecialchars($T['meta_desc'], ENT_QUOTES, 'UTF-8'); ?>">
<meta name="robots" content="index, follow">
<link rel="canonical" href="https://kurage.exbridge.jp/kurl2gr.php<?php echo $lang === 'en' ? '?lang=en' : ''; ?>">
<meta property="og:type" content="website">
<meta property="og:locale" content="<?php echo $T['og_locale']; ?>">
<meta property="og:title" content="<?php echo htmlspecialchars($T['title'], ENT_QUOTES, 'UTF-8'); ?>">
<meta property="og:description" content="<?php echo htmlspecialchars($T['meta_desc'], ENT_QUOTES, 'UTF-8'); ?>">
<meta property="og:url" content="https://kurage.exbridge.jp/kurl2gr.php">
<meta property="og:image" content="https://kurl2gr.exbridge.jp/assets/ogp.png">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:site_name" content="Kurage URL2GPTResearcher">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:image" content="https://kurl2gr.exbridge.jp/assets/ogp.png">
<script type="application/ld+json">
{
  "@context":"https://schema.org",
  "@type":"SoftwareApplication",
  "name":"Kurage URL2GPTResearcher",
  "applicationCategory":"BusinessApplication",
  "operatingSystem":"Web",
  "url":"https://kurage.exbridge.jp/kurl2gr.php",
  "isBasedOn":{"@type":"SoftwareSourceCode","name":"GPT Researcher","codeRepository":"https://github.com/assafelovic/gpt-researcher","license":"https://www.apache.org/licenses/LICENSE-2.0"},
  "featureList":["サイトのクロールと概要理解","概要に沿った関連情報のAIリサーチ","出典付きレポートの記事化","顧客サイトへの自動掲載","手動投稿"],
  "publisher":{"@type":"Organization","name":"Kurageプロジェクト","url":"https://kurage.exbridge.jp/"}
}
</script>
<script async src="https://www.googletagmanager.com/gtag/js?id=G-BP0650KDFR"></script>
<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments)}gtag('js',new Date());gtag('config','G-BP0650KDFR');</script>
<script>(function(){var s=document.createElement('script');s.src='https://aiknowledgecms.exbridge.jp/simpletrack.php?url='+encodeURIComponent(location.href)+'&ref='+encodeURIComponent(document.referrer);document.head.appendChild(s)})();</script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Zen+Maru+Gothic:wght@700;900&family=Zen+Kaku+Gothic+New:wght@400;500;700&display=swap" rel="stylesheet">
<style>
:root {
  --abyss:#12202f; --abyss-soft:#55697a; --foam:#f5fbfb; --panel:#e7f3f2; --panel-line:#cde5e2;
  --teal:#12a99f; --teal-deep:#0a726b; --gold:#c98a1e; --gold-bg:#fbf2db; --gold-line:#ecd9a8;
  --shadow:0 14px 40px rgba(10,40,45,.10);
}
@media (prefers-color-scheme:dark){:root{
  --abyss:#eaf3f3; --abyss-soft:#9fb3ba; --foam:#0c1720; --panel:#12242a; --panel-line:#1f3a3f;
  --teal:#2bd4c6; --teal-deep:#1c9e93; --gold:#f2c766; --gold-bg:#241b08; --gold-line:#4c3c17;
  --shadow:0 14px 40px rgba(0,0,0,.38);
}}
* { box-sizing:border-box; margin:0; padding:0; }
html { scroll-behavior:smooth; }
body { color:var(--abyss); font-family:"Zen Kaku Gothic New","Hiragino Sans","Yu Gothic",Meiryo,sans-serif; background:var(--foam); min-height:100vh; line-height:1.8; overflow-x:hidden; }
a { color:var(--teal-deep); text-decoration:none; }
a:hover { color:var(--teal); }
img { max-width:100%; }
h1,h2,h3 { font-family:"Zen Maru Gothic","Zen Kaku Gothic New",sans-serif; text-wrap:balance; }
.wrap { max-width:1000px; margin:0 auto; padding:0 24px; }
header.site { position:sticky; top:0; z-index:40; background:color-mix(in srgb,var(--foam) 88%,transparent); backdrop-filter:blur(16px); border-bottom:1px solid var(--panel-line); }
header.site .wrap { display:flex; align-items:center; gap:12px; padding:12px 24px; flex-wrap:wrap; }
.hbrand { display:flex; gap:12px; align-items:center; }
.hbrand .ico { width:40px; height:40px; border-radius:50%; overflow:hidden; border:2px solid var(--teal); flex:none; }
.hbrand .ico img { width:100%; height:100%; object-fit:cover; object-position:50% 15%; display:block; }
.hbrand strong { font-size:15px; font-weight:900; display:block; line-height:1.2; }
.hbrand span { font-size:11px; color:var(--abyss-soft); }
.hnav { display:flex; gap:8px; align-items:center; margin-left:auto; flex-wrap:wrap; }
.langswitch { display:inline-flex; border:1.5px solid var(--panel-line); border-radius:999px; overflow:hidden; }
.langswitch a { padding:6px 12px; font-size:12px; font-weight:800; color:var(--abyss-soft); }
.langswitch a.on { background:var(--teal); color:#fff; }
.btn { border-radius:999px; padding:10px 18px; font-weight:900; font-size:13px; display:inline-flex; align-items:center; gap:7px; border:1.5px solid transparent; cursor:pointer; text-decoration:none; }
.btn-primary { background:linear-gradient(135deg,var(--teal),var(--teal-deep)); color:#fff; box-shadow:0 10px 24px rgba(18,169,159,.28); }
.btn-ghost { background:transparent; border-color:var(--panel-line); color:var(--abyss-soft); }
.btn-ghost:hover { border-color:var(--teal); color:var(--teal-deep); }
.hero { display:grid; grid-template-columns:1.25fr .75fr; gap:40px; align-items:center; padding:56px 0 34px; }
.eyebrow { display:inline-flex; align-items:center; gap:8px; background:var(--panel); border:1.5px solid var(--panel-line); border-radius:999px; padding:7px 14px; font-size:12px; font-weight:900; color:var(--teal-deep); margin-bottom:18px; }
.dot { width:7px; height:7px; border-radius:50%; background:var(--teal); }
h1 { font-size:clamp(26px,4.4vw,42px); font-weight:900; line-height:1.3; letter-spacing:-.01em; margin-bottom:16px; }
h1 em { font-style:normal; color:var(--teal-deep); }
.lead { font-size:15.5px; color:var(--abyss-soft); max-width:600px; margin-bottom:24px; }
.lead b { color:var(--abyss); }
.cta-row { display:flex; gap:10px; flex-wrap:wrap; align-items:center; }
.cta-note { font-size:12px; color:var(--abyss-soft); }
.hero-card { background:var(--panel); border:1.5px solid var(--panel-line); border-radius:28px; padding:24px; text-align:center; box-shadow:var(--shadow); }
.hero-card img { width:180px; height:180px; object-fit:cover; object-position:50% 8%; border-radius:22px; }
.hero-card h2 { font-size:15px; margin:14px 0 4px; }
.hero-card p { font-size:12.5px; color:var(--abyss-soft); }
section { padding:34px 0; }
section > h2 { font-size:clamp(20px,3vw,27px); font-weight:900; margin-bottom:6px; }
section > .sub { color:var(--abyss-soft); font-size:14px; font-weight:700; margin-bottom:24px; }
.chips { display:flex; flex-wrap:wrap; gap:10px; }
.chip { background:var(--panel); border:1.5px solid var(--panel-line); border-radius:14px; padding:14px 18px; font-weight:700; font-size:14px; }
.chip span { display:block; font-size:12px; color:var(--abyss-soft); font-weight:500; margin-top:3px; }
.gate { background:var(--gold-bg); border:1.5px solid var(--gold-line); border-radius:24px; padding:28px; box-shadow:var(--shadow); }
.gate h2 { color:var(--gold); }
.gate ul { list-style:none; margin-top:14px; display:grid; gap:10px; }
.gate li { font-size:14px; padding-left:26px; position:relative; }
.gate li::before { content:"✓"; position:absolute; left:0; color:var(--gold); font-weight:900; }
.oss-note { background:var(--foam); border:1.5px solid var(--panel-line); border-radius:24px; padding:28px; box-shadow:var(--shadow); }
.oss-note p { font-size:14px; color:var(--abyss-soft); margin-top:10px; }
.oss-note a { font-weight:800; color:var(--teal-deep); }
.oss-note small { display:block; margin-top:10px; font-size:12px; color:var(--abyss-soft); }
footer.site { text-align:center; color:var(--abyss-soft); font-size:12.5px; padding:44px 20px 54px; border-top:1px solid var(--panel-line); margin-top:24px; }
footer.site a { font-weight:700; }
@media (max-width:760px){ header.site .wrap{ padding:10px 16px; } .wrap{ padding:0 16px; } .hero{ grid-template-columns:1fr; gap:20px; } .hero-card{ order:-1; } .hero-card img{ width:120px; height:120px; } }
</style></head><body>

<header class="site"><div class="wrap">
  <a class="hbrand" href="<?php echo $THIS_FILE; ?>">
    <span class="ico"><img src="images/kurage_avatar_face.webp" alt="Kurage"></span>
    <div><strong>Kurage URL2GPTResearcher</strong><span><?php echo htmlspecialchars($T['brand_sub'], ENT_QUOTES, 'UTF-8'); ?></span></div>
  </a>
  <nav class="hnav">
    <div class="langswitch">
      <a href="?lang=ja"<?php echo $lang === 'ja' ? ' class="on"' : ''; ?>>日本語</a>
      <a href="?lang=en"<?php echo $lang === 'en' ? ' class="on"' : ''; ?>>English</a>
    </div>
    <a class="btn btn-ghost" href="https://kurl2gr.exbridge.jp/<?php echo $lang === 'en' ? '' : 'kurl2gr.html'; ?>" target="_blank" rel="noopener"><?php echo htmlspecialchars($T['nav_about'], ENT_QUOTES, 'UTF-8'); ?></a>
    <a class="btn btn-primary" href="?login=1"><?php echo htmlspecialchars($T['nav_login'], ENT_QUOTES, 'UTF-8'); ?></a>
  </nav>
</div></header>

<main class="wrap">
  <section class="hero">
    <div>
      <span class="eyebrow"><span class="dot"></span><?php echo htmlspecialchars($T['eyebrow'], ENT_QUOTES, 'UTF-8'); ?></span>
      <h1><?php echo $T['h1']; ?></h1>
      <p class="lead"><?php echo $T['lead']; ?></p>
      <div class="cta-row">
        <a class="btn btn-primary" href="?login=1"><?php echo htmlspecialchars($T['cta_login'], ENT_QUOTES, 'UTF-8'); ?></a>
        <span class="cta-note"><?php echo htmlspecialchars($T['cta_note'], ENT_QUOTES, 'UTF-8'); ?></span>
      </div>
    </div>
    <div class="hero-card">
      <img src="images/kurage_avatar_face.webp" alt="<?php echo htmlspecialchars($T['hero_name'], ENT_QUOTES, 'UTF-8'); ?>">
      <h2><?php echo htmlspecialchars($T['hero_name'], ENT_QUOTES, 'UTF-8'); ?></h2>
      <p><?php echo htmlspecialchars($T['hero_desc'], ENT_QUOTES, 'UTF-8'); ?></p>
    </div>
  </section>

  <section>
    <h2><?php echo htmlspecialchars($T['feat_title'], ENT_QUOTES, 'UTF-8'); ?></h2>
    <p class="sub"><?php echo htmlspecialchars($T['feat_sub'], ENT_QUOTES, 'UTF-8'); ?></p>
    <div class="chips">
      <div class="chip"><?php echo htmlspecialchars($T['chip1'], ENT_QUOTES, 'UTF-8'); ?><span><?php echo htmlspecialchars($T['chip1s'], ENT_QUOTES, 'UTF-8'); ?></span></div>
      <div class="chip"><?php echo htmlspecialchars($T['chip2'], ENT_QUOTES, 'UTF-8'); ?><span><?php echo htmlspecialchars($T['chip2s'], ENT_QUOTES, 'UTF-8'); ?></span></div>
      <div class="chip"><?php echo htmlspecialchars($T['chip3'], ENT_QUOTES, 'UTF-8'); ?><span><?php echo htmlspecialchars($T['chip3s'], ENT_QUOTES, 'UTF-8'); ?></span></div>
      <div class="chip"><?php echo htmlspecialchars($T['chip4'], ENT_QUOTES, 'UTF-8'); ?><span><?php echo htmlspecialchars($T['chip4s'], ENT_QUOTES, 'UTF-8'); ?></span></div>
    </div>
  </section>

  <section>
    <h2><?php echo htmlspecialchars($T['install_title'], ENT_QUOTES, 'UTF-8'); ?></h2>
    <p class="sub"><?php echo htmlspecialchars($T['install_sub'], ENT_QUOTES, 'UTF-8'); ?></p>
    <div class="oss-note">
      <p><?php echo $T['install1']; ?></p>
      <p><?php echo $T['install2']; ?></p>
      <p><?php echo $T['install3']; ?></p>
      <p><?php echo $T['install4']; ?></p>
    </div>
  </section>

  <section>
    <div class="gate">
      <h2><?php echo htmlspecialchars($T['price_title'], ENT_QUOTES, 'UTF-8'); ?></h2>
      <p class="sub" style="margin:6px 0 0"><?php echo htmlspecialchars($T['price_sub'], ENT_QUOTES, 'UTF-8'); ?></p>
      <ul>
        <li><?php echo $T['price1']; ?></li>
        <li><?php echo $T['price2']; ?></li>
        <li><?php echo $T['price3']; ?></li>
        <li><?php echo $T['price4']; ?></li>
      </ul>
    </div>
  </section>

  <section>
    <h2><?php echo htmlspecialchars($T['oss_title'], ENT_QUOTES, 'UTF-8'); ?></h2>
    <div class="oss-note">
      <p><?php echo $T['oss_p']; ?></p>
      <small><?php echo htmlspecialchars($T['oss_note'], ENT_QUOTES, 'UTF-8'); ?></small>
    </div>
  </section>

<!-- BEGIN kurage-ecosystem (scripts/inject_ecosystem.py が生成) -->
<style>
  .kx-sec { max-width:1080px; margin:0 auto; padding:38px 20px; }
  .kx-h { font-size:clamp(20px,3vw,27px); font-weight:800; text-align:center; margin-bottom:6px; }
  .kx-sub { text-align:center; color:var(--abyss-soft); font-size:14px; margin-bottom:24px; }
  .kx-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(270px,1fr)); gap:16px; }
  .kx-card { display:block; background:var(--panel); border:1px solid var(--panel-line);
    border-radius:16px; overflow:hidden; box-shadow:var(--shadow); transition:transform .15s; }
  .kx-card:hover { transform:translateY(-3px); }
  .kx-card .kx-im { aspect-ratio:1200/630; background:var(--foam); overflow:hidden; }
  .kx-card .kx-im img { width:100%; height:100%; object-fit:cover; display:block; }
  .kx-card .kx-tx { padding:14px 16px 16px; }
  .kx-card .kx-nm { font-weight:800; font-size:15px; color:var(--abyss); }
  .kx-card .kx-ds { font-size:12.5px; color:var(--abyss-soft); margin-top:5px; line-height:1.7; }
  .kx-card .kx-lk { font-size:12px; color:var(--teal-deep); font-weight:700; margin-top:8px; word-break:break-all; }
  .kx-vision { background:var(--gold-bg); border:1px solid var(--gold-line); border-radius:18px; padding:26px; }
  .kx-vision p { font-size:14.5px; }
  .kx-vision ul { padding-left:22px; margin-top:12px; font-size:14px; color:var(--abyss); }
  .kx-vision li { margin-bottom:8px; }
  .kx-vision b { color:var(--gold); }
  .kx-fine { font-size:12px; color:var(--abyss-soft); margin-top:14px; }
</style>
<?php if ($lang === 'en'): ?>
<section class="kx-sec" id="kurage-ecosystem">
  <h2 class="kx-h">The Kurage series</h2>
  <p class="kx-sub">Kurage URL2GPTResearcher is one product in the Kurage ecosystem.</p>
  <div class="kx-grid">
    <a class="kx-card" href="https://kfreqai.exbridge.jp/" target="_blank" rel="noopener">
      <div class="kx-im"><img src="https://kfreqai.exbridge.jp/assets/ogp.png" alt="📈 Kurage FreqAI Trade" loading="lazy"></div>
      <div class="kx-tx"><div class="kx-nm">📈 Kurage FreqAI Trade</div>
        <div class="kx-ds">Self-improving crypto AI trading that researches its own losses. The whole process is published on the blog.</div>
        <div class="kx-lk">kfreqai.exbridge.jp →</div></div>
    </a>
    <a class="kx-card" href="https://kurage.exbridge.jp/kfreqaihl.php" target="_blank" rel="noopener">
      <div class="kx-im"><img src="https://kurage.exbridge.jp/images/kfreqaihl_ogp.png" alt="🌊 Kurage FreqAI for Hyperliquid" loading="lazy"></div>
      <div class="kx-tx"><div class="kx-nm">🌊 Kurage FreqAI for Hyperliquid</div>
        <div class="kx-ds">AI auto-trading with one wallet and no server. From crypto to FX, gold and equity indices.</div>
        <div class="kx-lk">kurage.exbridge.jp/kfreqaihl.php →</div></div>
    </a>
    <a class="kx-card" href="https://kfxai.exbridge.jp/" target="_blank" rel="noopener">
      <div class="kx-im"><img src="https://kfxai.exbridge.jp/assets/ogp.png" alt="💱 Kurage FX AI Trade" loading="lazy"></div>
      <div class="kx-tx"><div class="kx-nm">💱 Kurage FX AI Trade</div>
        <div class="kx-ds">OANDA-API FX automation with a swappable AI judgment layer. Yen pairs are being validated on paper trading.</div>
        <div class="kx-lk">kfxai.exbridge.jp →</div></div>
    </a>
    <a class="kx-card" href="https://llm2api.exbridge.jp/" target="_blank" rel="noopener">
      <div class="kx-im"><img src="https://llm2api.exbridge.jp/assets/ogp.png" alt="🤖 LLM2API" loading="lazy"></div>
      <div class="kx-tx"><div class="kx-nm">🤖 LLM2API</div>
        <div class="kx-ds">Pay-per-call LLM inference for agents. OpenAI-compatible, billed per request over x402.</div>
        <div class="kx-lk">llm2api.exbridge.jp →</div></div>
    </a>
    <a class="kx-card" href="https://kurage.exbridge.jp/karchitect.php" target="_blank" rel="noopener">
      <div class="kx-im"><img src="https://kurage.exbridge.jp/images/karchitect-ogp.png" alt="🏗️ Kurage Architect" loading="lazy"></div>
      <div class="kx-tx"><div class="kx-nm">🏗️ Kurage Architect</div>
        <div class="kx-ds">Build a system design document by talking with AI: requirements, Mermaid diagrams and PDF export.</div>
        <div class="kx-lk">kurage.exbridge.jp/karchitect.php →</div></div>
    </a>
    <a class="kx-card" href="https://url2ai.exbridge.jp/" target="_blank" rel="noopener">
      <div class="kx-im"><img src="https://url2ai.exbridge.jp/assets/ogp.png" alt="📝 Kurage URL2AI Publisher" loading="lazy"></div>
      <div class="kx-tx"><div class="kx-nm">📝 Kurage URL2AI Publisher</div>
        <div class="kx-ds">Give it a URL and Kurage reads the page, writes an announcement and a blog post, then publishes to five channels.</div>
        <div class="kx-lk">url2ai.exbridge.jp →</div></div>
    </a>
    <a class="kx-card" href="https://kmontage.exbridge.jp/" target="_blank" rel="noopener">
      <div class="kx-im"><img src="https://kmontage.exbridge.jp/assets/ogp.png" alt="🎬 kmontage" loading="lazy"></div>
      <div class="kx-tx"><div class="kx-nm">🎬 kmontage</div>
        <div class="kx-ds">Kurage's video production system that generates montages automatically from a script.</div>
        <div class="kx-lk">kmontage.exbridge.jp →</div></div>
    </a>
    <a class="kx-card" href="https://github.com/katsushi2441/kargov" target="_blank" rel="noopener">
      <div class="kx-im"><img src="https://kurl2earn.exbridge.jp/assets/cards/kargov.png" alt="🖱️ Kurage Argo Video（kargov）" loading="lazy"></div>
      <div class="kx-tx"><div class="kx-nm">🖱️ Kurage Argo Video（kargov）</div>
        <div class="kx-ds">A pipeline that turns recordings of AI browser operations into demo and manual videos.</div>
        <div class="kx-lk">github.com/katsushi2441/kargov →</div></div>
    </a>
    <a class="kx-card" href="https://kurage.exbridge.jp/kgeo.php" target="_blank" rel="noopener">
      <div class="kx-im"><img src="https://kgeo.exbridge.jp/assets/ogp.png" alt="🔍 Kurage GEO" loading="lazy"></div>
      <div class="kx-tx"><div class="kx-nm">🔍 Kurage GEO</div>
        <div class="kx-ds">Audits how easily AI search can understand and cite your site, and shows what to fix next.</div>
        <div class="kx-lk">kurage.exbridge.jp/kgeo.php →</div></div>
    </a>
    <a class="kx-card" href="https://kurage.exbridge.jp/" target="_blank" rel="noopener">
      <div class="kx-im"><img src="https://kurage.exbridge.jp/images/kurage_ogp.png" alt="🪼 Kurage（総合ポータル）" loading="lazy"></div>
      <div class="kx-tx"><div class="kx-nm">🪼 Kurage（総合ポータル）</div>
        <div class="kx-ds">The entrance to the Kurage series. All products and intro videos in one place.</div>
        <div class="kx-lk">kurage.exbridge.jp →</div></div>
    </a>
  </div>
</section>

<section class="kx-sec" id="urlai">
  <h2 class="kx-h">URLAI is the ecosystem token</h2>
  <div class="kx-vision">
    <p>URLAI is <b>the token for spreading the Kurage ecosystem</b>. Beyond <a href="https://kurl2earn.exbridge.jp/kurl2earn.php?lang=en" target="_blank" rel="noopener">URL2Earn</a>, it is distributed to <b>kfreqai ambassadors</b>.</p>
    <ul>
      <li>Ambassadors trade crypto and FX with <b>kfreqai</b> and share their results.</li>
      <li>That <b>improves kfreqai's profitability and spreads awareness</b>, aiming at <b>a project that eventually earns for many people</b>.</li>
      <li>URLAI aims to be <b>part of the ecosystem's tokenomics</b>, distributed for that spreading and contribution.</li>
    </ul>
    <p class="kx-fine">* URLAI is a token of the <b>Kurage ecosystem</b> as a whole, not tied to any single product. See <a href="https://kurl2earn.exbridge.jp/tokenomics-vision.html" target="_blank" rel="noopener">🧭 Vision — why we share (JA)</a> / <a href="https://kurl2earn.exbridge.jp/tokenomics.html" target="_blank" rel="noopener">📐 Design — the tokenomics in numbers (JA)</a>. Its price and liquidity fluctuate with the market and no monetary value is guaranteed. Claiming is not investment advice.</p>
  </div>
</section>
<?php else: ?>
<section class="kx-sec" id="kurage-ecosystem">
  <h2 class="kx-h">Kurageシリーズ</h2>
  <p class="kx-sub">Kurage URL2GPTResearcher は、Kurageエコシステムのひとつです。</p>
  <div class="kx-grid">
    <a class="kx-card" href="https://kfreqai.exbridge.jp/" target="_blank" rel="noopener">
      <div class="kx-im"><img src="https://kfreqai.exbridge.jp/assets/ogp.png" alt="📈 Kurage FreqAI Trade" loading="lazy"></div>
      <div class="kx-tx"><div class="kx-nm">📈 Kurage FreqAI Trade</div>
        <div class="kx-ds">自分の負けを自分で研究する、自己改善型の暗号資産AI自動取引。全過程をブログで公開。</div>
        <div class="kx-lk">kfreqai.exbridge.jp →</div></div>
    </a>
    <a class="kx-card" href="https://kurage.exbridge.jp/kfreqaihl.php" target="_blank" rel="noopener">
      <div class="kx-im"><img src="https://kurage.exbridge.jp/images/kfreqaihl_ogp.png" alt="🌊 Kurage FreqAI for Hyperliquid" loading="lazy"></div>
      <div class="kx-tx"><div class="kx-nm">🌊 Kurage FreqAI for Hyperliquid</div>
        <div class="kx-ds">ウォレット1つ・サーバー不要のAI自動取引。CryptoからFX・金・株価指数まで。</div>
        <div class="kx-lk">kurage.exbridge.jp/kfreqaihl.php →</div></div>
    </a>
    <a class="kx-card" href="https://kfxai.exbridge.jp/" target="_blank" rel="noopener">
      <div class="kx-im"><img src="https://kfxai.exbridge.jp/assets/ogp.png" alt="💱 Kurage FX AI Trade" loading="lazy"></div>
      <div class="kx-tx"><div class="kx-nm">💱 Kurage FX AI Trade</div>
        <div class="kx-ds">OANDA APIのFX自動運用×差し替え可能なAI判断レイヤー。円ペアをペーパー取引で検証中。</div>
        <div class="kx-lk">kfxai.exbridge.jp →</div></div>
    </a>
    <a class="kx-card" href="https://llm2api.exbridge.jp/" target="_blank" rel="noopener">
      <div class="kx-im"><img src="https://llm2api.exbridge.jp/assets/ogp.png" alt="🤖 LLM2API" loading="lazy"></div>
      <div class="kx-tx"><div class="kx-nm">🤖 LLM2API</div>
        <div class="kx-ds">エージェントが1回ずつ買えるLLM推論。OpenAI互換をx402の従量課金で提供。</div>
        <div class="kx-lk">llm2api.exbridge.jp →</div></div>
    </a>
    <a class="kx-card" href="https://kurage.exbridge.jp/karchitect.php" target="_blank" rel="noopener">
      <div class="kx-im"><img src="https://kurage.exbridge.jp/images/karchitect-ogp.png" alt="🏗️ Kurage Architect" loading="lazy"></div>
      <div class="kx-tx"><div class="kx-nm">🏗️ Kurage Architect</div>
        <div class="kx-ds">AIと対話しながらシステム設計書を作る。要件定義・Mermaid構成図・PDF出力まで。</div>
        <div class="kx-lk">kurage.exbridge.jp/karchitect.php →</div></div>
    </a>
    <a class="kx-card" href="https://url2ai.exbridge.jp/" target="_blank" rel="noopener">
      <div class="kx-im"><img src="https://url2ai.exbridge.jp/assets/ogp.png" alt="📝 Kurage URL2AI Publisher" loading="lazy"></div>
      <div class="kx-tx"><div class="kx-nm">📝 Kurage URL2AI Publisher</div>
        <div class="kx-ds">URLを渡すとKurageさんが記事を読み、告知文とブログを書いて5媒体へ自動配信。</div>
        <div class="kx-lk">url2ai.exbridge.jp →</div></div>
    </a>
    <a class="kx-card" href="https://kmontage.exbridge.jp/" target="_blank" rel="noopener">
      <div class="kx-im"><img src="https://kmontage.exbridge.jp/assets/ogp.png" alt="🎬 kmontage" loading="lazy"></div>
      <div class="kx-tx"><div class="kx-nm">🎬 kmontage</div>
        <div class="kx-ds">台本から動画（モンタージュ）を自動生成する、Kurageの動画制作システム。</div>
        <div class="kx-lk">kmontage.exbridge.jp →</div></div>
    </a>
    <a class="kx-card" href="https://github.com/katsushi2441/kargov" target="_blank" rel="noopener">
      <div class="kx-im"><img src="https://kurl2earn.exbridge.jp/assets/cards/kargov.png" alt="🖱️ Kurage Argo Video（kargov）" loading="lazy"></div>
      <div class="kx-tx"><div class="kx-nm">🖱️ Kurage Argo Video（kargov）</div>
        <div class="kx-ds">AIがブラウザを操作した記録から、デモ・マニュアル動画を自動生成する制作パイプライン。</div>
        <div class="kx-lk">github.com/katsushi2441/kargov →</div></div>
    </a>
    <a class="kx-card" href="https://kurage.exbridge.jp/kgeo.php" target="_blank" rel="noopener">
      <div class="kx-im"><img src="https://kgeo.exbridge.jp/assets/ogp.png" alt="🔍 Kurage GEO" loading="lazy"></div>
      <div class="kx-tx"><div class="kx-nm">🔍 Kurage GEO</div>
        <div class="kx-ds">サイトがAI検索に理解・引用されやすいかを日本語で診断し、次に直す箇所を示す。</div>
        <div class="kx-lk">kurage.exbridge.jp/kgeo.php →</div></div>
    </a>
    <a class="kx-card" href="https://kurage.exbridge.jp/" target="_blank" rel="noopener">
      <div class="kx-im"><img src="https://kurage.exbridge.jp/images/kurage_ogp.png" alt="🪼 Kurage（総合ポータル）" loading="lazy"></div>
      <div class="kx-tx"><div class="kx-nm">🪼 Kurage（総合ポータル）</div>
        <div class="kx-ds">Kurageシリーズの入口。全プロダクトと紹介動画をまとめたポータル。</div>
        <div class="kx-lk">kurage.exbridge.jp →</div></div>
    </a>
  </div>
</section>

<section class="kx-sec" id="urlai">
  <h2 class="kx-h">URLAIはエコシステムのトークン</h2>
  <div class="kx-vision">
    <p>URLAIは、<b>Kurageエコシステムを広めるためのトークン</b>です。<a href="https://kurl2earn.exbridge.jp/kurl2earn.php" target="_blank" rel="noopener">URL2Earn</a>だけでなく、<b>kfreqaiのアンバサダー</b>にも配布されます。</p>
    <ul>
      <li>アンバサダーは <b>kfreqai</b> を使って暗号資産・FXをトレードし、その成果を発信します。</li>
      <li>それが kfreqai の<b>収益性を高め、認知を広め</b>、やがて<b>たくさんの人の収益につながるプロジェクト</b>を目指しています。</li>
      <li>URLAIは、その拡散と貢献に対して配られる、<b>トークノミクスにおけるエコシステムの一部</b>になることを目指しています。</li>
    </ul>
    <p class="kx-fine">※URLAIは <b>Kurageエコシステム</b>のトークンです。特定のプロダクトに限定されるものではありません。考え方と数字は <a href="https://kurl2earn.exbridge.jp/tokenomics-vision.html" target="_blank" rel="noopener">🧭 思想編 — なぜ分かち合うのか</a>／<a href="https://kurl2earn.exbridge.jp/tokenomics.html" target="_blank" rel="noopener">📐 設計編 — 数字で見るトークノミクス</a> にまとめています。価格や流動性は市場により変動し、金銭的価値を保証するものではありません。受け取りは投資助言ではありません。</p>
  </div>
</section>
<?php endif; ?>
<!-- END kurage-ecosystem -->

</main>

<footer class="site"><div class="wrap">
  Kurage URL2GPTResearcher — <a href="https://exbridge.jp/"><?php echo htmlspecialchars($T['footer_product'], ENT_QUOTES, 'UTF-8'); ?></a> ·
  <a href="https://kurl2gr.exbridge.jp/<?php echo $lang === 'en' ? '' : 'kurl2gr.html'; ?>" target="_blank" rel="noopener"><?php echo htmlspecialchars($T['nav_about'], ENT_QUOTES, 'UTF-8'); ?></a> ·
  <a href="https://exbridge.jp/contact.php"><?php echo htmlspecialchars($T['footer_contact'], ENT_QUOTES, 'UTF-8'); ?></a>
  <br><br>&copy; <?php echo date('Y'); ?> EXBRIDGE, Inc.
</div></footer>
</body></html><?php
exit;
endif;

// ログイン後は管理画面(static/index.html)を返す。API呼び出しは ?api= に畳む。
$html_path = file_exists(__DIR__ . '/kurl2gr_app.html')
    ? __DIR__ . '/kurl2gr_app.html'
    : __DIR__ . '/../static/index.html';
$html = file_get_contents($html_path);
$html = str_replace('href="/static/styles.css"', 'href="?asset=styles.css"', $html);
$html = str_replace('src="/static/app.js"', 'src="?asset=app.js"', $html);
if ($lang === 'en') { $html = preg_replace('/<html lang="ja">/', '<html lang="en">', $html, 1); }
$bootstrap = '<script>'
    . 'window.KURL2GR_API_PREFIX="?api=";'
    . 'window.KURL2GR_CSRF=' . json_encode($csrf) . ';'
    . 'window.KURL2GR_LANG=' . json_encode($lang) . ';'
    . 'window.KURL2GR_USER=' . json_encode($session_user) . ';'
    . 'window.KURL2GR_IS_ADMIN=' . ($is_admin ? 'true' : 'false') . ';'
    . 'window.KURL2GR_ACT_AS=' . json_encode($act_as) . ';'
    . 'window.KURL2GR_PUBLIC_BASE=' . json_encode(KURL2GR_PUBLIC_BASE) . ';'
    . '</script>'
    . '<script async src="https://www.googletagmanager.com/gtag/js?id=G-BP0650KDFR"></script>'
    . '<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments)}gtag("js",new Date());gtag("config","G-BP0650KDFR");</script>'
    . '<script>(function(){var s=document.createElement("script");s.src="https://aiknowledgecms.exbridge.jp/simpletrack.php?url="+encodeURIComponent(location.href)+"&ref="+encodeURIComponent(document.referrer);document.head.appendChild(s)})();</script>';
$html = str_replace('</head>', $bootstrap . '</head>', $html);
echo $html;
