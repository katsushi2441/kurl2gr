#!/usr/bin/env bash
# kurl2gr の公開デプロイ。
#   アプリ入口 : https://kurage.exbridge.jp/kurl2gr.php  (/web/kurage_exbridge_jp)
#   LP        : https://kurl2gr.exbridge.jp/            (/web/kurl2gr_exbridge_jp)
# バックエンド(:18358)はこの環境のsystemd user unitで動く。ここでは触らない。
set -euo pipefail
cd "$(dirname "$0")/.."
set -a
. /home/kojima/work/aixec/.env
set +a

if [[ ! -f public/kurl2gr_config.php ]]; then
  echo "missing public/kurl2gr_config.php; run scripts/configure_runtime.py first" >&2
  exit 1
fi

app_remote="/web/kurage_exbridge_jp"
lp_remote="/web/kurl2gr_exbridge_jp"

upload() {  # upload <local> <remote-path> <remote-root>
  curl --fail --silent --show-error --ftp-create-dirs -T "$1" \
    "ftp://${FTP_USER}:${FTP_PASS}@${FTP_HOST}${3}/${2}"
  echo "deployed: ${3}/${2}"
}

# --- アプリ入口(kurage.exbridge.jp) ---
upload public/kurl2gr.php        kurl2gr.php        "$app_remote"
upload public/kurl2gr_config.php kurl2gr_config.php "$app_remote"
upload public/kurl2gr_billing.php kurl2gr_billing.php "$app_remote"
upload public/kurl2gr_data/.htaccess kurl2gr_data/.htaccess "$app_remote"
upload static/index.html         kurl2gr_app.html   "$app_remote"
upload static/styles.css         assets/kurl2gr.css "$app_remote"
upload static/app.js             assets/kurl2gr.js  "$app_remote"

# --- LP(kurl2gr.exbridge.jp) ---
upload landing/index.html     index.html       "$lp_remote"
upload landing/kurl2gr.html   kurl2gr.html     "$lp_remote"
upload landing/sitemap.php    sitemap.php      "$lp_remote"
upload landing/robots.txt     robots.txt       "$lp_remote"
upload landing/assets/ogp.png assets/ogp.png   "$lp_remote"
upload landing/assets/kurage_avatar.webp assets/kurage_avatar.webp "$lp_remote"
upload landing/assets/kurage_avatar.png  assets/kurage_avatar.png  "$lp_remote"

echo "published: https://kurage.exbridge.jp/kurl2gr.php"
echo "published: https://kurl2gr.exbridge.jp/ (en) / https://kurl2gr.exbridge.jp/kurl2gr.html (ja)"

# --- 顧客サイト側の表示ファイル（テスト設置先: exbridge.jp） ---
# 表示専用。投稿・編集の権限は顧客サーバーに置かない。
if [[ -f public/sns_config.php ]]; then
  cust_remote="/web/exbridge_jp"
  upload public/sns.php        sns.php        "$cust_remote"
  upload public/sns_config.php sns_config.php "$cust_remote"
  upload public/sns_widget.php sns_widget.php "$cust_remote"
  echo "published: https://exbridge.jp/sns.php"
fi
