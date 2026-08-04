"""Kurage URL2GPTResearcher — 顧客サイトの概要に沿った新着記事を自動で増やす。

日本の企業サイトの多くは更新が止まっており、AIにも人にも「死んだサイト」に
見える。既存サイトの内容に沿った新着ニュースを継続的に足すことで、
そこを解く。記事は顧客サイトに置いた sns.php（表示専用）に出る。

認証・テナント分離・LLMの出し分けは kgeo と同じ型。
"""

from __future__ import annotations

import asyncio
import hmac
import logging
from contextlib import asynccontextmanager
from datetime import datetime, timezone

from fastapi import Depends, FastAPI, Header, HTTPException, Query
from fastapi.responses import FileResponse
from fastapi.staticfiles import StaticFiles
from pydantic import BaseModel, Field

from . import config, db, llm, research_service, site_service

__version__ = "0.1.0"
logger = logging.getLogger("kurl2gr")
research_locks: dict[str, asyncio.Lock] = {}


@asynccontextmanager
async def lifespan(_: FastAPI):
    db.init_db()
    yield


app = FastAPI(title="Kurage URL2GPTResearcher", version=__version__, lifespan=lifespan)
if config.STATIC_DIR.exists():
    app.mount("/static", StaticFiles(directory=config.STATIC_DIR), name="static")


# --- 認証（kgeoと同型。管理者だけ代理操作できる）---------------------------

def _valid_username(value: str) -> bool:
    return bool(value) and len(value) <= 200 and not any(ord(c) < 32 for c in value)


def is_admin(username: str) -> bool:
    return username.strip().lstrip("@").lower() in config.ADMIN_USERS


def authenticated_owner(
    x_kurl2gr_token: str = Header(default=""),
    x_kurl2gr_user: str = Header(default=""),
    x_kurl2gr_act_as: str = Header(default=""),
) -> str:
    if not config.INTERNAL_TOKEN:
        return config.DEV_USER
    if not x_kurl2gr_token or not hmac.compare_digest(x_kurl2gr_token, config.INTERNAL_TOKEN):
        raise HTTPException(status_code=401, detail="Invalid internal token")
    owner = x_kurl2gr_user.strip()
    if not _valid_username(owner):
        raise HTTPException(status_code=401, detail="Authenticated user is required")
    act_as = x_kurl2gr_act_as.strip()
    if not act_as or act_as == owner:
        return owner
    if not is_admin(owner):
        raise HTTPException(status_code=403, detail="代理操作は管理者のみ利用できます")
    if not _valid_username(act_as):
        raise HTTPException(status_code=400, detail="代理操作の対象ユーザーが不正です")
    logger.info("admin %s acting as %s", owner, act_as)
    return act_as


def within_free_quota(owner: str) -> bool:
    """この1回が無料枠に収まるか。収まればローカルGemma、超えたらDeepSeek。"""
    if is_admin(owner):
        return True
    month = datetime.now(timezone.utc).strftime("%Y-%m")
    return db.monthly_usage(owner, "research", month) < config.FREE_RESEARCHES_PER_MONTH


def require_site(owner: str, site_id: str) -> dict:
    site = db.get_site(owner, site_id)
    if not site:
        raise HTTPException(status_code=404, detail="サイトが見つかりません")
    return site


# --- 入力モデル -------------------------------------------------------------

class SiteCreate(BaseModel):
    url: str = Field(min_length=8, max_length=500)
    name: str = Field(default="", max_length=120)


class ResearchRequest(BaseModel):
    keyword: str = Field(default="", max_length=100)
    publish: bool = True


class PostCreate(BaseModel):
    """管理画面からの手動投稿。顧客サイト側には投稿権限を置かない。"""

    title: str = Field(default="", max_length=120)
    content: str = Field(min_length=1, max_length=8000)


# --- 画面・状態 -------------------------------------------------------------

@app.get("/", response_class=FileResponse)
def index() -> FileResponse:
    return FileResponse(config.STATIC_DIR / "index.html")


@app.get("/health")
def health() -> dict:
    return {"ok": True, "service": "kurl2gr", "version": __version__}


@app.get("/api/usage")
def usage(owner: str = Depends(authenticated_owner)) -> dict:
    month = datetime.now(timezone.utc).strftime("%Y-%m")
    used = db.monthly_usage(owner, "research", month)
    free = within_free_quota(owner)
    return {
        "month": month,
        "researches_used": used,
        "researches_limit": None if is_admin(owner) else config.FREE_RESEARCHES_PER_MONTH,
        "next_is_free": free,
        "llm_configured": llm.configured(owner, paid=not free),
        "plan": "admin" if is_admin(owner) else "free",
    }


@app.get("/api/admin/users")
def admin_users(
    x_kurl2gr_token: str = Header(default=""),
    x_kurl2gr_user: str = Header(default=""),
) -> dict:
    if config.INTERNAL_TOKEN:
        if not x_kurl2gr_token or not hmac.compare_digest(x_kurl2gr_token, config.INTERNAL_TOKEN):
            raise HTTPException(status_code=401, detail="Invalid internal token")
        if not is_admin(x_kurl2gr_user.strip()):
            raise HTTPException(status_code=403, detail="管理者のみ利用できます")
    return {"users": db.list_owners()}


# --- サイト -----------------------------------------------------------------

@app.get("/api/sites")
def sites(owner: str = Depends(authenticated_owner)) -> list[dict]:
    return db.list_sites(owner)


@app.post("/api/sites")
def new_site(payload: SiteCreate, owner: str = Depends(authenticated_owner)) -> dict:
    try:
        url = site_service.validate_target(payload.url)
    except ValueError as exc:
        raise HTTPException(status_code=400, detail=str(exc)) from exc
    return db.create_site(owner, url, payload.name.strip())


@app.post("/api/sites/{site_id}/overview")
async def build_overview(site_id: str, owner: str = Depends(authenticated_owner)) -> dict:
    """サイトをクロールして概要を作る。以降のリサーチの土台になる。"""
    site = require_site(owner, site_id)
    free = within_free_quota(owner)
    try:
        context = await asyncio.to_thread(site_service.fetch_context, site["url"])
        text, provider, model = await asyncio.to_thread(
            llm.run, site_service.overview_messages(context), owner, paid=not free
        )
        parsed = llm.parse_json(text)
    except (ValueError, RuntimeError, KeyError) as exc:
        raise HTTPException(status_code=503, detail=str(exc)) from exc
    topics = [str(t).strip() for t in (parsed.get("topics") or []) if str(t).strip()][:5]
    db.update_site_overview(
        owner, site_id, str(parsed.get("name") or site["name"])[:120],
        str(parsed.get("summary") or "")[:400], topics,
    )
    return {**require_site(owner, site_id), "provider": provider, "model": model}


# --- リサーチ ---------------------------------------------------------------

@app.post("/api/sites/{site_id}/research")
async def run_research(
    site_id: str, payload: ResearchRequest, owner: str = Depends(authenticated_owner)
) -> dict:
    """概要（＋キーワード）で調べ、記事にして投稿する。"""
    site = require_site(owner, site_id)
    if not (site.get("summary") or "").strip():
        raise HTTPException(status_code=400, detail="先にサイト概要を作成してください")
    lock = research_locks.setdefault(owner, asyncio.Lock())
    async with lock:
        free = within_free_quota(owner)
        try:
            research = await research_service.run_research(
                site, payload.keyword, owner, paid=not free
            )
            text, provider, model = await asyncio.to_thread(
                llm.run, research_service.post_messages(site, research), owner, paid=not free
            )
        except (RuntimeError, ValueError, KeyError, ImportError) as exc:
            raise HTTPException(status_code=503, detail=str(exc)) from exc
        title, body = research_service.split_title(text)
        post = db.add_post(
            site_id, owner, config.RESEARCH_AUTHOR, title, body,
            slug=research_service.slugify(title), source="research",
            keyword=payload.keyword.strip(), provider=provider, model=model,
        )
        if not payload.publish:
            db.set_published(owner, int(post["id"]), False)
            post["published"] = 0
        db.add_usage(owner, "research", str(post["id"]))
        return {"post": post, "query": research["query"], "sources": research.get("sources") or []}


# --- 投稿 -------------------------------------------------------------------

@app.get("/api/sites/{site_id}/posts")
def posts(
    site_id: str,
    limit: int = Query(default=50, le=200),
    owner: str = Depends(authenticated_owner),
) -> list[dict]:
    require_site(owner, site_id)
    return db.list_posts(site_id, limit=limit, published_only=False)


@app.post("/api/sites/{site_id}/posts")
def new_post(site_id: str, payload: PostCreate, owner: str = Depends(authenticated_owner)) -> dict:
    """手動投稿。顧客サイトの sns.php は表示専用なので、投稿はここから行う。"""
    require_site(owner, site_id)
    title = payload.title.strip() or payload.content.strip().split("\n", 1)[0][:120]
    return db.add_post(
        site_id, owner, config.RESEARCH_AUTHOR, title, payload.content.strip(),
        slug=research_service.slugify(title), source="manual",
    )


@app.post("/api/posts/{post_id}/publish")
def set_publish(
    post_id: int,
    published: bool = Query(default=True),
    owner: str = Depends(authenticated_owner),
) -> dict:
    if not db.set_published(owner, post_id, published):
        raise HTTPException(status_code=404, detail="投稿が見つかりません")
    return {"ok": True, "id": post_id, "published": published}


# --- 顧客サイトの sns.php が読む公開API ------------------------------------

@app.get("/public/posts")
def public_posts(
    key: str = Query(..., min_length=8),
    limit: int = Query(default=20, le=100),
    offset: int = Query(default=0, ge=0),
) -> dict:
    """site_key で自分の投稿だけを返す。既存AIxSNSの応答形に合わせる。

    顧客サイトに置くのは表示専用の1ファイルだけで、DBもバックエンドも要らない。
    鍵は顧客が発行して sns.php に貼るので、こちらは顧客サーバーへ触れない。
    """
    site = db.get_site_by_key(key)
    if not site:
        raise HTTPException(status_code=404, detail="不明なサイトキーです")
    rows = db.list_posts(site["id"], limit=limit, offset=offset, published_only=True)
    return {
        "ok": True,
        "site": {"name": site["name"], "url": site["url"], "summary": site["summary"]},
        "items": [
            {
                "id": r["id"], "title": r["title"], "content": r["content"],
                "author": r["author"], "slug": r["slug"], "views": r["views"],
                "created_at": r["created_at"], "updated_at": r["updated_at"],
            }
            for r in rows
        ],
    }


@app.post("/public/posts/{post_id}/view")
def public_view(post_id: int, key: str = Query(..., min_length=8)) -> dict:
    site = db.get_site_by_key(key)
    if not site or not db.get_post(site["id"], post_id):
        raise HTTPException(status_code=404, detail="投稿が見つかりません")
    db.bump_views(post_id)
    return {"ok": True}
