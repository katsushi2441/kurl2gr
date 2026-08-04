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
upload static/index.html         kurl2gr_app.html   "$app_remote"
upload static/styles.css         assets/kurl2gr.css "$app_remote"
upload static/app.js             assets/kurl2gr.js  "$app_remote"

# --- LP(kurl2gr.exbridge.jp) ---
upload landing/index.html     index.html       "$lp_remote"
upload landing/kurl2gr.html   kurl2gr.html     "$lp_remote"
upload landing/sitemap.php    sitemap.php      "$lp_remote"
upload landing/robots.txt     robots.txt       "$lp_remote"
upload landing/assets/ogp.png assets/ogp.png   "$lp_remote"

echo "published: https://kurage.exbridge.jp/kurl2gr.php"
echo "published: https://kurl2gr.exbridge.jp/ (en) / https://kurl2gr.exbridge.jp/kurl2gr.html (ja)"
