"""kurl2gr のテスト。

顧客サイトに置く sns.php は site_key だけで投稿を読む。鍵の取り違えで
他社の記事が顧客サイトに出ると事故なので、テナント分離を最優先で固める。
"""

from __future__ import annotations

import asyncio
import os
import tempfile
import time

import pytest
from fastapi.testclient import TestClient

TMP = os.path.join(tempfile.mkdtemp(prefix="kurl2gr-test-"), "t.db")
os.environ["KURL2GR_DB"] = TMP
os.environ["KURL2GR_INTERNAL_TOKEN"] = "tok"

from app import config, db, main  # noqa: E402

HEAD_A = {"X-KURL2GR-Token": "tok", "X-KURL2GR-User": "alice"}
HEAD_B = {"X-KURL2GR-Token": "tok", "X-KURL2GR-User": "bob"}
HEAD_ADMIN = {"X-KURL2GR-Token": "tok", "X-KURL2GR-User": "xb_bittensor"}


@pytest.fixture()
def client(monkeypatch):
    if os.path.exists(TMP):
        os.remove(TMP)
    monkeypatch.setattr(config, "INTERNAL_TOKEN", "tok")
    monkeypatch.setattr(config, "ADMIN_USERS", {"xb_bittensor"})
    monkeypatch.setattr(config, "FREE_RESEARCHES_PER_MONTH", 3)
    # 外部へは出さない
    monkeypatch.setattr("app.main.site_service.validate_target", lambda u: u)
    with TestClient(main.app) as c:
        yield c


def make_site(client, headers=HEAD_A, url="https://example.com/"):
    res = client.post("/api/sites", json={"url": url, "name": "テスト社"}, headers=headers)
    assert res.status_code == 200, res.text
    return res.json()


class TestAuth:
    def test_token_required(self, client):
        assert client.get("/api/sites").status_code == 401

    def test_admin_can_act_as_another_user(self, client):
        make_site(client, HEAD_A)
        res = client.get("/api/sites", headers={**HEAD_ADMIN, "X-KURL2GR-Act-As": "alice"})
        assert res.status_code == 200 and len(res.json()) == 1

    def test_general_user_cannot_impersonate(self, client):
        res = client.get("/api/sites", headers={**HEAD_B, "X-KURL2GR-Act-As": "alice"})
        assert res.status_code == 403

    def test_admin_users_requires_admin(self, client):
        assert client.get("/api/admin/users", headers=HEAD_B).status_code == 403
        assert client.get("/api/admin/users", headers=HEAD_ADMIN).status_code == 200


class TestTenantIsolation:
    """他人のデータが混ざらないこと。ここが壊れると顧客サイトに他社の記事が出る。"""

    def test_sites_are_per_owner(self, client):
        make_site(client, HEAD_A)
        assert client.get("/api/sites", headers=HEAD_B).json() == []

    def test_cannot_read_another_owners_site(self, client):
        site = make_site(client, HEAD_A)
        res = client.get(f"/api/sites/{site['id']}/posts", headers=HEAD_B)
        assert res.status_code == 404

    def test_site_key_returns_only_that_site(self, client):
        a = make_site(client, HEAD_A, "https://a.example/")
        b = make_site(client, HEAD_B, "https://b.example/")
        client.post(f"/api/sites/{a['id']}/posts",
                    json={"title": "Aの記事", "content": "A社の本文"}, headers=HEAD_A)
        client.post(f"/api/sites/{b['id']}/posts",
                    json={"title": "Bの記事", "content": "B社の本文"}, headers=HEAD_B)

        out = client.get("/public/posts", params={"key": a["site_key"]}).json()
        titles = [i["title"] for i in out["items"]]
        assert titles == ["Aの記事"]
        assert out["site"]["url"] == "https://a.example/"

    def test_site_keys_are_unique(self, client):
        a = make_site(client, HEAD_A, "https://a.example/")
        b = make_site(client, HEAD_B, "https://b.example/")
        assert a["site_key"] != b["site_key"]

    def test_unknown_key_is_404(self, client):
        assert client.get("/public/posts", params={"key": "x" * 20}).status_code == 404


class TestPosts:
    def test_manual_post_uses_research_author(self, client):
        """顧客サイトに出る著者名は gptresearcher で統一する。"""
        site = make_site(client)
        res = client.post(f"/api/sites/{site['id']}/posts",
                          json={"content": "見出し\n\n本文"}, headers=HEAD_A)
        assert res.json()["author"] == config.RESEARCH_AUTHOR
        assert res.json()["source"] == "manual"

    def test_title_falls_back_to_first_line(self, client):
        site = make_site(client)
        res = client.post(f"/api/sites/{site['id']}/posts",
                          json={"content": "1行目が見出し\n\n本文です"}, headers=HEAD_A)
        assert res.json()["title"] == "1行目が見出し"

    def test_unpublished_post_is_hidden_from_customer_site(self, client):
        """非公開にした記事が顧客サイトに出てはいけない。"""
        site = make_site(client)
        post = client.post(f"/api/sites/{site['id']}/posts",
                           json={"content": "下書き"}, headers=HEAD_A).json()
        client.post(f"/api/posts/{post['id']}/publish", params={"published": False},
                    headers=HEAD_A)
        out = client.get("/public/posts", params={"key": site["site_key"]}).json()
        assert out["items"] == []
        # 管理側では見える
        assert len(client.get(f"/api/sites/{site['id']}/posts", headers=HEAD_A).json()) == 1

    def test_cannot_unpublish_another_owners_post(self, client):
        site = make_site(client, HEAD_A)
        post = client.post(f"/api/sites/{site['id']}/posts",
                           json={"content": "A社"}, headers=HEAD_A).json()
        assert client.post(f"/api/posts/{post['id']}/publish", params={"published": False},
                           headers=HEAD_B).status_code == 404

    def test_view_count_increments(self, client):
        site = make_site(client)
        post = client.post(f"/api/sites/{site['id']}/posts",
                           json={"content": "本文"}, headers=HEAD_A).json()
        client.post(f"/public/posts/{post['id']}/view", params={"key": site["site_key"]})
        out = client.get("/public/posts", params={"key": site["site_key"]}).json()
        assert out["items"][0]["views"] == 1


class TestQuota:
    def test_free_quota_boundary(self, client, monkeypatch):
        """無料枠内はローカルGemma、超えたらDeepSeek。"""
        assert main.within_free_quota("alice") is True
        for _ in range(3):
            db.add_usage("alice", "research")
        assert main.within_free_quota("alice") is False

    def test_admin_is_always_free(self, client):
        for _ in range(99):
            db.add_usage("xb_bittensor", "research")
        assert main.within_free_quota("xb_bittensor") is True

    def test_usage_endpoint_reports_next_run(self, client):
        out = client.get("/api/usage", headers=HEAD_A).json()
        assert out["next_is_free"] is True
        assert out["researches_limit"] == 3


class TestResearchGuards:
    def test_research_requires_overview(self, client):
        """概要が無いまま調べると、サイトと無関係な記事が出る。"""
        site = make_site(client)
        res = client.post(f"/api/sites/{site['id']}/research", json={}, headers=HEAD_A)
        assert res.status_code == 400
        assert "概要" in res.json()["detail"]

    def test_research_returns_job_and_is_polled(self, client, monkeypatch):
        """調査は非同期。POSTはジョブを返し、状態はGETで取りに行く。

        同期で返すとPHPゲートウェイ(heteml)が先に切れ、成功しても画面が失敗に見える。
        """
        site = make_site(client)
        db.update_site_overview("alice", site["id"], "テスト", "テストの概要", ["ai"])

        async def fake_research(site, keyword, owner, *, paid):
            return {"query": "テストの調査", "report": "本文", "sources": ["https://example.com/"]}

        monkeypatch.setattr(main.research_service, "run_research", fake_research)
        monkeypatch.setattr(main.llm, "run", lambda *a, **k: ("見出し\n本文", "ollama", "gemma4"))

        res = client.post(f"/api/sites/{site['id']}/research", json={}, headers=HEAD_A)
        assert res.status_code == 202
        job_id = res.json()["id"]

        for _ in range(50):
            job = client.get(f"/api/research/{job_id}", headers=HEAD_A).json()
            if job["status"] != "running":
                break
            time.sleep(0.05)
        assert job["status"] == "done", job
        assert job["sources"] == 1
        posts = client.get(f"/api/sites/{site['id']}/posts", headers=HEAD_A).json()
        assert posts[0]["title"] == "見出し"
        assert posts[0]["source"] == "research"

    def test_research_failure_is_reported_on_the_job(self, client, monkeypatch):
        """失敗の理由が画面に出ないと直せない。ジョブに残す。"""
        site = make_site(client)
        db.update_site_overview("alice", site["id"], "テスト", "テストの概要", [])

        async def boom(*a, **k):
            raise RuntimeError("検索エンジンに到達できません")

        monkeypatch.setattr(main.research_service, "run_research", boom)
        job_id = client.post(
            f"/api/sites/{site['id']}/research", json={}, headers=HEAD_A
        ).json()["id"]
        for _ in range(50):
            job = client.get(f"/api/research/{job_id}", headers=HEAD_A).json()
            if job["status"] != "running":
                break
            time.sleep(0.05)
        assert job["status"] == "failed"
        assert "検索エンジン" in job["detail"]

    def test_other_owner_cannot_read_job(self, client, monkeypatch):
        site = make_site(client)
        db.update_site_overview("alice", site["id"], "テスト", "テストの概要", [])

        async def slow(*a, **k):
            await asyncio.sleep(0.3)
            return {"query": "q", "report": "r", "sources": []}

        monkeypatch.setattr(main.research_service, "run_research", slow)
        monkeypatch.setattr(main.llm, "run", lambda *a, **k: ("t\nb", "ollama", "gemma4"))
        job_id = client.post(
            f"/api/sites/{site['id']}/research", json={}, headers=HEAD_A
        ).json()["id"]
        assert client.get(f"/api/research/{job_id}", headers=HEAD_B).status_code == 404
