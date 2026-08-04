from __future__ import annotations

import os
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
DATA_DIR = Path(os.environ.get("KURL2GR_DATA_DIR", ROOT / "data"))
STATIC_DIR = ROOT / "static"
VENDOR_DIR = ROOT / "vendor"

HOST = os.environ.get("KURL2GR_HOST", "0.0.0.0")
# 18300番台の空き最若番号を実測で確保した番号(2026-08-04)。
# ss -ltn で未使用、かつ他プロジェクトの設定にも申告が無いことを確認済み。
PORT = int(os.environ.get("KURL2GR_PORT", "18358"))
DB_PATH = Path(os.environ.get("KURL2GR_DB", DATA_DIR / "kurl2gr.db"))

# heteml のPHPゲートウェイから渡される共有トークン。未設定ならローカル開発。
INTERNAL_TOKEN = os.environ.get("KURL2GR_INTERNAL_TOKEN", "").strip()
DEV_USER = os.environ.get("KURL2GR_DEV_USER", "local")

_admin = os.environ.get("KURL2GR_ADMIN_USERS", "").strip() or "xb_bittensor"
ADMIN_USERS = {u.strip().lstrip("@").lower() for u in _admin.split(",") if u.strip()}

# 投稿の著者名。顧客サイトのSNSに出る表示名で、リサーチ由来であることを示す。
RESEARCH_AUTHOR = os.environ.get("KURL2GR_RESEARCH_AUTHOR", "gptresearcher").strip()

# --- LLM: 無料枠はローカルOllama(rqdb4ai経由)、課金はDeepSeek -----------------
# kgeo と同じ方針。無料枠でDeepSeekを呼ぶとAPI原価がそのまま赤字になる。
OLLAMA_MODEL = os.environ.get("KURL2GR_OLLAMA_MODEL", "gemma4:12b-it-qat")
RQDB4AI_URL = os.environ.get("KURL2GR_RQDB4AI_URL", "").strip().rstrip("/")
RQDB4AI_TOKEN = os.environ.get("KURL2GR_RQDB4AI_TOKEN", "").strip()
RQDB4AI_FUNCTION = (
    os.environ.get("KURL2GR_RQDB4AI_FUNCTION", "").strip() or "kurl2gr.jobs.ollama_chat_job"
)
RQDB4AI_POLL_INTERVAL = max(0.5, float(os.environ.get("KURL2GR_RQDB4AI_POLL_INTERVAL", "2")))
RQDB4AI_WAIT_TIMEOUT = max(60.0, float(os.environ.get("KURL2GR_RQDB4AI_WAIT_TIMEOUT", "900")))
# リサーチは調査まるごと1ジョブなので、単発のチャットより長く待つ
RQDB4AI_RESEARCH_FUNCTION = (
    os.environ.get("KURL2GR_RQDB4AI_RESEARCH_FUNCTION", "").strip()
    or "kurl2gr.jobs.research_job"
)
RQDB4AI_RESEARCH_TIMEOUT = max(
    120.0, float(os.environ.get("KURL2GR_RQDB4AI_RESEARCH_TIMEOUT", "1800")))

DEEPSEEK_BASE_URL = (
    os.environ.get("KURL2GR_DEEPSEEK_BASE_URL", "").strip() or "https://api.deepseek.com"
)
DEEPSEEK_API_KEY = os.environ.get("KURL2GR_DEEPSEEK_API_KEY", "").strip()
DEEPSEEK_MODEL = os.environ.get("KURL2GR_DEEPSEEK_MODEL", "").strip() or "deepseek-v4-flash"
DEEPSEEK_TIMEOUT = float(os.environ.get("KURL2GR_DEEPSEEK_TIMEOUT", "300"))

# 無料枠。これを超えた実行だけ課金＝DeepSeekになる。
FREE_RESEARCHES_PER_MONTH = int(os.environ.get("KURL2GR_FREE_RESEARCHES_PER_MONTH", "3"))

# サイト概要の取得で読む本文の上限。長すぎるとLLMの入力を食い潰す。
SITE_CONTEXT_CHARS = int(os.environ.get("KURL2GR_SITE_CONTEXT_CHARS", "6000"))
FETCH_TIMEOUT = float(os.environ.get("KURL2GR_FETCH_TIMEOUT", "20"))
