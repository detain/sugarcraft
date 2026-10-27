#!/usr/bin/env python3
"""Merge patch-<name>.json fragments into one patch for apply_comparison.py.

Fails loud on (row,col) collisions, duplicate new-row ids, and narrative
`old` strings whose spans overlap in the doc. headers.json (optional) is a
plain {old: new} map copied into the merged patch.

Usage: merge_patches.py <COMPARISON.md> <out.json> [--headers headers.json] <fragment.json>...
"""
import json, sys


def die(m):
    sys.stderr.write("FAIL: " + m + "\n")
    sys.exit(1)


def main():
    a = sys.argv[1:]
    doc, out = a[0], a[1]
    rest = a[2:]
    headers = {}
    if rest and rest[0] == "--headers":
        headers = json.load(open(rest[1], encoding="utf-8"))
        rest = rest[2:]
    text = open(doc, encoding="utf-8").read()
    cells, rows, narr = [], [], []
    owner = {}
    for f in rest:
        p = json.load(open(f, encoding="utf-8"))
        name = f.rsplit("/", 1)[-1]
        if "headers" in p:
            die(f"{name} carries a headers key")
        for c in p.get("cells", []):
            k = (c["row"], c["col"])
            if k in owner:
                die(f"cell collision {k}: {owner[k]} vs {name}")
            owner[k] = name
            cells.append(c)
        for r in p.get("new_rows", []):
            if any(x["id"] == r["id"] for x in rows):
                die(f"new-row id collision {r['id']} ({name})")
            r["_src"] = name
            rows.append(r)
        for n in p.get("narrative", []):
            n["_src"] = name
            narr.append(n)
    spans = []
    for n in narr:
        i = text.find(n["old"])
        if i < 0 or text.count(n["old"]) != 1:
            die(f"narrative old not unique/absent ({n['_src']}): {n['old'][:80]!r}")
        spans.append((i, i + len(n["old"]), n["_src"], n["old"][:60]))
    spans.sort()
    for x, y in zip(spans, spans[1:]):
        if y[0] < x[1]:
            die(f"narrative overlap: {x[2]} {x[3]!r} vs {y[2]} {y[3]!r}")
    for r in rows:
        r.pop("_src", None)
    for n in narr:
        n.pop("_src", None)
    json.dump({"headers": headers, "cells": cells, "new_rows": rows, "narrative": narr},
              open(out, "w", encoding="utf-8"), ensure_ascii=False, indent=1)
    print(f"merged {len(rest)} fragments: {len(cells)} cells, {len(rows)} new rows, {len(narr)} narrative, {len(headers)} headers -> {out}")


if __name__ == "__main__":
    main()
