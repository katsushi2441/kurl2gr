"""RQDB4AI ワーカー入口。

gpt-researcher は1回の調査で多数のLLM呼び出しを行うため、呼び出しごとに
キューへ載せると待ち時間が積み上がって終わらない。そこで**調査まるごと1件を
1ジョブ**として投入し、ワーカー側で実行する。こうすればホスト別キューによる
GPU直列化(rqdb4aiの目的)を保ったまま、内部の多段呼び出しは連続で回せる。

ワーカーには kurl2gr のパスと gpt-researcher の依存が要る
(scripts/configure_rqdb4ai_access.py が設定する)。
"""

from __future__ import annotations

import os
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[1]


def research_job(
    site: dict[str, Any],
    keyword: str = "",
    owner: str = "",
    paid: bool = False,
    timeout: int = 1500,
    **_: Any,
) -> dict[str, Any]:
    """サイト概要（＋キーワード）で調査し、レポートと出典を返す。

    gpt-researcher は Python 3.11+ が必要で、依存も重い(torch等)。共有ワーカーの
    Python(3.10/3.11)へ同居させると他プロダクトのジョブを壊すため、専用venv
    (.venv-gr / 3.12)の別プロセスで実行し、JSONで受け渡しする。

    調査まるごとを1ジョブにしているので、ホスト別キューによるGPU直列化
    (rqdb4aiの目的)は保たれる。
    """
    import json
    import subprocess

    if not isinstance(site, dict) or not site.get("url"):
        raise RuntimeError("site is required")

    python = ROOT / ".venv-gr" / "bin" / "python"
    if not python.exists():
        raise RuntimeError(f"リサーチ用のvenvがありません: {python}")

    payload = json.dumps(
        {"site": site, "keyword": keyword, "owner": owner or "worker", "paid": bool(paid)},
        ensure_ascii=False,
    )
    env = dict(os.environ)
    env["PYTHONPATH"] = str(ROOT)
    env["KURL2GR_IN_WORKER"] = "1"      # 子プロセスから再びキューへ載せない
    # gpt-researcher は進捗を標準出力へ流すので、結果はファイルで受け取る
    import tempfile

    with tempfile.NamedTemporaryFile("r", suffix=".json", delete=False) as handle:
        out_path = handle.name
    try:
        completed = subprocess.run(
            [str(python), "-m", "kurl2gr.run_research", out_path],
            input=payload, capture_output=True, text=True,
            timeout=max(120, int(timeout)), env=env, cwd=str(ROOT),
        )
        if completed.returncode != 0:
            tail = (completed.stderr or "").strip().splitlines()[-3:]
            raise RuntimeError("リサーチ実行に失敗: " + " / ".join(tail))
        with open(out_path, encoding="utf-8") as handle:
            return json.load(handle)
    except json.JSONDecodeError as exc:
        raise RuntimeError(f"リサーチ結果を解釈できません: {exc}") from exc
    finally:
        try:
            os.unlink(out_path)
        except OSError:
            pass


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
