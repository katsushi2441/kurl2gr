"""RQDB4AI ワーカー入口。

gpt-researcher は1回の調査で多数のLLM呼び出しを行うため、呼び出しごとに
キューへ載せると待ち時間が積み上がって終わらない。そこで**調査まるごと1件を
1ジョブ**として投入し、ワーカー側で実行する。こうすればホスト別キューによる
GPU直列化(rqdb4aiの目的)を保ったまま、内部の多段呼び出しは連続で回せる。

ワーカーには kurl2gr のパスと gpt-researcher の依存が要る
(scripts/configure_rqdb4ai_access.py が設定する)。
"""

from __future__ import annotations

import asyncio
import os
import sys
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[1]


def research_job(
    site: dict[str, Any],
    keyword: str = "",
    owner: str = "",
    paid: bool = False,
    **_: Any,
) -> dict[str, Any]:
    """サイト概要（＋キーワード）で調査し、レポートと出典を返す。

    LLMの向き先は research_service が paid で決める。無料枠なら
    ワーカーと同じホストのOllamaを使うので、GPUはこのキューで直列化される。
    """
    if not isinstance(site, dict) or not site.get("url"):
        raise RuntimeError("site is required")

    if str(ROOT) not in sys.path:
        sys.path.insert(0, str(ROOT))
    os.environ.setdefault("KURL2GR_DATA_DIR", str(ROOT / "data"))
    # ワーカー内から再びキューへ載せると無限に積む。ここで直接実行に切り替える。
    os.environ["KURL2GR_IN_WORKER"] = "1"

    from app import research_service  # noqa: E402  パスを通した後に読む

    result = asyncio.run(
        research_service.run_research(site, keyword, owner or "worker", paid=bool(paid))
    )
    # ジョブ結果はJSONで往復するので、素の型だけにする
    return {
        "query": str(result.get("query") or ""),
        "report": str(result.get("report") or ""),
        "sources": [str(s) for s in (result.get("sources") or [])][:10],
    }


def ollama_chat_job(
    messages: list[dict[str, str]],
    model: str = "gemma4:12b-it-qat",
    temperature: float = 0.2,
    num_predict: int = 2000,
    request_timeout: int = 300,
    **_: Any,
) -> dict[str, Any]:
    """単発のチャット。サイト概要の抽出と、記事への整形に使う。

    gemma4は思考型なので think=False を必ず指定する。指定しないと隠れ推論
    トークンが num_predict を食い潰し、応答が空になる(ワークスペースの既知の罠)。
    """
    import json as _json

    import requests

    if not isinstance(messages, list) or not messages:
        raise RuntimeError("messages are required")
    if len(_json.dumps(messages, ensure_ascii=False)) > 120_000:
        raise RuntimeError("messages are too large")

    base = os.environ.get("KURL2GR_OLLAMA_BASE_URL", "http://192.168.0.14:11434").rstrip("/")
    response = requests.post(
        f"{base}/api/chat",
        json={
            "model": str(model),
            "messages": messages,
            "stream": False,
            "think": False,
            "options": {"temperature": float(temperature), "num_predict": int(num_predict)},
        },
        timeout=max(30, int(request_timeout)),
    )
    response.raise_for_status()
    body = response.json()
    return {"content": (body.get("message") or {}).get("content", ""), "model": str(model)}
