"""顧客サイトをクロールして概要を理解する。

「このサイトが何の会社で、どんな話題を扱っているか」を掴むのが目的。
ここで得た概要が、あとのリサーチの土台になる(概要と無関係な記事を
自動投稿しても、死んだサイトが賑やかになるだけで意味がない)。

外部URLを取りに行くので、SSRF対策として内部アドレスへの接続は拒む。
"""

from __future__ import annotations

import ipaddress
import re
import socket
from html.parser import HTMLParser
from urllib.parse import urlparse

import httpx

from .config import FETCH_TIMEOUT, SITE_CONTEXT_CHARS

MAX_BYTES = 2_000_000


class _TextExtractor(HTMLParser):
    """本文らしさを優先してテキストを取り出す。script/style は捨てる。"""

    SKIP = {"script", "style", "noscript", "svg", "template"}

    def __init__(self) -> None:
        super().__init__(convert_charrefs=True)
        self.parts: list[str] = []
        self.title = ""
        self.description = ""
        self._skip_depth = 0
        self._in_title = False

    def handle_starttag(self, tag, attrs):
        if tag in self.SKIP:
            self._skip_depth += 1
        elif tag == "title":
            self._in_title = True
        elif tag == "meta":
            data = dict(attrs)
            key = (data.get("name") or data.get("property") or "").lower()
            if key in {"description", "og:description"} and not self.description:
                self.description = (data.get("content") or "").strip()

    def handle_endtag(self, tag):
        if tag in self.SKIP and self._skip_depth:
            self._skip_depth -= 1
        elif tag == "title":
            self._in_title = False

    def handle_data(self, data):
        if self._skip_depth:
            return
        text = data.strip()
        if not text:
            return
        if self._in_title and not self.title:
            self.title = text
        elif len(text) > 1:
            self.parts.append(text)


def validate_target(url: str) -> str:
    """公開URLだけを許す。内部アドレスへ向けさせない(SSRF対策)。"""
    parsed = urlparse(url.strip())
    if parsed.scheme not in {"http", "https"} or not parsed.hostname:
        raise ValueError("http/https のURLを指定してください")
    try:
        infos = socket.getaddrinfo(parsed.hostname, None)
    except OSError as exc:
        raise ValueError(f"ホスト名を解決できません: {parsed.hostname}") from exc
    for info in infos:
        address = ipaddress.ip_address(info[4][0])
        if (address.is_private or address.is_loopback or address.is_link_local
                or address.is_reserved or address.is_multicast):
            raise ValueError("内部アドレスは指定できません")
    return url.strip()


def fetch_context(url: str) -> dict:
    """トップページの本文・タイトル・説明文を取る。"""
    target = validate_target(url)
    with httpx.Client(timeout=FETCH_TIMEOUT, follow_redirects=True,
                      headers={"User-Agent": "KurageURL2GPTResearcher/1.0"}) as client:
        response = client.get(target)
        response.raise_for_status()
        body = response.text[:MAX_BYTES]

    parser = _TextExtractor()
    parser.feed(body)
    text = re.sub(r"\s+", " ", " ".join(parser.parts)).strip()
    if not text:
        raise RuntimeError("対象ページから本文を抽出できませんでした")
    return {
        "url": target,
        "title": parser.title,
        "description": parser.description,
        "text": text[:SITE_CONTEXT_CHARS],
    }


def overview_messages(context: dict) -> list[dict[str, str]]:
    """サイト概要と話題を、機械が扱える形で出させる。"""
    return [
        {
            "role": "system",
            "content": (
                "あなたは企業サイトを読んで要点を整理する編集者です。"
                "与えられたページ本文だけを根拠に、次のJSONだけを出力してください。\n"
                '{"name":"会社・サービス名","summary":"事業内容の要約(120字以内)",'
                '"topics":["関連する話題を最大5つ"]}\n'
                "本文から読み取れないことは書かないこと。JSON以外を出力しないこと。"
            ),
        },
        {
            "role": "user",
            "content": (
                f"URL: {context['url']}\n"
                f"タイトル: {context.get('title', '')}\n"
                f"説明: {context.get('description', '')}\n\n"
                f"--- 本文 ---\n{context['text']}"
            ),
        },
    ]
