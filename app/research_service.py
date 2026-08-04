"""サイト概要（＋任意のキーワード）を足がかりに、外部の新しい情報を調べる。

「日々更新されていない企業サイトは死んで見える」という課題に対し、
既存サイトの内容に沿った新着情報を継続的に足すのが目的。
無関係な話題を自動投稿しても賑やかになるだけなので、必ずサイト概要を土台にする。

リサーチの実体は vendor/gpt-researcher（Apache-2.0・コミットピン留め）。
planner/execution/publisher の多エージェント構成で、検索して引用付きの
レポートを書く。ここではその設定と、投稿用の整形を担う。
"""

from __future__ import annotations

import os
import re
import sys
from pathlib import Path

from . import config, llm

VENDOR = config.VENDOR_DIR / "gpt-researcher"
MAX_POST_CHARS = 2400


def query_for(site: dict, keyword: str = "") -> str:
    """調査クエリを組み立てる。サイト概要が土台、キーワードは絞り込み。"""
    name = (site.get("name") or "").strip()
    summary = (site.get("summary") or "").strip()
    topics = [t for t in (site.get("topics") or "").split(",") if t.strip()]
    base = summary or name or site.get("url", "")
    if keyword.strip():
        return f"{base} に関連する「{keyword.strip()}」の最新動向とニュース"
    focus = "・".join(topics[:3]) if topics else base
    return f"{focus} に関する最近のニュースと動向（{name or site.get('url','')} の事業領域）"


def _llm_env(owner: str, *, paid: bool) -> dict[str, str]:
    """gpt-researcher へ渡すLLM設定。

    gpt-researcher は1回の調査で多数のLLM呼び出しを行うため、
    rqdb4ai のジョブキューを1呼び出しごとに挟むと現実的な時間で終わらない。
    無料枠はOllamaへ直接、課金はDeepSeek(OpenAI互換)へ向ける。
    """
    if llm.provider_for(owner, paid=paid) == "ollama":
        model = f"ollama:{config.OLLAMA_MODEL}"
        return {
            "FAST_LLM": model, "SMART_LLM": model, "STRATEGIC_LLM": model,
            # 直接叩くときのホストはワークスペース既定の 192.168.0.3
            "OLLAMA_BASE_URL": os.environ.get(
                "KURL2GR_OLLAMA_BASE_URL", "http://192.168.0.3:11434"),
            "EMBEDDING": "ollama:nomic-embed-text",
        }
    model = f"openai:{config.DEEPSEEK_MODEL}"
    return {
        "FAST_LLM": model, "SMART_LLM": model, "STRATEGIC_LLM": model,
        "OPENAI_BASE_URL": config.DEEPSEEK_BASE_URL.rstrip("/") + "/v1",
        "OPENAI_API_KEY": config.DEEPSEEK_API_KEY,
    }


async def run_research_via_queue(site: dict, keyword: str, owner: str, *, paid: bool) -> dict:
    """調査まるごと1件を rqdb4ai の1ジョブとして投げる。

    gpt-researcher は内部で多数のLLM呼び出しを行うため、呼び出しごとに
    キューへ載せると終わらない。調査単位でキューに載せることで、
    ホスト別キューによるGPU直列化(rqdb4aiの目的)を保つ。
    """
    import httpx

    headers = {"Authorization": f"Bearer {config.RQDB4AI_TOKEN}"}
    payload = {
        "function": config.RQDB4AI_RESEARCH_FUNCTION,
        "queue": "auto",
        "kwargs": {"site": site, "keyword": keyword, "owner": owner, "paid": paid},
        "meta": {"ollama_host": "192.168.0.14", "source": "web_online"},
    }
    async with httpx.AsyncClient(timeout=60) as client:
        response = await client.post(
            f"{config.RQDB4AI_URL}/api/enqueue", json=payload, headers=headers)
        response.raise_for_status()
        body = response.json()
        job_id = body.get("id") or body.get("job_id")
        if not job_id:
            raise RuntimeError("rqdb4ai がジョブIDを返しませんでした")

        import asyncio as _asyncio
        import time as _time

        deadline = _time.time() + config.RQDB4AI_RESEARCH_TIMEOUT
        while _time.time() < deadline:
            await _asyncio.sleep(config.RQDB4AI_POLL_INTERVAL)
            job = (await client.get(
                f"{config.RQDB4AI_URL}/api/jobs/{job_id}", headers=headers)).json()
            status = str(job.get("status") or "")
            if status == "finished":
                return job.get("result") or {}
            if status in {"failed", "canceled", "stopped"}:
                raise RuntimeError(f"リサーチジョブが {status}: {job.get('error') or ''}")
    raise RuntimeError("リサーチジョブの待機がタイムアウトしました")


async def run_research(site: dict, keyword: str, owner: str, *, paid: bool) -> dict:
    """調査を実行して、レポート本文と出典を返す。

    rqdb4ai が設定されていればキュー経由(既定)。未設定ならこのプロセスで直接
    実行する(ローカル開発と、ワーカー内から呼ばれたときの経路)。
    """
    if config.RQDB4AI_URL and config.RQDB4AI_TOKEN and not os.environ.get("KURL2GR_IN_WORKER"):
        return await run_research_via_queue(site, keyword, owner, paid=paid)
    return await _run_research_local(site, keyword, owner, paid=paid)


async def _run_research_local(site: dict, keyword: str, owner: str, *, paid: bool) -> dict:
    """このプロセスで gpt-researcher を実行する。"""
    if str(VENDOR) not in sys.path:
        sys.path.insert(0, str(VENDOR))
    saved = {k: os.environ.get(k) for k in
             ("FAST_LLM", "SMART_LLM", "STRATEGIC_LLM", "OLLAMA_BASE_URL",
              "OPENAI_BASE_URL", "OPENAI_API_KEY", "EMBEDDING", "RETRIEVER")}
    try:
        os.environ.update(_llm_env(owner, paid=paid))
        # キー不要で動く検索を既定にする（有料APIを無料枠の原価にしない）
        os.environ.setdefault("RETRIEVER", os.environ.get("KURL2GR_RETRIEVER", "duckduckgo"))

        from gpt_researcher import GPTResearcher  # noqa: E402  vendor をパスに入れた後

        query = query_for(site, keyword)
        researcher = GPTResearcher(query=query, report_type="research_report")
        await researcher.conduct_research()
        report = await researcher.write_report()
        sources = []
        for getter in ("get_source_urls", "get_research_sources"):
            if hasattr(researcher, getter):
                try:
                    value = getattr(researcher, getter)()
                    if isinstance(value, list):
                        sources = [str(v) for v in value][:10]
                        break
                except Exception:  # noqa: BLE001  出典が取れなくても本文は返す
                    continue
        return {"query": query, "report": str(report), "sources": sources}
    finally:
        for key, value in saved.items():
            if value is None:
                os.environ.pop(key, None)
            else:
                os.environ[key] = value


def post_messages(site: dict, research: dict) -> list[dict[str, str]]:
    """レポート調の出力を、そのまま読める投稿に整える。"""
    return [
        {
            "role": "system",
            "content": (
                "あなたは企業サイトの新着ブログを書く編集者です。"
                "与えられた調査結果だけを根拠に、日本語のブログ記事を書いてください。\n"
                "・1行目に見出しを書き、2行目は空ける\n"
                "・本文は600〜1000字。事実と出典に基づき、推測で補わない\n"
                "・調査結果に無い数字や固有名詞を作らない\n"
                "・末尾に「参考:」として出典URLを最大3件\n"
                "見出しと本文だけを出力し、前置きや説明は書かないこと。"
            ),
        },
        {
            "role": "user",
            "content": (
                f"サイト: {site.get('name') or site.get('url')}\n"
                f"事業内容: {site.get('summary') or ''}\n\n"
                f"--- 調査結果 ---\n{research['report'][:8000]}\n\n"
                f"--- 出典 ---\n" + "\n".join(research.get("sources") or [])
            ),
        },
    ]


def split_title(text: str) -> tuple[str, str]:
    """1行目を見出し、残りを本文として切り出す。"""
    body = llm.strip_fence(text).strip()
    lines = [l for l in body.split("\n")]
    title = ""
    for index, line in enumerate(lines):
        stripped = line.strip().lstrip("#").strip()
        if stripped:
            title = stripped
            body = "\n".join(lines[index + 1:]).strip()
            break
    return title[:120], body[:MAX_POST_CHARS]


def slugify(title: str) -> str:
    """URLに載せる識別子。日本語はそのまま残す（既存SNSの流儀に合わせる）。"""
    slug = re.sub(r"\s+", "-", title.strip().lower())
    slug = re.sub(r"[^0-9a-z぀-ヿ㐀-鿿\-]", "", slug)
    return slug.strip("-")[:80]
