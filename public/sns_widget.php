<?php
// 既存サイトに貼る「新着」枠。
//
// 更新が止まったコーポレートサイトでも、ここに新着が並べば動いて見える。
// 表示専用で、投稿の実体は kurl2gr が持つ。site_key で自分の投稿だけを読む。
//
// 使い方（既存ページの好きな位置に1行）:
//   <?php include __DIR__ . '/sns_widget.php'; ?>
// もしくは iframe:
//   <iframe src="/sns_widget.php?embed=1" style="width:100%;height:420px;border:0"></iframe>
//
// PHP 5.x で動く構文だけを使う（?? / match / 型宣言は使わない）。
require_once __DIR__ . '/sns_config.php';

if (!function_exists('kurl2gr_widget_fetch')) {
    function kurl2gr_widget_fetch($limit) {
        $url = rtrim(KURL2GR_API_BASE, '/') . '/public/posts?'
             . http_build_query(array('key' => KURL2GR_SITE_KEY, 'limit' => $limit));
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false || $status !== 200) { return array(); }
        $data = json_decode($body, true);
        return (is_array($data) && isset($data['items']) && is_array($data['items']))
            ? $data['items'] : array();
    }
    function kurl2gr_widget_h($v) {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
    function kurl2gr_widget_excerpt($text, $length) {
        $flat = trim(preg_replace('/\s+/u', ' ', strip_tags((string)$text)));
        if (mb_strlen($flat, 'UTF-8') <= $length) { return $flat; }
        return mb_substr($flat, 0, $length, 'UTF-8') . '…';
    }
    function kurl2gr_widget_date($value) {
        $ts = strtotime((string)$value);
        return $ts ? date('Y.m.d', $ts) : '';
    }
}

$kurl2gr_limit = isset($_GET['limit']) ? max(1, min(20, (int)$_GET['limit'])) : 5;
$kurl2gr_items = kurl2gr_widget_fetch($kurl2gr_limit);
$kurl2gr_embed = isset($_GET['embed']) && $_GET['embed'] === '1';
$kurl2gr_sns_url = defined('KURL2GR_SNS_URL') ? KURL2GR_SNS_URL : 'sns.php';

if ($kurl2gr_embed) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="ja"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width, initial-scale=1"></head><body>';
}
?>
<style>
  .k2g-news { font-family:-apple-system,BlinkMacSystemFont,"Hiragino Sans","Noto Sans JP",sans-serif;
    max-width:860px; margin:0 auto; }
  .k2g-news h2 { font-size:17px; font-weight:800; margin:0 0 4px; }
  .k2g-news .k2g-sub { font-size:12px; color:#7a8a95; margin:0 0 14px; }
  .k2g-list { list-style:none; padding:0; margin:0; border-top:1px solid #e3ebee; }
  .k2g-item { border-bottom:1px solid #e3ebee; padding:13px 2px; }
  .k2g-item a { text-decoration:none; color:inherit; display:block; }
  .k2g-item a:hover .k2g-title { text-decoration:underline; }
  .k2g-meta { font-size:11.5px; color:#8b9aa4; letter-spacing:.02em; }
  .k2g-title { font-size:14.5px; font-weight:700; margin:3px 0 4px; line-height:1.6; color:#12202f; }
  .k2g-excerpt { font-size:12.5px; color:#5c6b76; line-height:1.75; }
  .k2g-more { display:inline-block; margin-top:14px; font-size:12.5px; font-weight:700; color:#0a726b; text-decoration:none; }
  .k2g-empty { font-size:13px; color:#8b9aa4; padding:14px 0; }
  @media (prefers-color-scheme:dark){
    .k2g-list,.k2g-item { border-color:#233; }
    .k2g-title { color:#eaf3f3; }
    .k2g-excerpt { color:#9fb3ba; }
  }
</style>
<section class="k2g-news">
  <h2>新着</h2>
  <p class="k2g-sub">自動で収集・要約した最新情報を掲載しています。</p>
<?php if (!$kurl2gr_items) { ?>
  <p class="k2g-empty">まだ記事がありません。</p>
<?php } else { ?>
  <ul class="k2g-list">
<?php foreach ($kurl2gr_items as $item) {
        $link = $kurl2gr_sns_url . '?id=' . (int)$item['id'];
        $title = isset($item['title']) && $item['title'] !== ''
               ? $item['title'] : kurl2gr_widget_excerpt($item['content'], 40); ?>
    <li class="k2g-item">
      <a href="<?php echo kurl2gr_widget_h($link); ?>">
        <div class="k2g-meta"><?php echo kurl2gr_widget_h(kurl2gr_widget_date($item['created_at'])); ?></div>
        <div class="k2g-title"><?php echo kurl2gr_widget_h($title); ?></div>
        <div class="k2g-excerpt"><?php echo kurl2gr_widget_h(kurl2gr_widget_excerpt($item['content'], 90)); ?></div>
      </a>
    </li>
<?php } ?>
  </ul>
  <a class="k2g-more" href="<?php echo kurl2gr_widget_h($kurl2gr_sns_url); ?>">すべて見る →</a>
<?php } ?>
</section>
<?php if ($kurl2gr_embed) { echo '</body></html>'; } ?>
