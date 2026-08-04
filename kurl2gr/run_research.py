"""リサーチ実行の子プロセス入口（.venv-gr / Python 3.12 で動く）。

標準入力にJSONを受け、**argv[1] のファイル**にJSONを書く。
gpt-researcher は進捗を標準出力へ流すため、stdoutで結果を返すと混ざる。
共有ワーカーのPythonと依存を分離するために、あえてプロセスを分けている。
"""

from __future__ import annotations

import asyncio
import json
import sys


def main() -> int:
    if len(sys.argv) < 2:
        print("出力先のファイルパスを指定してください", file=sys.stderr)
        return 2
    out_path = sys.argv[1]
    request = json.load(sys.stdin)
    from app import research_service   # PYTHONPATH にリポジトリ直下が入っている前提

    result = asyncio.run(
        research_service.run_research(
            request["site"], request.get("keyword", ""),
            request.get("owner", "worker"), paid=bool(request.get("paid")),
        )
    )
    payload = {
        "query": str(result.get("query") or ""),
        "report": str(result.get("report") or ""),
        "sources": [str(s) for s in (result.get("sources") or [])][:10],
    }
    with open(out_path, "w", encoding="utf-8") as handle:
        json.dump(payload, handle, ensure_ascii=False)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
