<?php
session_start();
date_default_timezone_set("Asia/Tokyo");
// Kurage URL2GPTResearcher 版。
// 顧客サイトに置く表示専用のSNS。投稿の実体は kurl2gr が持ち、
// site_key で自分の投稿だけを読む。顧客サーバーにDBもバックエンドも置かない。
require_once __DIR__ . '/sns_config.php';
$API_PROXY = KURL2GR_API_BASE;      // 例: https://exbridge.ddns.net:18358
$SITE_KEY  = KURL2GR_SITE_KEY;
$BASE_HOST = 'https://aixec.exbridge.jp';
$THIS_FILE = 'sns.php';
$ADMIN     = 'xb_bittensor';

$x_keys = array();
$x_keys_file = __DIR__ . '/x_api_keys.sh';
if (file_exists($x_keys_file)) {
    foreach (file($x_keys_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (preg_match('/(?:export\s+)?(\w+)=["\']?([^"\'#\r\n]*)["\']?/', $line, $m))
            $x_keys[trim($m[1])] = trim($m[2]);
    }
}
$x_client_id     = $x_keys['X_API_KEY']    ?? '';
$x_client_secret = $x_keys['X_API_SECRET'] ?? '';
$x_redirect_uri  = $BASE_HOST . '/' . $THIS_FILE;

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function x($s) { return htmlspecialchars((string)$s, ENT_XML1 | ENT_COMPAT, 'UTF-8'); }
function plain_text_from_content($s) {
    $text = html_entity_decode(strip_tags((string)$s), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim(preg_replace('/[ \t]+/u', ' ', $text));
}
function post_title_from_content($content) {
    $plain = plain_text_from_content($content);
    if (preg_match('/\n\n(.+?)\s+の特徴/u', $plain, $m)) {
        $title = trim($m[1]);
        return mb_strlen($title, 'UTF-8') > 100 ? mb_substr($title, 0, 100, 'UTF-8') . '…' : $title;
    }
    $first_line = trim(strtok($plain, "\n"));
    if ($first_line === '') $first_line = 'AIxSNSニュース';
    return mb_strlen($first_line, 'UTF-8') > 70 ? mb_substr($first_line, 0, 70, 'UTF-8') . '…' : $first_line;
}
function post_desc_from_content($content) {
    $plain = plain_text_from_content($content);
    return mb_strlen($plain, 'UTF-8') > 150 ? mb_substr($plain, 0, 150, 'UTF-8') . '…' : $plain;
}
function post_title($post) {
    $title = trim((string)($post['title'] ?? ''));
    return $title !== '' ? $title : post_title_from_content($post['content'] ?? '');
}
function post_description($post) {
    $desc = trim((string)($post['description'] ?? ''));
    return $desc !== '' ? $desc : post_desc_from_content($post['content'] ?? '');
}
function post_path($post) {
    $slug = trim((string)($post['slug'] ?? ''));
    if ($slug !== '') return '/sns.php?slug=' . rawurlencode($slug);
    return '/sns.php?id=' . (int)($post['id'] ?? 0);
}
function post_full_url($post) {
    global $BASE_HOST;
    return $BASE_HOST . post_path($post);
}
function author_profile_url($author) {
    return 'sns.php?author=' . rawurlencode((string)$author);
}
function post_category_from_author($author) {
    $map = array(
        'aixtubeg' => 'AIxTube',
        'oss' => 'OSS',
        'osszenn' => 'OSS/Zenn',
        'ainews' => 'AI News',
        'aitech' => 'AI Tech',
        'finreport' => 'Financial Report',
        'polymarket' => 'Polymarket',
        'codex' => 'VWork / Codex',
        'claude' => 'VWork / Claude',
        'register' => 'Product',
        'kurage' => 'Kurage / Horizon',
        'kgrowth' => 'Growth / SEO'
    );
    $key = strtolower((string)$author);
    return isset($map[$key]) ? $map[$key] : 'AIxSNS';
}
function render_post_text_html($content) {
    $parts = preg_split('#(https?://[^\s<>"\']+)#u', (string)$content, -1, PREG_SPLIT_DELIM_CAPTURE);
    $out = '';
    foreach ($parts as $i => $part) {
        if ($i % 2 === 0) {
            $out .= h($part);
        } else {
            $url = rtrim($part, "。、，．,.)]}\n\r\t");
            $suffix = h(substr($part, strlen($url)));
            $out .= '<a href="' . h($url) . '" target="_blank" rel="noopener nofollow">' . h($url) . '</a>' . $suffix;
        }
    }
    return nl2br($out);
}
function aff_base64url($d) { return rtrim(strtr(base64_encode($d), '+/', '-_'), '='); }
function aff_gen_verifier() { $b=''; for($i=0;$i<32;$i++) $b.=chr(mt_rand(0,255)); return aff_base64url($b); }
function aff_gen_challenge($v) { return aff_base64url(hash('sha256', $v, true)); }
function aff_x_post($url, $data, $headers) {
    $opts = array('http'=>array('method'=>'POST','header'=>implode("\r\n",$headers)."\r\n",'content'=>$data,'timeout'=>12,'ignore_errors'=>true));
    return json_decode(@file_get_contents($url, false, stream_context_create($opts)) ?: '{}', true);
}
function aff_x_get($url, $token) {
    $opts = array('http'=>array('method'=>'GET','header'=>"Authorization: Bearer $token\r\nUser-Agent: AffiliateManager/1.0\r\n",'timeout'=>12,'ignore_errors'=>true));
    return json_decode(@file_get_contents($url, false, stream_context_create($opts)) ?: '{}', true);
}
function api_call($path, $params = array()) {
    global $API_PROXY, $SITE_KEY;
    $path = ltrim($path, '/');
    // kurl2gr は site_key で自分の投稿だけを返す。他社の記事は混ざらない。
    if (strpos($path, 'posts') !== 0) { return array(); }   // 商品等は扱わない
    $p = array_merge(array('key' => $SITE_KEY), $params);
    unset($p['exclude_author']);
    $url = rtrim($API_PROXY, '/') . '/public/posts?' . http_build_query($p);
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $body = curl_exec($ch);
    curl_close($ch);
    $d = json_decode($body, true);
    return is_array($d) ? $d : array();
}
function api_post($path, $payload) {
    global $API_PROXY, $SITE_KEY;
    $path = ltrim($path, '/');
    // 表示専用。投稿・編集・削除は kurl2gr の管理画面だけで行う
    // (顧客サイト側に投稿権限を置かないため)。
    if ($path !== 'posts/view') {
        return array('ok' => false, 'error' => 'read_only');
    }
    $id = isset($payload['id']) ? (int)$payload['id'] : 0;
    $url = rtrim($API_PROXY, '/') . '/public/posts/' . $id . '/view?key=' . urlencode($SITE_KEY);
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
    $resp = curl_exec($ch);
    curl_close($ch);
    $d = json_decode($resp, true);
    return is_array($d) ? $d : array();
}
function first_url_from_text($text) {
    if (preg_match('#https?://[^\s<>"\']+#u', (string)$text, $m)) {
        return rtrim($m[0], "。、，．,.)]}\n\r\t");
    }
    return '';
}
function preferred_ogp_url_from_text($text) {
    if (!preg_match_all('#https?://[^\s<>"\']+#u', (string)$text, $matches)) return '';
    $urls = array_map(function($url) {
        return rtrim($url, "。、，．,.)]}\n\r\t");
    }, $matches[0]);
    foreach ($urls as $url) {
        $host = strtolower((string)parse_url($url, PHP_URL_HOST));
        if (preg_match('/(^|\.)amazon\.(co\.jp|com)$/i', $host)) return $url;
    }
    return $urls[0] ?? '';
}
function absolute_url_for_ogp($url, $base) {
    $url = html_entity_decode(trim((string)$url), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if ($url === '') return '';
    if (preg_match('#^https?://#i', $url)) return $url;
    if (strpos($url, '//') === 0) return 'https:' . $url;
    $parts = parse_url($base);
    if (empty($parts['scheme']) || empty($parts['host'])) return '';
    $root = $parts['scheme'] . '://' . $parts['host'];
    if (strpos($url, '/') === 0) return $root . $url;
    $path = isset($parts['path']) ? preg_replace('#/[^/]*$#', '/', $parts['path']) : '/';
    return $root . $path . $url;
}
function fetch_ogp_card($url) {
    $url = trim((string)$url);
    if (!preg_match('#^https?://#i', $url)) return array('ok' => false, 'error' => 'invalid url');
    $requested_host = strtolower((string)parse_url($url, PHP_URL_HOST));
    $is_amazon = (bool)preg_match('/(^|\.)amazon\.(co\.jp|com)$/i', $requested_host);
    $cache_dir = __DIR__ . '/data/ogp_cache';
    if (!is_dir($cache_dir)) @mkdir($cache_dir, 0755, true);
    $cache_file = $cache_dir . '/' . sha1($url) . '.json';
    if (is_readable($cache_file) && filemtime($cache_file) > time() - 86400) {
        $cached = json_decode(file_get_contents($cache_file), true);
        // Amazonはog:imageを返さない場合がある。画像なしの旧キャッシュは再取得する。
        if (is_array($cached) && (!$is_amazon || !empty($cached['image']))) return $cached;
    }
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 4);
    curl_setopt($ch, CURLOPT_TIMEOUT, 8);
    curl_setopt($ch, CURLOPT_USERAGENT, 'AIxEC OGP Preview/1.0');
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Accept: text/html,application/xhtml+xml'));
    $html = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $final_url = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    $ctype = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    if (!$html || $status >= 400 || ($ctype && stripos($ctype, 'text/html') === false && stripos($ctype, 'application/xhtml') === false)) {
        return array('ok' => false, 'error' => 'fetch failed');
    }
    if (strlen($html) > 500000) $html = substr($html, 0, 500000);
    $meta = array();
    if (preg_match_all('/<meta\b[^>]+>/i', $html, $tags)) {
        foreach ($tags[0] as $tag) {
            $key = '';
            $val = '';
            if (preg_match('/\b(?:property|name)=["\']([^"\']+)["\']/i', $tag, $km)) $key = strtolower($km[1]);
            if (preg_match('/\bcontent=["\']([^"\']*)["\']/i', $tag, $vm)) $val = html_entity_decode($vm[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($key !== '' && $val !== '') $meta[$key] = $val;
        }
    }
    $title = isset($meta['og:title']) ? $meta['og:title'] : '';
    if ($title === '' && preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $tm)) {
        $title = html_entity_decode(trim(strip_tags($tm[1])), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    $desc = isset($meta['og:description']) ? $meta['og:description'] : (isset($meta['description']) ? $meta['description'] : '');
    $image = isset($meta['og:image']) ? absolute_url_for_ogp($meta['og:image'], $final_url ?: $url) : '';
    $host = parse_url($final_url ?: $url, PHP_URL_HOST);
    if ($image === '' && $is_amazon
        && preg_match('/\bdata-old-hires=["\'](https:\/\/m\.media-amazon\.com\/images\/I\/[^"\']+)["\']/i', $html, $im)) {
        $image = html_entity_decode($im[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    $card = array(
        'ok' => true,
        'url' => $final_url ?: $url,
        'host' => $host ?: '',
        'title' => mb_substr(trim($title), 0, 140, 'UTF-8'),
        'description' => mb_substr(trim($desc), 0, 220, 'UTF-8'),
        'image' => $image,
    );
    if (is_dir($cache_dir) && is_writable($cache_dir)) {
        @file_put_contents($cache_file, json_encode($card, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }
    return $card;
}

function render_ogp_card_html($card) {
    if (!is_array($card) || empty($card['ok']) || (empty($card['title']) && empty($card['image']))) return '';
    $has_image = !empty($card['image']);
    $class = $has_image ? 'ogp-card' : 'ogp-card no-image';
    $html = '<a class="' . $class . '" href="' . h($card['url'] ?? '') . '" target="_blank" rel="noopener nofollow">';
    if ($has_image) {
        $html .= '<img class="ogp-image" src="' . h($card['image']) . '" alt="' . h($card['title'] ?? '') . '" loading="lazy">';
    }
    $html .= '<div class="ogp-body">';
    $html .= '<div class="ogp-host">' . h($card['host'] ?? '') . '</div>';
    $html .= '<div class="ogp-title">' . h($card['title'] ?? ($card['url'] ?? '')) . '</div>';
    if (!empty($card['description'])) $html .= '<div class="ogp-desc">' . h($card['description']) . '</div>';
    $html .= '</div></a>';
    return $html;
}

function product_page_url($item) {
    $id = isset($item['id']) ? (int)$item['id'] : 0;
    return $id > 0 ? '/product.php?id=' . $id : '/';
}
function product_click_url($item, $to) {
    $params = array(
        'to' => $to,
        'kw' => trim((string)($item['name'] ?? 'AIxEC')),
        'from' => isset($_GET['id']) ? ('sns:' . (int)$_GET['id']) : 'sns',
    );
    foreach (array('id' => 'pid', 'model_number' => 'model', 'jan' => 'jan', 'asin' => 'asin') as $src => $dst) {
        if (!empty($item[$src])) $params[$dst] = (string)$item[$src];
    }
    if ($to === 'rakuten' && !empty($item['rakuten_url'])) $params['url'] = (string)$item['rakuten_url'];
    return '/go.php?' . http_build_query($params);
}
function product_short_desc($item) {
    $desc = plain_text_from_content($item['market_description_ai'] ?? ($item['description'] ?? ''));
    $desc = preg_replace('/楽天市場でこの商品を見る\s*→?\s*楽天市場の商品ページで価格・在庫・レビューをご確認ください。?/u', '', $desc);
    if (!empty($item['name'])) {
        $name = preg_quote((string)$item['name'], '/');
        $desc = preg_replace('/^' . $name . '\s*/u', '', $desc);
    }
    $desc = preg_replace('/^(AIによる商品説明|ジャンル:\s*[^。]+|ショップ:\s*[^。]+)\s*/u', '', trim($desc));
    $desc = preg_replace('/^[。・\s]+/u', '', trim($desc));
    if ($desc === '') return '';
    return mb_strlen($desc, 'UTF-8') > 90 ? mb_substr($desc, 0, 90, 'UTF-8') . '…' : $desc;
}
function related_product_keywords($content) {
    $plain = plain_text_from_content(preg_replace('#https?://\S+#u', ' ', (string)$content));
    $genres = array(
        array('q' => '人気書籍',          'match' => array('書籍', '本', '読書', '出版')),
        array('q' => 'AI',                'match' => array('AI', '人工知能', '機械学習', 'LLM', 'ChatGPT', 'Claude', 'Gemini', 'OpenAI')),
        array('q' => 'プログラミング IT', 'match' => array('プログラミング', 'コーディング', 'Python', 'JavaScript', 'PHP', 'バイブコーディング', 'IT')),
        array('q' => '健康 医療',         'match' => array('健康', '医療', '病院', 'ダイエット', '筋トレ')),
        array('q' => 'Web3',              'match' => array('Web3', 'web3')),
        array('q' => '暗号資産 暗号通貨', 'match' => array('暗号資産', '暗号通貨', '仮想通貨', 'ビットコイン')),
        array('q' => 'ブロックチェーン',  'match' => array('ブロックチェーン', 'blockchain')),
        array('q' => 'NFT',               'match' => array('NFT')),
        array('q' => 'DeFi',              'match' => array('DeFi', 'defi')),
        array('q' => '副業',              'match' => array('副業', '在宅', 'フリーランス')),
        array('q' => '起業 個人事業',     'match' => array('起業', '個人事業', '経営', '会社設立')),
        array('q' => '確定申告',          'match' => array('確定申告', '税金', '節税')),
        array('q' => '投資 新NISA NISA',  'match' => array('投資', 'NISA', '新NISA', '資産運用')),
        array('q' => '株式投資 株',       'match' => array('株式', '株', '株価')),
        array('q' => '工具 DIY',          'match' => array('工具', 'DIY', '電動ドリル')),
        array('q' => '機械',              'match' => array('機械', '工業', '製造')),
        array('q' => 'トレカ',            'match' => array('トレカ', 'トレーディングカード', 'ポケカ')),
        array('q' => '美容 コスメ',       'match' => array('美容', 'コスメ', '化粧', 'スキンケア')),
        array('q' => 'サプリ',            'match' => array('サプリ', 'プロテイン', 'ビタミン')),
        array('q' => 'AI PC ゲーミング',  'match' => array('AI PC', 'ゲーミング', 'ゲーム')),
        array('q' => 'ゲーミングPC RTX',  'match' => array('ゲーミングPC', 'GeForce', 'NVIDIA')),
        array('q' => 'RTX ゲーミングPC',  'match' => array('RTX', 'GPU')),
        array('q' => 'ミニPC 32GB',       'match' => array('ミニPC', 'NUC', 'コンパクトPC')),
        array('q' => '4K モニター',       'match' => array('4K', 'モニター', 'ディスプレイ')),
        array('q' => 'メカニカルキーボード', 'match' => array('メカニカルキーボード', 'キーボード')),
        array('q' => 'USB マイク 配信',   'match' => array('マイク', '配信', '録音')),
        array('q' => 'Webカメラ 4K',      'match' => array('Webカメラ', 'WEBカメラ', 'ウェブカメラ')),
        array('q' => '外付けSSD 2TB',     'match' => array('外付けSSD', 'SSD', 'NVMe', 'ストレージ')),
        array('q' => 'キャプチャーボード', 'match' => array('キャプチャー', 'キャプチャーボード')),
    );
    $queries = array();
    foreach ($genres as $genre) {
        foreach ($genre['match'] as $word) {
            if (mb_stripos($plain, $word, 0, 'UTF-8') !== false) {
                $queries[] = $genre['q'];
                break;
            }
        }
    }
    return array_values(array_unique($queries));
}
function related_products_for_post($content, $limit = 6) {
    $items = array();
    $seen = array();
    $ids = array();
    if (preg_match_all('#aixtube\.php\?v=(\d+)#u', (string)$content, $m)) {
        foreach ($m[1] as $id) $ids[] = (int)$id;
    }
    if (preg_match_all('#product(?:\.php\?id=|/)(\d+)#u', (string)$content, $m)) {
        foreach ($m[1] as $id) $ids[] = (int)$id;
    }
    $ids = array_values(array_unique(array_filter($ids)));
    if ($ids) {
        $res = api_call('/products', array('ids' => implode(',', $ids), 'limit' => $limit));
        foreach (($res['items'] ?? array()) as $item) {
            $id = (int)($item['id'] ?? 0);
            if ($id > 0 && !isset($seen[$id])) {
                $seen[$id] = true;
                $items[] = $item;
            }
        }
    }
    foreach (related_product_keywords($content) as $kw) {
        if (count($items) >= $limit) break;
        $res = api_call('/products', array('q' => $kw, 'limit' => 8));
        foreach (($res['items'] ?? array()) as $item) {
            $id = (int)($item['id'] ?? 0);
            if ($id <= 0 || isset($seen[$id])) continue;
            $seen[$id] = true;
            $items[] = $item;
            if (count($items) >= $limit) break;
        }
    }
    return $items;
}

/* RSS feed */
if (isset($_GET['feed'])) {
    header('Content-Type: application/rss+xml; charset=UTF-8');
    header('Access-Control-Allow-Origin: https://exbridge.jp');
    header('Vary: Origin');
    $res = api_call('/posts', array('limit' => 30, 'offset' => 0, 'exclude_author' => 'AIxTubeG'));
    $items = isset($res['items']) && is_array($res['items']) ? $res['items'] : array();
    $feed_title = 'AIxSNSニュース | AIxEC';
    $feed_desc  = 'AIxSNSニュース — AI・Web3・SNS・テック最新情報をリアルタイムで配信。AIxECが厳選するビジネスに役立つニュースフィード。';
    $feed_url   = $BASE_HOST . '/sns.php?feed';
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<rss version="2.0">' . "\n";
    echo '<channel>' . "\n";
    echo '<title>' . x($feed_title) . '</title>' . "\n";
    echo '<link>' . x($BASE_HOST . '/sns.php') . '</link>' . "\n";
    echo '<description>' . x($feed_desc) . '</description>' . "\n";
    echo '<language>ja</language>' . "\n";
    echo '<lastBuildDate>' . date(DATE_RSS) . '</lastBuildDate>' . "\n";
    echo '<atom:link xmlns:atom="http://www.w3.org/2005/Atom" href="' . x($feed_url) . '" rel="self" type="application/rss+xml" />' . "\n";
    foreach ($items as $item) {
        $id = isset($item['id']) ? (int)$item['id'] : 0;
        $content = isset($item['content']) ? (string)$item['content'] : '';
        if ($id <= 0 || trim($content) === '') continue;
        $plain = plain_text_from_content($content);
        $first_line = trim(strtok($plain, "\n"));
        $title = $first_line !== '' ? $first_line : 'AIxSNSニュース | AIxEC';
        if (mb_strlen($title, 'UTF-8') > 80) $title = mb_substr($title, 0, 80, 'UTF-8') . '…';
        $link = $BASE_HOST . '/sns.php?id=' . $id;
        $created = isset($item['created_at']) ? strtotime($item['created_at']) : false;
        echo '<item>' . "\n";
        echo '<title>' . x($title) . '</title>' . "\n";
        echo '<link>' . x($link) . '</link>' . "\n";
        echo '<guid isPermaLink="true">' . x($link) . '</guid>' . "\n";
        if ($created) echo '<pubDate>' . date(DATE_RSS, $created) . '</pubDate>' . "\n";
        echo '<description>' . x($content) . '</description>' . "\n";
        echo '</item>' . "\n";
    }
    echo '</channel>' . "\n";
    echo '</rss>' . "\n";
    exit;
}

/* X OAuth */
if (isset($_GET['aff_logout'])) { session_destroy(); header('Location: '.$x_redirect_uri); exit; }
if (isset($_GET['aff_login'])) {
    $ver = aff_gen_verifier(); $chal = aff_gen_challenge($ver); $state = md5(uniqid('',true));
    $_SESSION['aff_code_verifier'] = $ver; $_SESSION['aff_oauth_state'] = $state;
    $p = array('response_type'=>'code','client_id'=>$x_client_id,'redirect_uri'=>$x_redirect_uri,
               'scope'=>'tweet.read users.read','state'=>$state,'code_challenge'=>$chal,'code_challenge_method'=>'S256');
    header('Location: https://twitter.com/i/oauth2/authorize?'.http_build_query($p)); exit;
}
if (isset($_GET['code'],$_GET['state'],$_SESSION['aff_oauth_state']) && $_GET['state']===$_SESSION['aff_oauth_state']) {
    $post = http_build_query(array('grant_type'=>'authorization_code','code'=>$_GET['code'],
        'redirect_uri'=>$x_redirect_uri,'code_verifier'=>$_SESSION['aff_code_verifier'],'client_id'=>$x_client_id));
    $cred = base64_encode($x_client_id.':'.$x_client_secret);
    $data = aff_x_post('https://api.twitter.com/2/oauth2/token',$post,array('Content-Type: application/x-www-form-urlencoded','Authorization: Basic '.$cred));
    if (isset($data['access_token'])) {
        $_SESSION['aff_access_token'] = $data['access_token'];
        unset($_SESSION['aff_oauth_state'],$_SESSION['aff_code_verifier']);
        $me = aff_x_get('https://api.twitter.com/2/users/me',$data['access_token']);
        if (isset($me['data']['username'])) $_SESSION['aff_username'] = $me['data']['username'];
    }
    header('Location: '.$x_redirect_uri); exit;
}

$session_user = $_SESSION['aff_username'] ?? '';
$is_admin     = ($session_user === $ADMIN);

/* AJAX: 投稿一覧 */
if (isset($_GET['api_posts'])) {
    header('Content-Type: application/json; charset=utf-8');
    $limit  = min(50, max(1, (int)($_GET['limit'] ?? 20)));
    $offset = max(0, (int)($_GET['offset'] ?? 0));
    $author = isset($_GET['author']) ? trim((string)$_GET['author']) : '';
    $params = array('limit' => $limit, 'offset' => $offset);
    if ($author !== '' && preg_match('/^[A-Za-z0-9_\-]{1,32}$/', $author)) {
        $params['author'] = $author;
    } else {
        $params['exclude_author'] = 'AIxTubeG';
    }
    $res = api_call('/posts', $params);
    echo json_encode(array('items' => $res['items'] ?? array(), 'total' => $res['total'] ?? 0), JSON_UNESCAPED_UNICODE);
    exit;
}

/* AJAX: URL OGP preview */
if (isset($_GET['api_ogp'])) {
    header('Content-Type: application/json; charset=utf-8');
    $url = trim((string)$_GET['api_ogp']);
    echo json_encode(fetch_ogp_card($url), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/* AJAX: 投稿作成（管理者のみ） */
if (isset($_GET['post_create']) && $is_admin) {
    header('Content-Type: application/json; charset=utf-8');
    $data    = json_decode(file_get_contents('php://input'), true);
    $content = trim($data['content'] ?? '');
    if (!$content) { echo json_encode(array('status'=>'error','error'=>'内容が空です')); exit; }
    $author = preg_match('/^[A-Za-z0-9_\-]{1,32}$/', $session_user) ? $session_user : 'xb_bittensor';
    $reg = api_post('/posts', array('author' => $author, 'content' => $content));
    if (empty($reg['ok'])) { echo json_encode(array('status'=>'error','error'=>$reg['error']??'投稿失敗')); exit; }
    echo json_encode(array('status'=>'ok','item'=>$reg['item']), JSON_UNESCAPED_UNICODE);
    exit;
}

/* AJAX: 投稿更新（管理者のみ） */
if (isset($_GET['post_update']) && $is_admin) {
    header('Content-Type: application/json; charset=utf-8');
    $data    = json_decode(file_get_contents('php://input'), true);
    $id      = (int)($data['id'] ?? $_GET['post_update']);
    $content = trim($data['content'] ?? '');
    if (!$id) { echo json_encode(array('status'=>'error','error'=>'IDが不正')); exit; }
    if (!$content) { echo json_encode(array('status'=>'error','error'=>'内容が空です')); exit; }
    $author = preg_match('/^[A-Za-z0-9_\-]{1,32}$/', $session_user) ? $session_user : 'xb_bittensor';
    $reg = api_post('/posts/update', array('id' => $id, 'author' => $author, 'content' => $content));
    if (empty($reg['ok'])) { echo json_encode(array('status'=>'error','error'=>$reg['error']??'更新失敗')); exit; }
    echo json_encode(array('status'=>'ok','item'=>$reg['item']), JSON_UNESCAPED_UNICODE);
    exit;
}

/* AJAX: 投稿削除（管理者のみ） */
if (isset($_GET['post_delete']) && $is_admin) {
    header('Content-Type: application/json; charset=utf-8');
    $id = (int)$_GET['post_delete'];
    if (!$id) { echo json_encode(array('status'=>'error','error'=>'IDが不正')); exit; }
    $res = api_post('/posts/delete', array('id' => $id));
    echo json_encode(array('status' => !empty($res['ok']) ? 'ok' : 'error'), JSON_UNESCAPED_UNICODE);
    exit;
}

/* AJAX: 表示回数 */
if (isset($_GET['post_view'])) {
    header('Content-Type: application/json; charset=utf-8');
    $id = (int)$_GET['post_view'];
    if (!$id) { echo json_encode(array('status'=>'error','error'=>'IDが不正')); exit; }
    $res = api_post('/posts/view', array('id' => $id));
    echo json_encode(array(
        'status' => !empty($res['ok']) ? 'ok' : 'error',
        'views' => isset($res['item']['views']) ? (int)$res['item']['views'] : null,
    ), JSON_UNESCAPED_UNICODE);
    exit;
}

/* 単体投稿ページ */
$post_id     = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$post_slug   = isset($_GET['slug']) ? trim((string)$_GET['slug']) : '';
$detail_post = null;
if ($post_slug !== '') {
    $res = api_call('/posts/slug/' . $post_slug);
    $detail_post = (!empty($res['ok']) && !empty($res['item'])) ? $res['item'] : null;
    if ($detail_post && !empty($detail_post['id'])) $post_id = (int)$detail_post['id'];
} elseif ($post_id > 0) {
    $res = api_call('/posts/' . $post_id);
    $detail_post = (!empty($res['ok']) && !empty($res['item'])) ? $res['item'] : null;
}
$initial_posts = array();
if ($detail_post) {
    $initial_posts_res = api_call('/posts', array('limit' => 5, 'offset' => 0, 'exclude_author' => 'AIxTubeG'));
    $initial_posts = isset($initial_posts_res['items']) && is_array($initial_posts_res['items']) ? $initial_posts_res['items'] : array();
}

/* OGP */
if ($detail_post) {
    $plain_content = plain_text_from_content($detail_post['content']);
    $page_title = post_title($detail_post) . ' | AIxEC';
    $page_desc  = post_description($detail_post);
    $page_url   = post_full_url($detail_post);
    $page_image = $BASE_HOST . '/images/sns.png';
} else {
    $page_title = 'AIxSNSニュース | AI・SNS・Web3・テック最新情報 — AIxEC';
    $page_desc  = 'AIxSNSニュース — AI・SNS・Web3・テクノロジーの最新情報をリアルタイムで配信。ビジネスに役立つAIニュース・SNSトレンド・テック情報をタイムライン形式でお届けします。';
    $page_url   = $BASE_HOST . '/sns.php';
    $page_image = $BASE_HOST . '/images/sns.png';
}
?><!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php echo h($page_title); ?></title>
<meta name="description" content="<?php echo h($page_desc); ?>">
<link rel="canonical" href="<?php echo h($page_url); ?>">
<?php if ($detail_post && (($detail_post['author'] ?? '') === 'register')): ?>
<meta name="robots" content="noindex,follow">
<?php endif; ?>
<meta name="keywords" content="AIxSNSニュース,SNSニュース,AIニュース,AI最新情報,Web3,テクノロジー,SNSトレンド,AIxEC,ビジネスAI,AI活用">
<link rel="alternate" type="application/rss+xml" title="AIxSNSニュース RSS" href="<?php echo h($BASE_HOST . '/sns.php?feed'); ?>">
<meta property="og:type"        content="<?php echo $detail_post ? 'article' : 'website'; ?>">
<meta property="og:title"       content="<?php echo h($page_title); ?>">
<meta property="og:description" content="<?php echo h($page_desc); ?>">
<meta property="og:url"         content="<?php echo h($page_url); ?>">
<meta property="og:image"       content="<?php echo h($page_image); ?>">
<meta property="og:site_name"   content="AIxEC">
<meta property="og:locale"      content="ja_JP">
<meta name="twitter:card"        content="summary_large_image">
<meta name="twitter:title"       content="<?php echo h($page_title); ?>">
<meta name="twitter:description" content="<?php echo h($page_desc); ?>">
<meta name="twitter:image"       content="<?php echo h($page_image); ?>">
<meta name="twitter:site"        content="@xb_bittensor">
<?php if ($detail_post): ?>
<script type="application/ld+json">
<?php echo json_encode(array(
    '@context'      => 'https://schema.org',
    '@type'         => 'Article',
    'headline'      => post_title($detail_post),
    'description'   => post_description($detail_post),
    'text'          => $detail_post['content'],
    'datePublished' => $detail_post['created_at'],
    'dateModified'  => $detail_post['updated_at'] ?? $detail_post['created_at'],
    'articleSection'=> post_category_from_author($detail_post['author'] ?? ''),
    'image'         => $page_image,
    'author'        => array('@type' => 'Person', 'name' => $detail_post['author'] ?? 'xb_bittensor', 'url' => $BASE_HOST . '/' . author_profile_url($detail_post['author'] ?? 'xb_bittensor')),
    'publisher'     => array('@type' => 'Organization', 'name' => 'AIxEC', 'url' => $BASE_HOST),
    'url'           => $page_url,
), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT); ?>
</script>
<?php else: ?>
<script type="application/ld+json">
<?php echo json_encode(array(
    '@context' => 'https://schema.org',
    '@type' => 'WebSite',
    'name' => 'AIxSNSニュース',
    'description' => $page_desc,
    'url' => $BASE_HOST . '/sns.php',
    'publisher' => array('@type' => 'Organization', 'name' => 'AIxEC', 'url' => $BASE_HOST),
), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT); ?>
</script>
<?php endif; ?>
<style>
:root{--accent:#55c500;--accent-dark:#3d9400;--ink:#0f172a;--muted:#64748b;--line:#e2e8f0;--bg:#f1f5f9;--card:#fff;--red:#bf0000}
*{box-sizing:border-box;margin:0;padding:0}
body{background:var(--bg);color:var(--ink);font-family:-apple-system,'Hiragino Sans',sans-serif;min-height:100vh}
a{color:inherit;text-decoration:none}

.header{background:#fff;border-bottom:1px solid var(--line);padding:12px 20px;position:sticky;top:0;z-index:200;display:flex;align-items:center;gap:10px}
.header h1{font-size:17px;font-weight:700}
.brand-link{display:flex;align-items:center;gap:10px;color:inherit;text-decoration:none}
.brand-link:hover h1{text-decoration:underline}
.badge{background:var(--accent);color:#fff;font-size:11px;padding:2px 8px;border-radius:10px;text-decoration:none}
a.badge:hover{background:var(--accent-dark)}
.userbar{display:flex;align-items:center;gap:.6rem;font-size:.8rem;margin-left:auto}
.userbar strong{color:#059669}
.btn-sm{border:1px solid var(--line);padding:3px 10px;border-radius:4px;color:var(--muted);text-decoration:none;font-size:.75rem}
.btn-sm:hover{border-color:#dc2626;color:#dc2626}
.btn-login-sm{border:1px solid var(--accent);padding:4px 12px;border-radius:4px;color:var(--accent-dark);text-decoration:none;font-size:.75rem}
.btn-login-sm:hover{background:#f0fce8}

.compose-wrap{max-width:640px;margin:12px auto 0;padding:0 16px}
.compose{background:#fff;border:1px solid var(--line);border-radius:12px;padding:16px}
.compose-header{display:flex;gap:12px;align-items:flex-start}
.compose-avatar{width:42px;height:42px;border-radius:50%;background:var(--accent);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:12px;color:#fff;flex-shrink:0}
.compose textarea{width:100%;border:none;outline:none;font-size:15px;line-height:1.75;resize:none;min-height:100px;font-family:inherit;color:var(--ink)}
.compose textarea::placeholder{color:#aaa}
.compose-footer{display:flex;align-items:center;gap:8px;margin-top:10px;padding-top:10px;border-top:1px solid var(--line)}
.compose-count{font-size:12px;color:var(--muted)}
.compose-status{font-size:12px;padding:3px 8px;border-radius:4px;display:none}
.compose-status.ok{background:#dcfce7;color:#166534;display:inline-block}
.compose-status.err{background:#fee2e2;color:#991b1b;display:inline-block}
.btn-post{margin-left:auto;background:var(--ink);color:#fff;border:none;border-radius:20px;padding:7px 22px;font-size:14px;font-weight:700;cursor:pointer}
.btn-post:hover{background:#334155}
.btn-post:disabled{background:#94a3b8;cursor:not-allowed}

.page-shell{max-width:880px;margin:12px auto 80px;padding:0 16px;display:grid;grid-template-columns:180px minmax(0,640px);gap:14px;align-items:start}
.author-menu{position:sticky;top:68px;background:#fff;border:1px solid var(--line);border-radius:12px;padding:10px;display:flex;flex-direction:column;gap:4px}
.author-menu-title{font-size:12px;font-weight:700;color:var(--muted);padding:4px 6px 6px}
.author-filter{display:flex;align-items:center;justify-content:space-between;gap:8px;border-radius:8px;padding:8px 10px;color:var(--muted);font-size:13px;text-decoration:none}
.author-filter:hover{background:#f8fafc;color:var(--accent-dark)}
.author-filter.active{background:#eaf7d8;color:var(--accent-dark);font-weight:700}
.author-count{font-size:11px;color:inherit;opacity:.7}
.timeline{display:flex;flex-direction:column;gap:2px}

.post-card{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:16px;display:flex;gap:12px;transition:box-shadow .15s}
.post-card:hover{box-shadow:0 1px 4px rgba(0,0,0,.08)}
.post-card.highlight{border-color:var(--accent);box-shadow:0 0 0 2px rgba(85,197,0,.15)}
.server-article{background:#fff;border:1px solid var(--line);border-radius:12px;padding:22px;box-shadow:0 1px 4px rgba(0,0,0,.06)}
.server-article h2{font-size:24px;line-height:1.45;margin:0 0 10px}
.server-meta{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:16px;color:var(--muted);font-size:12px}
.server-chip{border:1px solid var(--line);border-radius:999px;padding:2px 8px;background:#f8fafc}
.server-body{font-size:16px;line-height:1.9;white-space:normal;word-break:break-word}
.server-body a{color:var(--accent-dark);text-decoration:underline;word-break:break-all}
.server-related{margin-top:24px;padding-top:16px;border-top:1px solid var(--line)}
.server-related h3{font-size:15px;margin:0 0 10px}
.server-related ul{display:grid;gap:8px;margin:0;padding-left:1.1em;color:var(--muted);font-size:13px}
.server-related a{color:var(--ink);text-decoration:underline}
.server-products{margin-top:18px;padding-top:16px;border-top:1px solid var(--line)}
.server-products h3{font-size:16px;margin:0 0 10px}
.related-products-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
.related-product{display:grid;grid-template-columns:82px minmax(0,1fr);gap:10px;border:1px solid var(--line);border-radius:10px;background:#fff;padding:10px}
.related-product img{width:82px;height:82px;object-fit:contain;background:#f8fafc;border-radius:8px}
.related-product-title{font-size:13px;font-weight:700;line-height:1.45;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.related-product-desc{font-size:12px;color:var(--muted);line-height:1.55;margin-top:4px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.related-product-actions{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px}
.related-product-actions a{font-size:11px;font-weight:700;border-radius:999px;padding:5px 8px;text-decoration:none;border:1px solid var(--line);color:var(--muted)}
.related-product-actions a.amazon{background:#111827;color:#fff;border-color:#111827}
.related-product-actions a.rakuten{background:#fff;color:#991b1b;border-color:#fecaca}
.server-list{display:grid;gap:2px}

.avatar{width:42px;height:42px;border-radius:50%;background:var(--accent);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:12px;color:#fff;flex-shrink:0}

.post-main{flex:1;min-width:0}
.post-header{display:flex;align-items:baseline;gap:6px;margin-bottom:8px;flex-wrap:wrap}
.post-name{font-weight:700;font-size:14px}
.post-handle{color:var(--muted);font-size:13px}
.post-dot{color:var(--muted);font-size:12px}
.post-time{color:var(--muted);font-size:12px}
.post-time a{color:inherit}
.post-time a:hover{text-decoration:underline;color:var(--accent-dark)}

.post-body{font-size:15px;line-height:1.8;white-space:pre-wrap;word-break:break-word;color:var(--ink)}
.post-body a{color:var(--accent-dark);word-break:break-all}
.post-body a:hover{text-decoration:underline}
.post-html{white-space:normal}
.post-html p{margin:0 0 10px}
.post-html p:last-child{margin-bottom:0}
.post-html ul,.post-html ol{padding-left:1.4em;margin:8px 0 10px}
.post-html li{margin:3px 0}
.post-html table{width:100%;border-collapse:collapse;margin:10px 0;display:block;overflow-x:auto;white-space:normal}
.post-html th,.post-html td{border:1px solid var(--line);padding:7px 9px;vertical-align:top;font-size:13px;line-height:1.65}
.post-html th{background:#f8fafc;font-weight:700;color:#334155}
.post-html code{background:#f1f5f9;border-radius:4px;padding:1px 4px;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:.92em}
.post-html pre{background:#0f172a;color:#e2e8f0;border-radius:8px;padding:10px;overflow:auto;white-space:pre-wrap}
.post-html .uslideblog-embed{margin:10px 0 12px}
.post-html .uslideblog-embed iframe{width:100%;height:520px;border:1px solid var(--line);border-radius:10px;background:#fff}
.uslideblog-lazy{margin:10px 0 12px;border:1px solid var(--line);border-radius:10px;background:#f8fafc;padding:12px}
.uslideblog-lazy-title{font-weight:700;font-size:14px;margin-bottom:8px;color:#0f172a}
.uslideblog-lazy-btn{border:1px solid #bfdbfe;background:#eff6ff;color:#1d4ed8;border-radius:6px;padding:8px 12px;font-weight:700;cursor:pointer}
.uslideblog-lazy iframe{width:100%;height:520px;border:1px solid var(--line);border-radius:10px;background:#fff;margin-top:10px}
.post-edit{margin-top:12px;border:1px solid var(--line);border-radius:10px;background:#f8fafc;padding:10px}
.post-edit textarea{width:100%;min-height:180px;border:1px solid var(--line);border-radius:8px;padding:10px;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:13px;line-height:1.65;resize:vertical}
.post-edit-actions{display:flex;gap:8px;justify-content:flex-end;margin-top:8px}
.ogp-card{display:grid;grid-template-columns:128px minmax(0,1fr);gap:12px;margin-top:12px;border:1px solid var(--line);border-radius:12px;overflow:hidden;background:#fff;color:inherit;text-decoration:none}
.ogp-card:hover{border-color:#cbd5e1;box-shadow:0 1px 4px rgba(0,0,0,.08)}
.ogp-card.no-image{grid-template-columns:1fr}
.ogp-image{width:128px;height:128px;background:#f8fafc;object-fit:cover}
.ogp-body{min-width:0;padding:12px 12px 12px 0}
.ogp-card.no-image .ogp-body{padding:12px}
.ogp-host{font-size:12px;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-bottom:5px}
.ogp-title{font-size:14px;font-weight:700;line-height:1.45;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.ogp-desc{font-size:13px;color:var(--muted);line-height:1.55;margin-top:6px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}

.post-actions{display:flex;gap:6px;margin-top:12px;flex-wrap:wrap}
.post-affiliate{display:flex;gap:6px;margin-top:8px;flex-wrap:wrap}
.aff-btn{display:inline-flex;align-items:center;gap:4px;border-radius:6px;padding:4px 12px;font-size:11px;font-weight:700;text-decoration:none;transition:opacity .15s}
.aff-btn:hover{opacity:.8}
.aff-amazon{background:#111827;color:#fff}
.aff-rakuten{background:#bf0000;color:#fff}
.act-btn{background:none;border:1px solid var(--line);border-radius:20px;padding:5px 14px;font-size:12px;color:var(--muted);cursor:pointer;display:inline-flex;align-items:center;gap:4px;transition:all .15s;text-decoration:none}
.act-btn:hover{border-color:var(--accent);color:var(--accent-dark)}
.act-btn.copied{border-color:#22c55e;color:#22c55e}
.act-btn-x{background:#000;color:#fff !important;border-color:#000}
.act-btn-x:hover{background:#1a1a1a;border-color:#1a1a1a}
.act-btn-del{border-color:#fecaca;color:#ef4444 !important}
.act-btn-del:hover{background:#fee2e2;border-color:#ef4444}
.impression{border:1px solid var(--line);border-radius:20px;padding:5px 12px;font-size:12px;color:var(--muted);display:inline-flex;align-items:center;gap:4px;background:#fff}
.announcement{max-width:640px;margin:12px auto;padding:14px 18px;background:#e8fdf1;border:1px solid #a7f3d0;border-radius:14px;color:#064e3b;line-height:1.7;}
.announcement a{color:#065f46;text-decoration:underline}
.announcement a:hover{color:#047857}

.load-more{text-align:center;padding:16px;font-size:13px;color:var(--muted)}
.empty-msg{text-align:center;color:var(--muted);padding:60px 20px;font-size:15px;background:var(--card);border:1px solid var(--line);border-radius:12px}

#copy-toast{position:fixed;bottom:30px;left:50%;transform:translateX(-50%);background:#111;color:#fff;padding:10px 22px;border-radius:20px;font-size:13px;opacity:0;pointer-events:none;transition:opacity .3s;z-index:999}
#copy-toast.show{opacity:1}

@media(max-width:820px){.page-shell{display:block;margin-top:10px;padding:0 10px}.author-menu{position:static;display:flex;flex-direction:row;overflow-x:auto;margin-bottom:10px;border-radius:10px}.author-menu-title{display:none}.author-filter{white-space:nowrap;flex-shrink:0}.post-card{padding:14px 12px}.compose-wrap{padding:0 10px}.ogp-card{grid-template-columns:96px minmax(0,1fr)}.ogp-image{width:96px;height:96px}.ogp-body{padding:10px 10px 10px 0}.related-products-grid{grid-template-columns:1fr}.related-product{grid-template-columns:76px minmax(0,1fr)}.related-product img{width:76px;height:76px}}
</style>
<!-- Google tag (gtag.js) -->
<script async src="https://www.googletagmanager.com/gtag/js?id=G-BP0650KDFR"></script>
<script>
  window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);}
  gtag('js', new Date());
  gtag('config', 'G-BP0650KDFR');
</script>
<script>
(function () {
    var s = document.createElement('script');
    s.src = '/simpletrack.php'
        + '?url=' + encodeURIComponent(location.href)
        + '&ref=' + encodeURIComponent(document.referrer);
    document.head.appendChild(s);
})();
</script>
</head>
<body>

<div class="header">
  <a class="brand-link" href="sns.php" aria-label="AIxSNSニュース トップ">
    <div style="font-size:20px">📡</div>
    <h1>AIxSNSニュース</h1>
  </a>
  <a href="/" class="badge">AIxEC</a>
  <span class="badge">URL2AI</span>
  <div class="userbar">
    <?php if ($session_user): ?>
    <strong>@<?= h($session_user) ?></strong>
    <a href="?aff_logout=1" class="btn-sm">logout</a>
    <?php else: ?>
    <a href="?aff_login=1" class="btn-login-sm">X でログイン</a>
    <?php endif; ?>
  </div>
</div>
<?php include __DIR__ . '/vwork_promo.php'; ?>

<?php if ($is_admin): ?>
<div class="compose-wrap">
  <div class="compose">
    <div class="compose-header">
      <div class="compose-avatar">AIx</div>
      <textarea id="compose-text" placeholder="新しい情報を投稿... (Ctrl+Enter で送信)"></textarea>
    </div>
    <div class="compose-footer">
      <span class="compose-count" id="compose-count">0文字</span>
      <span class="compose-status" id="compose-status"></span>
      <button class="btn-post" id="compose-btn" onclick="createPost()">投稿する</button>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="page-shell">
  <aside class="author-menu" aria-label="投稿者フィルター">
    <div class="author-menu-title">投稿者</div>
    <a class="author-filter" data-author="" href="sns.php"><span>すべて（動画告知除く）</span><span class="author-count" data-count-for="">0</span></a>
    <a class="author-filter" data-author="xb_bittensor" href="sns.php?author=xb_bittensor"><span>@xb_bittensor</span><span class="author-count" data-count-for="xb_bittensor">0</span></a>
    <a class="author-filter" data-author="oss" href="sns.php?author=oss"><span>@oss</span><span class="author-count" data-count-for="oss">0</span></a>
    <a class="author-filter" data-author="osszenn" href="sns.php?author=osszenn"><span>@osszenn</span><span class="author-count" data-count-for="osszenn">0</span></a>
    <a class="author-filter" data-author="ainews" href="sns.php?author=ainews"><span>@ainews</span><span class="author-count" data-count-for="ainews">0</span></a>
    <a class="author-filter" data-author="aitech" href="sns.php?author=aitech"><span>@aitech</span><span class="author-count" data-count-for="aitech">0</span></a>
    <a class="author-filter" data-author="finreport" href="sns.php?author=finreport"><span>@finreport</span><span class="author-count" data-count-for="finreport">0</span></a>
    <a class="author-filter" data-author="polymarket" href="sns.php?author=polymarket"><span>@polymarket</span><span class="author-count" data-count-for="polymarket">0</span></a>
    <a class="author-filter" data-author="register" href="sns.php?author=register"><span>@register</span><span class="author-count" data-count-for="register">0</span></a>
    <a class="author-filter" data-author="AIxTubeG" href="sns.php?author=AIxTubeG"><span>@AIxTubeG</span><span class="author-count" data-count-for="AIxTubeG">0</span></a>
    <a class="author-filter" data-author="codex" href="sns.php?author=codex"><span>@codex</span><span class="author-count" data-count-for="codex">0</span></a>
    <a class="author-filter" data-author="claude" href="sns.php?author=claude"><span>@claude</span><span class="author-count" data-count-for="claude">0</span></a>
    <a class="author-filter" data-author="kurage" href="sns.php?author=kurage"><span>@kurage</span><span class="author-count" data-count-for="kurage">0</span></a>
    <a class="author-filter" data-author="buzblogger" href="sns.php?author=buzblogger"><span>@buzblogger</span><span class="author-count" data-count-for="buzblogger">0</span></a>
    <a class="author-filter" data-author="kgrowth" href="sns.php?author=kgrowth"><span>@kgrowth</span><span class="author-count" data-count-for="kgrowth">0</span></a>
  </aside>
  <main class="timeline" id="timeline">
    <?php if ($detail_post): ?>
    <article class="server-article" id="post-<?php echo (int)$detail_post['id']; ?>">
      <h2><?php echo h(post_title($detail_post)); ?></h2>
      <div class="server-meta">
        <a href="<?php echo h(author_profile_url($detail_post['author'] ?? 'xb_bittensor')); ?>">@<?php echo h($detail_post['author'] ?? 'xb_bittensor'); ?></a>
        <time datetime="<?php echo h(date('c', strtotime($detail_post['created_at']))); ?>"><?php echo h($detail_post['created_at']); ?></time>
        <span><?php echo (int)($detail_post['views'] ?? 0); ?> views</span>
      </div>
      <div class="server-body"><?php echo render_post_text_html($detail_post['content']); ?></div>
      <?php
        $detail_ogp_url = preferred_ogp_url_from_text($detail_post['content'] ?? '');
        $detail_ogp = $detail_ogp_url !== '' ? fetch_ogp_card($detail_ogp_url) : array();
        echo render_ogp_card_html($detail_ogp);
      ?>
      <?php
        $aff_kw = mb_substr(preg_replace('/https?:\/\/\S+|\s+/u', ' ', strip_tags($detail_post['content'] ?? '')), 0, 30);
        $aff_kw = trim($aff_kw) ?: 'AI';
        $aff_from = 'sns:' . (int)$detail_post['id'];
      ?>
      <div class="post-affiliate" style="margin-top:12px;">
        <a class="aff-btn aff-amazon" href="/go.php?to=amazon&kw=<?php echo urlencode($aff_kw); ?>&from=<?php echo urlencode($aff_from); ?>" target="_blank" rel="nofollow sponsored noopener">🛒 Amazonで探す</a>
        <a class="aff-btn aff-rakuten" href="/go.php?to=rakuten&kw=<?php echo urlencode($aff_kw); ?>&from=<?php echo urlencode($aff_from); ?>" target="_blank" rel="nofollow sponsored noopener">🛒 楽天で探す</a>
      </div>
      <?php $related_products = related_products_for_post($detail_post['content'], 6); ?>
      <?php if ($related_products): ?>
      <aside class="server-products">
        <h3>関連するAIxEC商品</h3>
        <div class="related-products-grid">
          <?php foreach ($related_products as $product): ?>
          <?php
            $product_name = (string)($product['name'] ?? '');
            $product_image = (string)($product['image_url'] ?? '');
            $product_desc = product_short_desc($product);
          ?>
          <div class="related-product">
            <a href="<?php echo h(product_page_url($product)); ?>">
              <?php if ($product_image !== ''): ?>
              <img src="<?php echo h($product_image); ?>" alt="<?php echo h($product_name); ?>" loading="lazy">
              <?php else: ?>
              <div style="width:82px;height:82px;background:#f8fafc;border-radius:8px;"></div>
              <?php endif; ?>
            </a>
            <div>
              <a class="related-product-title" href="<?php echo h(product_page_url($product)); ?>"><?php echo h($product_name); ?></a>
              <?php if ($product_desc !== ''): ?><div class="related-product-desc"><?php echo h($product_desc); ?></div><?php endif; ?>
              <div class="related-product-actions">
                <a class="amazon" href="<?php echo h(product_click_url($product, 'amazon')); ?>" target="_blank" rel="nofollow sponsored noopener">Amazonで探す</a>
                <?php if (!empty($product['rakuten_url'])): ?><a class="rakuten" href="<?php echo h(product_click_url($product, 'rakuten')); ?>" target="_blank" rel="nofollow sponsored noopener">楽天でも見る</a><?php endif; ?>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </aside>
      <?php endif; ?>
      <?php
        $related = array();
        foreach ($initial_posts as $item) {
            if ((int)($item['id'] ?? 0) === (int)$detail_post['id']) continue;
            if (count($related) >= 5) break;
            $related[] = $item;
        }
      ?>
      <?php if ($related): ?>
      <aside class="server-related">
        <h3>最近のAIxSNS投稿</h3>
        <ul>
          <?php foreach ($related as $item): ?>
          <li><a href="<?php echo h(post_path($item)); ?>"><?php echo h(post_title($item)); ?></a></li>
          <?php endforeach; ?>
        </ul>
      </aside>
      <?php endif; ?>
    </article>
    <?php elseif ($initial_posts): ?>
    <div class="server-list" aria-label="AIxSNS最新投稿">
      <?php foreach ($initial_posts as $item): ?>
      <article class="post-card" data-id="<?php echo (int)$item['id']; ?>">
        <div class="avatar" onclick="location.href='<?php echo h(post_path($item)); ?>'" style="cursor:pointer">AIx</div>
        <div class="post-main">
          <div class="post-header">
            <a class="post-name" href="sns.php?author=<?php echo urlencode($item['author'] ?? ''); ?>">@<?php echo h($item['author'] ?? 'xb_bittensor'); ?></a>
            <span class="post-dot">·</span>
            <span class="post-time"><a href="<?php echo h(post_path($item)); ?>"><?php echo h($item['created_at'] ?? ''); ?></a></span>
          </div>
          <h2 style="font-size:17px;line-height:1.55;margin:0 0 8px"><a href="<?php echo h(post_path($item)); ?>"><?php echo h(post_title($item)); ?></a></h2>
          <div class="post-body"><?php echo h(post_description($item)); ?></div>
        </div>
      </article>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </main>
</div>
<div id="copy-toast">コピーしました</div>

<script>
var IS_ADMIN   = <?php echo $is_admin ? 'true' : 'false'; ?>;
var ALL_POSTS  = [];
var API_OFFSET = 0;
var API_LIMIT  = 20;
var API_DONE   = false;
var LOADING    = false;
var FOCUS_ID   = <?php echo $post_id > 0 ? (int)$post_id : 'null'; ?>;
var DETAIL_POST = <?php echo $detail_post ? json_encode($detail_post, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : 'null'; ?>;
var AUTHOR_FILTER = new URLSearchParams(location.search).get('author') || '';
if (!/^[A-Za-z0-9_\-]{0,32}$/.test(AUTHOR_FILTER)) AUTHOR_FILTER = '';
var VIEWED_POSTS = {};

function esc(s){ return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

function firstUrl(text) {
    var m = String(text || '').match(/https?:\/\/[^\s<>"']+/);
    return m ? m[0].replace(/[。、，．,.)\]}]+$/g, '') : '';
}

function preferredOgpUrl(text) {
    var urls = String(text || '').match(/https?:\/\/[^\s<>"']+/g) || [];
    urls = urls.map(function(url){ return url.replace(/[。、，．,.)\]}]+$/g, ''); });
    for (var i = 0; i < urls.length; i++) {
        try {
            if (/(^|\.)amazon\.(co\.jp|com)$/i.test(new URL(urls[i]).hostname)) return urls[i];
        } catch (e) {}
    }
    return urls.length ? urls[0] : '';
}

function linkifyText(text) {
    return esc(text).replace(/(https?:\/\/[^\s<>"]+)/g,
        '<a href="$1" target="_blank" rel="noopener nofollow">$1</a>');
}

var ALLOWED_HTML_TAGS = {
    A:1, B:1, BLOCKQUOTE:1, BR:1, CODE:1, DIV:1, EM:1, H2:1, H3:1, H4:1,
    I:1, IFRAME:1, LI:1, OL:1, P:1, PRE:1, SPAN:1, STRONG:1, TABLE:1, TBODY:1,
    TD:1, TFOOT:1, TH:1, THEAD:1, TR:1, UL:1
};

function hasAllowedHtml(text) {
    return /<\/?(a|b|blockquote|br|code|div|em|h2|h3|h4|i|iframe|li|ol|p|pre|span|strong|table|tbody|td|tfoot|th|thead|tr|ul)\b/i.test(String(text || ''));
}

function sanitizeHtml(raw) {
    var template = document.createElement('template');
    template.innerHTML = String(raw || '');

    function linkifyTextNode(text) {
        var frag = document.createDocumentFragment();
        var re = /(https?:\/\/[^\s<>"']+)/g;
        var last = 0;
        String(text || '').replace(re, function(match, url, offset) {
            var cleanUrl = url.replace(/[。、，．,.)\]}]+$/g, '');
            var suffix = url.slice(cleanUrl.length);
            if (offset > last) frag.appendChild(document.createTextNode(text.slice(last, offset)));
            var a = document.createElement('a');
            a.href = cleanUrl;
            a.target = '_blank';
            a.rel = 'noopener nofollow';
            a.textContent = cleanUrl;
            frag.appendChild(a);
            if (suffix) frag.appendChild(document.createTextNode(suffix));
            last = offset + url.length;
            return match;
        });
        if (last < String(text || '').length) frag.appendChild(document.createTextNode(String(text || '').slice(last)));
        return frag;
    }

    function clean(node, preserveText) {
        if (node.nodeType === 3) {
            return preserveText ? document.createTextNode(node.nodeValue || '') : linkifyTextNode(node.nodeValue || '');
        }
        if (node.nodeType !== 1) return document.createTextNode('');

        var tag = node.tagName.toUpperCase();
        if (!ALLOWED_HTML_TAGS[tag]) {
            var safeFrag = document.createDocumentFragment();
            Array.prototype.slice.call(node.childNodes).forEach(function(child) {
                safeFrag.appendChild(clean(child, preserveText));
            });
            return safeFrag;
        }

        if (tag === 'IFRAME') {
            var src = node.getAttribute('src') || '';
            if (!/^https:\/\/aiknowledgecms\.exbridge\.jp\/uslideblog\.php\?[^"'<>\s]*\bembed=1\b/i.test(src)) {
                return document.createTextNode('');
            }
            var lazy = document.createElement('div');
            lazy.className = 'uslideblog-lazy';
            lazy.setAttribute('data-src', src);
            var title = document.createElement('div');
            title.className = 'uslideblog-lazy-title';
            title.textContent = 'USlideBlog';
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'uslideblog-lazy-btn';
            btn.textContent = 'スライドを表示';
            lazy.appendChild(title);
            lazy.appendChild(btn);
            return lazy;
        }

        var el = document.createElement(tag.toLowerCase());
        if (tag === 'A') {
            var href = node.getAttribute('href') || '';
            if (/^https?:\/\//i.test(href)) {
                el.setAttribute('href', href);
                el.setAttribute('target', '_blank');
                el.setAttribute('rel', 'noopener nofollow');
            }
        }
        if ((tag === 'TD' || tag === 'TH') && /^\d{1,2}$/.test(node.getAttribute('colspan') || '')) {
            el.setAttribute('colspan', node.getAttribute('colspan'));
        }
        if ((tag === 'TD' || tag === 'TH') && /^\d{1,2}$/.test(node.getAttribute('rowspan') || '')) {
            el.setAttribute('rowspan', node.getAttribute('rowspan'));
        }
        Array.prototype.slice.call(node.childNodes).forEach(function(child) {
            el.appendChild(clean(child, preserveText || tag === 'PRE' || tag === 'CODE' || tag === 'A'));
        });
        return el;
    }

    var frag = document.createDocumentFragment();
    Array.prototype.slice.call(template.content.childNodes).forEach(function(node) {
        frag.appendChild(clean(node, false));
    });
    var box = document.createElement('div');
    box.appendChild(frag);
    return box.innerHTML;
}

function renderContent(text) {
    if (hasAllowedHtml(text)) {
        return '<div class="post-html">' + sanitizeHtml(text) + '</div>';
    }
    return linkifyText(text);
}

function plainTextForShare(raw) {
    raw = String(raw || '');
    if (!hasAllowedHtml(raw)) return raw;

    var template = document.createElement('template');
    template.innerHTML = raw;
    var lines = [];

    function textOf(node) {
        return (node.textContent || '').replace(/[ \t\r\n]+/g, ' ').trim();
    }

    function walk(node) {
        if (node.nodeType === 3) {
            var t = node.nodeValue.replace(/[ \t\r\n]+/g, ' ').trim();
            if (t) lines.push(t);
            return;
        }
        if (node.nodeType !== 1) return;
        var tag = node.tagName.toUpperCase();
        if (tag === 'SCRIPT' || tag === 'STYLE') return;

        if (tag === 'TABLE') {
            Array.prototype.slice.call(node.querySelectorAll('tr')).forEach(function(row) {
                var cells = Array.prototype.slice.call(row.children)
                    .filter(function(cell){ return /^(TH|TD)$/i.test(cell.tagName); })
                    .map(textOf)
                    .filter(Boolean);
                if (cells.length) lines.push(cells.join(' | '));
            });
            lines.push('');
            return;
        }

        if (tag === 'LI') {
            var li = textOf(node);
            if (li) lines.push('- ' + li);
            return;
        }

        if (tag === 'A') {
            var label = textOf(node);
            var href = node.getAttribute('href') || '';
            if (/^https?:\/\//i.test(href) && label && label !== href) {
                lines.push(label + ' ' + href);
            } else if (href && /^https?:\/\//i.test(href)) {
                lines.push(href);
            } else if (label) {
                lines.push(label);
            }
            return;
        }

        if (/^(P|DIV|H2|H3|H4|BLOCKQUOTE|PRE)$/i.test(tag)) {
            var block = textOf(node);
            if (block) lines.push(block);
            lines.push('');
            return;
        }

        Array.prototype.slice.call(node.childNodes).forEach(walk);
    }

    Array.prototype.slice.call(template.content.childNodes).forEach(walk);
    return lines.join('\n').replace(/\n{3,}/g, '\n\n').trim();
}

function timeAgo(dateStr) {
    var d = new Date(dateStr.replace(' ','T'));
    var diff = (Date.now() - d.getTime()) / 1000;
    if (diff < 60)       return Math.floor(diff) + '秒前';
    if (diff < 3600)     return Math.floor(diff/60) + '分前';
    if (diff < 86400)    return Math.floor(diff/3600) + '時間前';
    if (diff < 86400*7)  return Math.floor(diff/86400) + '日前';
    return d.toLocaleDateString('ja-JP',{year:'numeric',month:'short',day:'numeric'});
}

function buildCard(p) {
    var path = p.slug ? ('/sns.php?slug=' + encodeURIComponent(p.slug)) : ('/sns.php?id=' + p.id);
    var postUrl = 'https://aixec.exbridge.jp' + path;
    var shareText = plainTextForShare(p.content);
    var author = p.author || 'xb_bittensor';
    var authorUrl = '/sns.php?author=' + encodeURIComponent(author);
    var delBtn  = IS_ADMIN
        ? '<button class="act-btn act-btn-del" onclick="deletePost('+p.id+',this)">🗑 削除</button>'
        : '';
    var editBtn = IS_ADMIN
        ? '<button class="act-btn act-btn-edit" onclick="editPost('+p.id+',this)">✏️ 編集</button>'
        : '';
    return '<div class="post-card'+(FOCUS_ID&&p.id==FOCUS_ID?' highlight':'')+'" data-id="'+p.id+'">'
        +'<div class="avatar" onclick="location.href=\''+esc(path)+'\'" style="cursor:pointer">AIx</div>'
        +'<div class="post-main">'
          +'<div class="post-header">'
            +'<a class="post-name" href="'+esc(authorUrl)+'">'+esc(author)+'</a>'
            +'<span class="post-dot">·</span>'
            +'<span class="post-time"><a href="'+esc(path)+'">'+esc(timeAgo(p.created_at))+'</a></span>'
          +'</div>'
          +'<div class="post-body">'+renderContent(p.content)+'</div>'
          +'<div class="ogp-slot" data-ogp-url="'+esc(preferredOgpUrl(p.content))+'"></div>'
          +'<div class="post-actions">'
            +'<span class="impression" title="表示回数">👁 <span data-views-for="'+p.id+'">'+esc(p.views || 0)+'</span></span>'
            +'<button class="act-btn copy-btn" data-content="'+esc(shareText)+'" data-url="'+esc(postUrl)+'">📋 コピー</button>'
            +'<button class="act-btn link-btn" data-url="'+esc(postUrl)+'">🔗 リンク</button>'
            +'<a class="act-btn act-btn-x" href="https://twitter.com/intent/tweet?text='+encodeURIComponent(shareText+'\n'+postUrl)+'" target="_blank" rel="noopener">𝕏 投稿</a>'
            + editBtn
            + delBtn
          +'</div>'
          +(function(){
              var kw = (p.content||'').replace(/https?:\/\/\S+/g,'').replace(/[#@\n\r]/g,' ').replace(/\s+/g,' ').trim().slice(0,30).trim() || 'AI';
              var amazonUrl = '/go.php?to=amazon&kw='+encodeURIComponent(kw)+'&from=sns:'+p.id;
              var rakutenUrl = '/go.php?to=rakuten&kw='+encodeURIComponent(kw)+'&from=sns:'+p.id;
              return '<div class="post-affiliate">'
                +'<a class="aff-btn aff-amazon" href="'+esc(amazonUrl)+'" target="_blank" rel="nofollow sponsored noopener">🛒 Amazonで探す</a>'
                +'<a class="aff-btn aff-rakuten" href="'+esc(rakutenUrl)+'" target="_blank" rel="nofollow sponsored noopener">🛒 楽天で探す</a>'
                +'</div>';
          })()
        +'</div>'
      +'</div>';
}

function renderOgpCard(card) {
    if (!card || !card.ok || (!card.title && !card.image)) return '';
    var cls = card.image ? 'ogp-card' : 'ogp-card no-image';
    var image = card.image ? '<img class="ogp-image" src="'+esc(card.image)+'" alt="" loading="lazy">' : '';
    return '<a class="'+cls+'" href="'+esc(card.url || '')+'" target="_blank" rel="noopener nofollow">'
        + image
        + '<div class="ogp-body">'
          + '<div class="ogp-host">'+esc(card.host || '')+'</div>'
          + '<div class="ogp-title">'+esc(card.title || card.url || '')+'</div>'
          + (card.description ? '<div class="ogp-desc">'+esc(card.description)+'</div>' : '')
        + '</div>'
      + '</a>';
}

function trackVisiblePosts() {
    var cards = Array.prototype.slice.call(document.querySelectorAll('.post-card[data-id]:not([data-view-observed])'));
    if (!cards.length) return;
    if (!('IntersectionObserver' in window)) {
        cards.forEach(function(card){ markPostViewed(card); });
        return;
    }
    var observer = new IntersectionObserver(function(entries, obs) {
        entries.forEach(function(entry) {
            if (entry.isIntersecting && entry.intersectionRatio >= 0.45) {
                markPostViewed(entry.target);
                obs.unobserve(entry.target);
            }
        });
    }, {threshold: [0.45]});
    cards.forEach(function(card) {
        card.setAttribute('data-view-observed', '1');
        observer.observe(card);
    });
}

function markPostViewed(card) {
    var id = card && card.getAttribute('data-id');
    if (!id || VIEWED_POSTS[id]) return;
    VIEWED_POSTS[id] = true;
    fetch('?post_view=' + encodeURIComponent(id), {cache: 'no-store'})
        .then(function(r){ return r.json(); })
        .then(function(d) {
            if (!d || d.status !== 'ok' || d.views === null) return;
            var node = document.querySelector('[data-views-for="'+id+'"]');
            if (node) node.textContent = d.views;
        })
        .catch(function(){});
}

function hydrateOgpCards(root) {
    (root || document).querySelectorAll('.ogp-slot[data-ogp-url]').forEach(function(slot) {
        var url = slot.dataset.ogpUrl || '';
        if (!url || slot.dataset.loaded) return;
        slot.dataset.loaded = '1';
        fetch('sns.php?api_ogp=' + encodeURIComponent(url), { credentials: 'same-origin' })
            .then(function(r){ return r.json(); })
            .then(function(card){
                var html = renderOgpCard(card);
                if (html) slot.innerHTML = html;
            })
            .catch(function(){});
    });
}

function hydrateUSlideEmbeds(root) {
    (root || document).querySelectorAll('.uslideblog-lazy[data-src]').forEach(function(box) {
        if (box.dataset.bound) return;
        box.dataset.bound = '1';
        var btn = box.querySelector('.uslideblog-lazy-btn');
        if (!btn) return;
        btn.addEventListener('click', function() {
            if (box.dataset.loaded) return;
            box.dataset.loaded = '1';
            btn.textContent = '読み込み中...';
            var iframe = document.createElement('iframe');
            iframe.src = box.dataset.src;
            iframe.width = '100%';
            iframe.height = '520';
            iframe.frameBorder = '0';
            iframe.loading = 'lazy';
            iframe.setAttribute('allowfullscreen', '');
            iframe.onload = function(){ btn.style.display = 'none'; };
            box.appendChild(iframe);
        });
    });
}

function renderPosts(posts) {
    var tl = document.getElementById('timeline');
    if (posts.length === 0 && ALL_POSTS.length === 0) {
        tl.innerHTML = '<div class="empty-msg">まだ投稿がありません</div>';
        return;
    }
    var frag = document.createDocumentFragment();
    posts.forEach(function(p) {
        var wrap = document.createElement('div');
        wrap.innerHTML = buildCard(p);
        frag.appendChild(wrap.firstChild);
    });
    tl.appendChild(frag);
    hydrateOgpCards(tl);
    hydrateUSlideEmbeds(tl);
    trackVisiblePosts();
}

function renderDetailPost() {
    if (!DETAIL_POST) return false;
    var tl = document.getElementById('timeline');
    ALL_POSTS = [DETAIL_POST];
    API_DONE = true;
    if (tl.querySelector('.server-article')) {
        updateAuthorMenu(1);
        return true;
    }
    tl.innerHTML = '';
    renderPosts([DETAIL_POST]);
    var card = document.querySelector('.post-card[data-id="'+DETAIL_POST.id+'"]');
    if (card) card.classList.add('highlight');
    updateAuthorMenu(1);
    return true;
}

function updateAuthorMenu(total) {
    document.querySelectorAll('.author-filter').forEach(function(link) {
        link.classList.toggle('active', (link.dataset.author || '') === AUTHOR_FILTER);
    });
    var totalEl = document.querySelector('.author-count[data-count-for=""]');
    if (totalEl && !AUTHOR_FILTER) totalEl.textContent = total;
    if (AUTHOR_FILTER) {
        var currentEl = document.querySelector('.author-count[data-count-for="'+AUTHOR_FILTER.replace(/"/g, '\\"')+'"]');
        if (currentEl) currentEl.textContent = total;
    }
    if (AUTHOR_FILTER === '') {
        document.querySelectorAll('.author-count[data-count-for]:not([data-count-for=""])').forEach(function(el){ el.textContent = ''; });
    }
}

function loadPosts() {
    if (API_DONE || LOADING) return;
    LOADING = true;
    var tl = document.getElementById('timeline');
    var url = 'sns.php?api_posts=1&offset='+API_OFFSET+'&limit='+API_LIMIT;
    if (AUTHOR_FILTER) url += '&author=' + encodeURIComponent(AUTHOR_FILTER);
    fetch(url)
        .then(function(r){ return r.json(); })
        .then(function(d){
            var items = d.items || [];
            if (items.length < API_LIMIT) API_DONE = true;
            API_OFFSET += items.length;
            ALL_POSTS = ALL_POSTS.concat(items);
            updateAuthorMenu(d.total || ALL_POSTS.length);
            renderPosts(items);
            LOADING = false;
            if (FOCUS_ID) {
                var card = document.querySelector('.post-card[data-id="'+FOCUS_ID+'"]');
                if (card) { setTimeout(function(){ card.scrollIntoView({behavior:'smooth',block:'start'}); }, 100); }
                FOCUS_ID = null;
            }
        })
        .catch(function(){ LOADING = false; });
}

function showToast(msg){
    var t = document.getElementById('copy-toast');
    t.textContent = msg; t.classList.add('show');
    setTimeout(function(){ t.classList.remove('show'); }, 2000);
}

document.getElementById('timeline').addEventListener('click', function(e) {
    var copyBtn = e.target.closest('.copy-btn');
    if (copyBtn) {
        var text = copyBtn.dataset.content + '\n' + copyBtn.dataset.url;
        navigator.clipboard.writeText(text).then(function(){
            copyBtn.textContent = '✓ コピー済'; copyBtn.classList.add('copied');
            setTimeout(function(){ copyBtn.textContent = '📋 コピー'; copyBtn.classList.remove('copied'); }, 2000);
            showToast('コピーしました');
        });
    }
    var linkBtn = e.target.closest('.link-btn');
    if (linkBtn) {
        navigator.clipboard.writeText(linkBtn.dataset.url).then(function(){
            linkBtn.textContent = '✓ コピー済'; linkBtn.classList.add('copied');
            setTimeout(function(){ linkBtn.textContent = '🔗 リンク'; linkBtn.classList.remove('copied'); }, 2000);
            showToast('リンクをコピーしました');
        });
    }
});

/* 無限スクロール */
var sentinel = document.createElement('div');
sentinel.style.height = '1px';
document.getElementById('timeline').after(sentinel);
if (DETAIL_POST) {
    renderDetailPost();
} else {
    document.getElementById('timeline').innerHTML = '';
    new IntersectionObserver(function(entries){
        if (entries[0].isIntersecting) loadPosts();
    }, { rootMargin:'400px' }).observe(sentinel);
    loadPosts();
}

<?php if ($is_admin): ?>
document.getElementById('compose-text').addEventListener('input', function(){
    document.getElementById('compose-count').textContent = this.value.length + '文字';
    this.style.height = 'auto';
    this.style.height = Math.max(100, this.scrollHeight) + 'px';
});
document.getElementById('compose-text').addEventListener('keydown', function(e){
    if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') createPost();
});

function createPost() {
    var content = document.getElementById('compose-text').value.trim();
    var btn     = document.getElementById('compose-btn');
    var status  = document.getElementById('compose-status');
    if (!content) { status.textContent='内容を入力してください'; status.className='compose-status err'; return; }
    btn.disabled = true;
    status.textContent = '投稿中...'; status.className = 'compose-status ok';

    var xhr = new XMLHttpRequest();
    xhr.open('POST', 'sns.php?post_create=1', true);
    xhr.setRequestHeader('Content-Type', 'application/json');
    xhr.onreadystatechange = function() {
        if (xhr.readyState !== 4) return;
        btn.disabled = false;
        try {
            var res = JSON.parse(xhr.responseText);
            if (res.status === 'ok') {
                status.textContent = '投稿しました ✓'; status.className = 'compose-status ok';
                document.getElementById('compose-text').value = '';
                document.getElementById('compose-text').style.height = '';
                document.getElementById('compose-count').textContent = '0文字';
                setTimeout(function(){ status.style.display='none'; }, 3000);
                /* 先頭に挿入 */
                ALL_POSTS.unshift(res.item);
                if (!AUTHOR_FILTER || AUTHOR_FILTER === (res.item.author || 'xb_bittensor')) {
                    var tl = document.getElementById('timeline');
                    var wrap = document.createElement('div');
                    wrap.innerHTML = buildCard(res.item);
                    var card = wrap.firstChild;
                    card.classList.add('highlight');
                    tl.insertBefore(card, tl.firstChild);
                    hydrateUSlideEmbeds(card);
                    trackVisiblePosts();
                    tl.querySelector('.empty-msg') && tl.querySelector('.empty-msg').remove();
                }
            } else {
                status.textContent = 'エラー: '+(res.error||'不明'); status.className = 'compose-status err';
            }
        } catch(e) { status.textContent = '通信エラー'; status.className = 'compose-status err'; }
    };
    xhr.send(JSON.stringify({ content: content }));
}

function findPost(id) {
    for (var i = 0; i < ALL_POSTS.length; i++) {
        if (String(ALL_POSTS[i].id) === String(id)) return ALL_POSTS[i];
    }
    return null;
}

function editPost(id, btn) {
    var card = document.querySelector('.post-card[data-id="'+id+'"]');
    var post = findPost(id);
    if (!card || !post) return;
    var main = card.querySelector('.post-main');
    var old = main.querySelector('.post-edit');
    if (old) { old.remove(); return; }
    var box = document.createElement('div');
    box.className = 'post-edit';
    box.innerHTML = '<textarea></textarea>'
        + '<div class="post-edit-actions">'
        + '<button class="act-btn" type="button" onclick="cancelEdit(this)">キャンセル</button>'
        + '<button class="act-btn act-btn-edit-save" type="button" onclick="savePost('+id+',this)">保存</button>'
        + '</div>';
    box.querySelector('textarea').value = post.content || '';
    main.appendChild(box);
    box.querySelector('textarea').focus();
}

function cancelEdit(btn) {
    var box = btn.closest('.post-edit');
    if (box) box.remove();
}

function savePost(id, btn) {
    var box = btn.closest('.post-edit');
    var text = box.querySelector('textarea').value.trim();
    if (!text) { showToast('内容を入力してください'); return; }
    btn.disabled = true;
    btn.textContent = '保存中...';
    fetch('sns.php?post_update=' + id, {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        credentials: 'same-origin',
        body: JSON.stringify({id: id, content: text})
    })
    .then(function(r){ return r.json(); })
    .then(function(d){
        btn.disabled = false;
        btn.textContent = '保存';
        if (d.status !== 'ok') throw new Error(d.error || '更新失敗');
        for (var i = 0; i < ALL_POSTS.length; i++) {
            if (String(ALL_POSTS[i].id) === String(id)) ALL_POSTS[i] = d.item;
        }
        var card = document.querySelector('.post-card[data-id="'+id+'"]');
        if (card) {
            var wrap = document.createElement('div');
            wrap.innerHTML = buildCard(d.item);
            card.replaceWith(wrap.firstChild);
            var newCard = document.querySelector('.post-card[data-id="'+id+'"]');
            hydrateOgpCards(newCard);
            hydrateUSlideEmbeds(newCard);
        }
        showToast('更新しました');
    })
    .catch(function(e){
        btn.disabled = false;
        btn.textContent = '保存';
        showToast('エラー: ' + e.message);
    });
}

function deletePost(id, btn) {
    if (!confirm('この投稿を削除しますか？')) return;
    fetch('sns.php?post_delete=' + id)
        .then(function(r){ return r.json(); })
        .then(function(d){
            if (d.status === 'ok') {
                var card = document.querySelector('.post-card[data-id="'+id+'"]');
                if (card) { card.style.opacity = '0'; card.style.transition = 'opacity .3s'; setTimeout(function(){ card.remove(); }, 300); }
                showToast('削除しました');
            }
        });
}
<?php endif; ?>
</script>
</body>
</html>
