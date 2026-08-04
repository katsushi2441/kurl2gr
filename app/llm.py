"""LLMの出し分け。無料枠はローカルOllama(rqdb4ai経由)、課金はDeepSeek。

kgeo と同じ方針。無料枠でDeepSeekを呼ぶとAPI原価がそのまま赤字になり、
自社GPUを持っている利点も消える。既定は paid=True(DeepSeek側)にしてある。
呼び出し漏れが無料扱いに倒れると原価が静かに漏れるため。
"""

from __future__ import annotations

import json
import re
import time

import httpx

from . import config


def provider_for(owner: str, *, paid: bool = True) -> str:
    if owner.strip().lstrip("@").lower() in config.ADMIN_USERS:
        return "ollama"
    return "deepseek" if paid else "ollama"


def configured(owner: str, *, paid: bool = True) -> bool:
    if provider_for(owner, paid=paid) == "ollama":
        return bool(config.RQDB4AI_URL and config.RQDB4AI_TOKEN)
    return bool(config.DEEPSEEK_BASE_URL and config.DEEPSEEK_MODEL and config.DEEPSEEK_API_KEY)


def _run_ollama(messages: list[dict[str, str]]) -> tuple[str, str, str]:
    """rqdb4ai のホスト別キュー経由でローカルGemmaを呼ぶ。

    直接Ollamaを叩かずキューに載せるのは、他プロダクトとGPUを取り合わないため
    (ワークスペースの既定。queue=auto がホストとsourceからキューを決める)。
    """
    headers = {"Authorization": f"Bearer {config.RQDB4AI_TOKEN}"}
    payload = {
        "function": config.RQDB4AI_FUNCTION,
        "queue": "auto",
        "kwargs": {
            "messages": messages,
            "model": config.OLLAMA_MODEL,
            # gemma4は思考型。thinkを切らないと隠れ推論トークンが出力を食い潰す。
            "think": False,
        },
        "meta": {"ollama_host": "192.168.0.14", "source": "web_online"},
    }
    with httpx.Client(timeout=60) as client:
        response = client.post(f"{config.RQDB4AI_URL}/api/enqueue", json=payload, headers=headers)
        response.raise_for_status()
        body = response.json()
        job = body.get("job") or body
        job_id = job.get("id") or job.get("job_id")
        if not job_id:
            raise RuntimeError("rqdb4ai がジョブIDを返しませんでした")

        deadline = time.time() + config.RQDB4AI_WAIT_TIMEOUT
        while time.time() < deadline:
            time.sleep(config.RQDB4AI_POLL_INTERVAL)
            body = client.get(f"{config.RQDB4AI_URL}/api/jobs/{job_id}", headers=headers).json()
            job = body.get("job") or body
            status = str(job.get("status") or "")
            if status == "finished":
                result = job.get("result") or {}
                text = result.get("content") or result.get("text") or ""
                return str(text), "ollama-rqdb4ai", config.OLLAMA_MODEL
            if status in {"failed", "canceled", "stopped"}:
                raise RuntimeError(f"rqdb4ai ジョブが {status}: {job.get('error') or ''}")
    raise RuntimeError("rqdb4ai の待機がタイムアウトしました")


def _run_deepseek(messages: list[dict[str, str]]) -> tuple[str, str, str]:
    payload = {
        "model": config.DEEPSEEK_MODEL,
        "messages": messages,
        # deepseek系は思考型。低いmax_tokensで空応答になるのを避ける。
        "thinking": {"type": "disabled"},
    }
    with httpx.Client(timeout=config.DEEPSEEK_TIMEOUT) as client:
        response = client.post(
            f"{config.DEEPSEEK_BASE_URL.rstrip('/')}/chat/completions",
            json=payload,
            headers={"Authorization": f"Bearer {config.DEEPSEEK_API_KEY}"},
        )
        response.raise_for_status()
        body = response.json()
    text = body["choices"][0]["message"]["content"]
    return str(text), "deepseek", config.DEEPSEEK_MODEL


def run(messages: list[dict[str, str]], owner: str, *, paid: bool) -> tuple[str, str, str]:
    if not configured(owner, paid=paid):
        raise RuntimeError("LLMが設定されていません")
    if provider_for(owner, paid=paid) == "ollama":
        return _run_ollama(messages)
    return _run_deepseek(messages)


def strip_fence(text: str) -> str:
    """```json ... ``` を外す。モデルが頼まなくても付けるため。"""
    stripped = (text or "").strip()
    match = re.match(r"^```[a-zA-Z]*\s*\n(.*?)\n?```$", stripped, re.S)
    return match.group(1).strip() if match else stripped


def parse_json(text: str) -> dict:
    """LLM出力からJSONを取り出す。前後に説明が付いても拾う。"""
    body = strip_fence(text)
    try:
        return json.loads(body)
    except json.JSONDecodeError:
        match = re.search(r"\{.*\}", body, re.S)
        if not match:
            raise
        return json.loads(match.group(0))
