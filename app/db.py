"""SQLite永続化層。テナント(Xアカウント)ごとにデータを分離する。

顧客サイトへ置く sns.php は表示専用で、投稿の実体はここにある。
顧客サーバーにDBを置かないので、設置は1ファイルで済み、更新もこちらで完結する。
"""

from __future__ import annotations

import secrets
import sqlite3
from contextlib import contextmanager
from datetime import datetime, timezone
from pathlib import Path
from typing import Iterator

from .config import DB_PATH

SCHEMA = """
CREATE TABLE IF NOT EXISTS sites (
    id          TEXT PRIMARY KEY,
    owner       TEXT NOT NULL,
    url         TEXT NOT NULL,
    name        TEXT NOT NULL DEFAULT '',
    summary     TEXT NOT NULL DEFAULT '',
    topics      TEXT NOT NULL DEFAULT '',   -- カンマ区切り。リサーチの足がかり
    -- 顧客サイトの sns.php がこの鍵で自分の投稿だけを読む
    site_key    TEXT NOT NULL UNIQUE,
    created_at  TEXT NOT NULL,
    updated_at  TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS posts (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id     TEXT NOT NULL REFERENCES sites(id),
    owner       TEXT NOT NULL,
    author      TEXT NOT NULL,
    title       TEXT NOT NULL DEFAULT '',
    content     TEXT NOT NULL,
    slug        TEXT NOT NULL DEFAULT '',
    source      TEXT NOT NULL DEFAULT 'research',  -- research | manual
    keyword     TEXT NOT NULL DEFAULT '',
    provider    TEXT NOT NULL DEFAULT '',
    model       TEXT NOT NULL DEFAULT '',
    published   INTEGER NOT NULL DEFAULT 1,
    views       INTEGER NOT NULL DEFAULT 0,
    created_at  TEXT NOT NULL,
    updated_at  TEXT NOT NULL
);

-- リサーチは数分かかる。PHPゲートウェイに待たせるとheteml側で切れるため、
-- 実行状態をここに置いて画面から進捗を取りに来る形にする。
CREATE TABLE IF NOT EXISTS research_jobs (
    id          TEXT PRIMARY KEY,
    site_id     TEXT NOT NULL REFERENCES sites(id),
    owner       TEXT NOT NULL,
    keyword     TEXT NOT NULL DEFAULT '',
    status      TEXT NOT NULL DEFAULT 'running',  -- running | done | failed
    detail      TEXT NOT NULL DEFAULT '',
    query       TEXT NOT NULL DEFAULT '',
    sources     INTEGER NOT NULL DEFAULT 0,
    post_id     INTEGER,
    created_at  TEXT NOT NULL,
    updated_at  TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS usage_events (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    owner       TEXT NOT NULL,
    kind        TEXT NOT NULL,
    ref_id      TEXT NOT NULL DEFAULT '',
    created_at  TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_posts_site ON posts(site_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_sites_owner ON sites(owner, updated_at DESC);
CREATE INDEX IF NOT EXISTS idx_usage_owner ON usage_events(owner, kind, created_at);
"""


def now_iso() -> str:
    return datetime.now(timezone.utc).isoformat(timespec="seconds")


def new_id() -> str:
    return secrets.token_hex(6)


def new_site_key() -> str:
    return secrets.token_urlsafe(24)


@contextmanager
def connect(db_path: Path | None = None) -> Iterator[sqlite3.Connection]:
    path = Path(db_path or DB_PATH)
    path.parent.mkdir(parents=True, exist_ok=True)
    conn = sqlite3.connect(path)
    conn.row_factory = sqlite3.Row
    conn.execute("PRAGMA foreign_keys = ON")
    try:
        yield conn
        conn.commit()
    finally:
        conn.close()


def init_db(db_path: Path | None = None) -> None:
    with connect(db_path) as conn:
        conn.executescript(SCHEMA)


def create_site(owner: str, url: str, name: str = "") -> dict:
    site_id = new_id()
    stamp = now_iso()
    with connect() as conn:
        conn.execute(
            "INSERT INTO sites (id, owner, url, name, site_key, created_at, updated_at)"
            " VALUES (?,?,?,?,?,?,?)",
            (site_id, owner, url, name, new_site_key(), stamp, stamp),
        )
    return get_site(owner, site_id)


def list_sites(owner: str) -> list[dict]:
    with connect() as conn:
        rows = conn.execute(
            "SELECT * FROM sites WHERE owner = ? ORDER BY updated_at DESC", (owner,)
        ).fetchall()
    return [dict(r) for r in rows]


def get_site(owner: str, site_id: str) -> dict | None:
    with connect() as conn:
        row = conn.execute(
            "SELECT * FROM sites WHERE owner = ? AND id = ?", (owner, site_id)
        ).fetchone()
    return dict(row) if row else None


def get_site_by_key(site_key: str) -> dict | None:
    """顧客サイトの sns.php からの参照用。鍵だけで引く(オーナー指定は無い)。"""
    with connect() as conn:
        row = conn.execute("SELECT * FROM sites WHERE site_key = ?", (site_key,)).fetchone()
    return dict(row) if row else None


def update_site_overview(owner: str, site_id: str, name: str, summary: str, topics: list[str]) -> None:
    with connect() as conn:
        conn.execute(
            "UPDATE sites SET name = ?, summary = ?, topics = ?, updated_at = ?"
            " WHERE owner = ? AND id = ?",
            (name, summary, ",".join(topics), now_iso(), owner, site_id),
        )


def add_post(site_id: str, owner: str, author: str, title: str, content: str,
             slug: str = "", source: str = "research", keyword: str = "",
             provider: str = "", model: str = "") -> dict:
    stamp = now_iso()
    with connect() as conn:
        cur = conn.execute(
            "INSERT INTO posts (site_id, owner, author, title, content, slug, source,"
            " keyword, provider, model, created_at, updated_at)"
            " VALUES (?,?,?,?,?,?,?,?,?,?,?,?)",
            (site_id, owner, author, title, content, slug, source, keyword,
             provider, model, stamp, stamp),
        )
        post_id = int(cur.lastrowid)
        row = conn.execute("SELECT * FROM posts WHERE id = ?", (post_id,)).fetchone()
    return dict(row)


def list_posts(site_id: str, limit: int = 50, offset: int = 0,
               published_only: bool = True) -> list[dict]:
    sql = "SELECT * FROM posts WHERE site_id = ?"
    params: list = [site_id]
    if published_only:
        sql += " AND published = 1"
    sql += " ORDER BY created_at DESC, id DESC LIMIT ? OFFSET ?"
    params += [limit, offset]
    with connect() as conn:
        rows = conn.execute(sql, tuple(params)).fetchall()
    return [dict(r) for r in rows]


def get_post(site_id: str, post_id: int) -> dict | None:
    with connect() as conn:
        row = conn.execute(
            "SELECT * FROM posts WHERE site_id = ? AND id = ?", (site_id, post_id)
        ).fetchone()
    return dict(row) if row else None


def bump_views(post_id: int) -> None:
    with connect() as conn:
        conn.execute("UPDATE posts SET views = views + 1 WHERE id = ?", (post_id,))


def set_published(owner: str, post_id: int, published: bool) -> bool:
    with connect() as conn:
        cur = conn.execute(
            "UPDATE posts SET published = ?, updated_at = ? WHERE owner = ? AND id = ?",
            (1 if published else 0, now_iso(), owner, post_id),
        )
    return cur.rowcount > 0


def create_job(owner: str, site_id: str, keyword: str) -> dict:
    stamp = now_iso()
    job_id = new_id()
    with connect() as conn:
        conn.execute(
            "INSERT INTO research_jobs (id, site_id, owner, keyword, status, created_at, updated_at)"
            " VALUES (?,?,?,?,'running',?,?)",
            (job_id, site_id, owner, keyword, stamp, stamp),
        )
        row = conn.execute("SELECT * FROM research_jobs WHERE id = ?", (job_id,)).fetchone()
    return dict(row)


def finish_job(job_id: str, status: str, *, detail: str = "", query: str = "",
               sources: int = 0, post_id: int | None = None) -> None:
    with connect() as conn:
        conn.execute(
            "UPDATE research_jobs SET status = ?, detail = ?, query = ?, sources = ?,"
            " post_id = ?, updated_at = ? WHERE id = ?",
            (status, detail[:500], query[:500], sources, post_id, now_iso(), job_id),
        )


def get_job(owner: str, job_id: str) -> dict | None:
    with connect() as conn:
        row = conn.execute(
            "SELECT * FROM research_jobs WHERE owner = ? AND id = ?", (owner, job_id)
        ).fetchone()
    return dict(row) if row else None


def running_job(owner: str, site_id: str) -> dict | None:
    """実行中のジョブ。画面を開き直しても進捗を追えるようにする。"""
    with connect() as conn:
        row = conn.execute(
            "SELECT * FROM research_jobs WHERE owner = ? AND site_id = ? AND status = 'running'"
            " ORDER BY created_at DESC LIMIT 1",
            (owner, site_id),
        ).fetchone()
    return dict(row) if row else None


def add_usage(owner: str, kind: str, ref_id: str = "") -> None:
    with connect() as conn:
        conn.execute(
            "INSERT INTO usage_events (owner, kind, ref_id, created_at) VALUES (?,?,?,?)",
            (owner, kind, ref_id, now_iso()),
        )


def monthly_usage(owner: str, kind: str, month: str) -> int:
    with connect() as conn:
        row = conn.execute(
            "SELECT COUNT(*) c FROM usage_events"
            " WHERE owner = ? AND kind = ? AND substr(created_at, 1, 7) = ?",
            (owner, kind, month),
        ).fetchone()
    return int(row["c"])


def list_owners() -> list[dict]:
    """管理者の代理操作で対象を選ぶための一覧(kgeoと同型)。"""
    with connect() as conn:
        rows = conn.execute(
            "SELECT owner, COUNT(*) sites, MAX(updated_at) last_seen"
            " FROM sites GROUP BY owner ORDER BY last_seen DESC"
        ).fetchall()
    return [dict(r) for r in rows]
