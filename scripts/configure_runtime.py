"""公開サーバー(heteml)へ置く kurl2gr_config.php を .env から作る。

トークンはリポジトリに置かない(.gitignore で public/*_config.php を除外済み)。
値を発明せず、必ず .env の KURL2GR_INTERNAL_TOKEN と同じものを書き出す。
バックエンドとPHP側でトークンがずれると全APIが401になり、原因が見えにくい。
"""

from __future__ import annotations

from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
ENV = ROOT / ".env"
OUT = ROOT / "public" / "kurl2gr_config.php"

# heteml から見たバックエンドの到達先。他プロダクト(ktajp/kgm/kfinanalyst)と同じ経路。
API_BASE = "http://exbridge.ddns.net:18358"


def read_env() -> dict[str, str]:
    values: dict[str, str] = {}
    for line in ENV.read_text(encoding="utf-8").splitlines():
        line = line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        values[key.strip()] = value.strip()
    return values


def main() -> int:
    env = read_env()
    token = env.get("KURL2GR_INTERNAL_TOKEN", "").strip()
    if not token:
        raise SystemExit(".env に KURL2GR_INTERNAL_TOKEN がありません")
    OUT.write_text(
        "<?php\n"
        "// scripts/configure_runtime.py が生成。手で編集しない。\n"
        f"define('KURL2GR_API_BASE', getenv('KURL2GR_API_BASE') ?: '{API_BASE}');\n"
        f"define('KURL2GR_API_TOKEN', getenv('KURL2GR_API_TOKEN') ?: '{token}');\n"
        f"define('KURL2GR_PUBLIC_BASE', getenv('KURL2GR_PUBLIC_BASE') ?: '{API_BASE}');\n",
        encoding="utf-8",
    )
    OUT.chmod(0o600)
    print(f"生成: {OUT.relative_to(ROOT)} (API_BASE={API_BASE})")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
