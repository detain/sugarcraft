#!/usr/bin/env python3
"""Parse comparison-1026 agent reports into a patch.json for apply_comparison.py.

Reads the `## Cell changes` section of each report:
    ### <ROW-ID> · <column header>
    - current: …
    - new: <glyph> text
    - detail: <glyph> — text — _Evidence:_ …
    - why: …
Column header → key by prefix (candy-code-web before candy-code).
Later reports win on (row, col) collisions; collisions are listed on stderr.

Usage: reports_to_patch.py <out.json> <report.md>...
"""
import json, re, sys

GLYPHS = ["✅", "🟡", "🔌", "🧪", "❌", "➖", "❓", "·"]


def col_key(h):
    h = h.strip().strip("*`").lower()
    if h.startswith("candy-code-web") or h in ("web", "cc-web"):
        return "web"
    if h.startswith("candy-code") or h == "cc":
        return "cc"
    if h.startswith("claude"):
        return "claude"
    if h.startswith("opencode 1") or h.startswith("opencode v1") or h == "oc1":
        return "oc1"
    if h.startswith("opencode 2") or h.startswith("opencode v2") or h == "oc2":
        return "oc2"
    if h.startswith("openclaw") or h == "claw":
        return "claw"
    return None


def strip_q(v):
    v = v.strip()
    if len(v) >= 2 and v[0] == v[-1] and v[0] in "`\"":
        v = v[1:-1]
    return v.strip()


def parse(path):
    text = open(path, encoding="utf-8").read()
    m = re.search(r"^## Cell changes.*?$", text, re.M)
    if not m:
        return [], [f"{path}: no '## Cell changes' section"]
    body = text[m.end():]
    nxt = re.search(r"^## ", body, re.M)
    if nxt:
        body = body[:nxt.start()]
    out, warn = [], []
    for blk in re.split(r"^### ", body, flags=re.M)[1:]:
        head, _, rest = blk.partition("\n")
        hm = re.match(r"\s*([A-Z]+-N?\d+)\s*[·|/—-]+\s*(.+)$", head.strip())
        if not hm:
            warn.append(f"{path}: unparsed heading {head[:70]!r}")
            continue
        rid, col = hm.group(1), col_key(hm.group(2))
        if not col:
            warn.append(f"{path}: {rid}: unknown column {hm.group(2)!r}")
            continue
        f = {}
        for line in rest.split("\n"):
            lm = re.match(r"^- \*?\*?(current|new|detail|why)\*?\*?:\s*(.*)$", line)
            if lm:
                f[lm.group(1)] = strip_q(lm.group(2))
        e = {"row": rid, "col": col, "src": path.rsplit("/", 1)[-1]}
        if f.get("current"):
            e["expect"] = f["current"][:60]
        if f.get("new"):
            e["new"] = f["new"]
            if e["new"][:1] not in GLYPHS:
                warn.append(f"{path}: {rid}/{col}: new lacks glyph: {e['new'][:40]!r}")
        if f.get("detail"):
            d = f["detail"]
            if d[:1] not in GLYPHS:
                g = (f.get("new") or f.get("current") or "")[:1]
                if g in GLYPHS:
                    d = f"{g} — {d}"
                else:
                    warn.append(f"{path}: {rid}/{col}: detail lacks glyph")
            e["detail"] = d
        if "new" not in e and "detail" not in e:
            warn.append(f"{path}: {rid}/{col}: no new/detail")
            continue
        e["why"] = f.get("why", "")
        out.append(e)
    return out, warn


def main():
    outp, reports = sys.argv[1], sys.argv[2:]
    merged, seen = [], {}
    for r in reports:
        cells, warn = parse(r)
        for w in warn:
            sys.stderr.write("WARN " + w + "\n")
        for c in cells:
            k = (c["row"], c["col"])
            if k in seen:
                sys.stderr.write(f"COLLIDE {k}: {merged[seen[k]]['src']} vs {c['src']}\n")
                merged[seen[k]] = c
            else:
                seen[k] = len(merged)
                merged.append(c)
    json.dump({"cells": merged}, open(outp, "w", encoding="utf-8"), ensure_ascii=False, indent=1)
    by = {}
    for c in merged:
        by[c["src"]] = by.get(c["src"], 0) + 1
    print(f"{len(merged)} cell edits -> {outp}: {by}")


if __name__ == "__main__":
    main()
