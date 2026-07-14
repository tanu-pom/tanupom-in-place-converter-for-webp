#!/usr/bin/env python3
"""WP.org 提出用 zip をビルドする。

.distignore を尊重し、プラグイン slug のディレクトリで wrap した zip を
dist/ に出力する（WP.org / WP 管理画面はこの構造を前提にする）。

`wp dist-archive` は標準の wp-cli には同梱されないため、zipfile で代替する。
"""
import pathlib
import re
import zipfile

ROOT = pathlib.Path(__file__).resolve().parent
SLUG = "tanupom-in-place-converter-for-webp"

version = re.search(
    r"^\s*\*\s*Version:\s*(\S+)", (ROOT / f"{SLUG}.php").read_text(encoding="utf-8"), re.M
).group(1)

# .distignore を読む（先頭 / は「ルート起点」、それ以外は名前マッチ）
rooted, names = set(), set()
for line in (ROOT / ".distignore").read_text(encoding="utf-8").splitlines():
    line = line.strip()
    if not line or line.startswith("#"):
        continue
    (rooted if line.startswith("/") else names).add(line.lstrip("/"))


def excluded(rel: pathlib.Path) -> bool:
    if str(rel) in rooted or rel.parts[0] in rooted:
        return True
    return any(rel.match(pat) or rel.name == pat for pat in names)


out = ROOT / "dist" / f"{SLUG}.{version}.zip"
out.parent.mkdir(exist_ok=True)

written = []
with zipfile.ZipFile(out, "w", zipfile.ZIP_DEFLATED) as z:
    for f in sorted(ROOT.rglob("*")):
        if not f.is_file():
            continue
        rel = f.relative_to(ROOT)
        if rel.parts[0] in (".git", "dist") or rel.name in ("build-zip.py",) or excluded(rel):
            continue
        z.write(f, f"{SLUG}/{rel}")
        written.append(str(rel))

print(f"✓ {out}  ({out.stat().st_size:,} bytes)")
print(f"  version {version} / {len(written)} ファイル\n")
for w in written:
    print(f"  {SLUG}/{w}")
