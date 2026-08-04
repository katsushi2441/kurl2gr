<?php
// kurl2gr.exbridge.jp のサイトマップ。lastmod はLPファイルの更新時刻から出す。
header('Content-Type: application/xml; charset=utf-8');
date_default_timezone_set('Asia/Tokyo');

$base = 'https://kurl2gr.exbridge.jp';
$pages = array(
    array('file' => 'index.html', 'loc' => $base . '/', 'priority' => '1.0'),
    array('file' => 'kurl2gr.html', 'loc' => $base . '/kurl2gr.html', 'priority' => '0.9'),
);
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"'
   . ' xmlns:xhtml="http://www.w3.org/1999/xhtml">' . "\n";
foreach ($pages as $page) {
    $path = __DIR__ . '/' . $page['file'];
    $lastmod = date('Y-m-d', file_exists($path) ? filemtime($path) : time());
    echo "  <url>\n";
    echo '    <loc>' . htmlspecialchars($page['loc'], ENT_QUOTES, 'UTF-8') . "</loc>\n";
    echo '    <xhtml:link rel="alternate" hreflang="en" href="' . $base . '/"/>' . "\n";
    echo '    <xhtml:link rel="alternate" hreflang="ja" href="' . $base . '/kurl2gr.html"/>' . "\n";
    echo '    <lastmod>' . $lastmod . "</lastmod>\n";
    echo '    <changefreq>weekly</changefreq>' . "\n";
    echo '    <priority>' . $page['priority'] . "</priority>\n";
    echo "  </url>\n";
}
echo '</urlset>' . "\n";
