#!/usr/bin/env python3
"""Mechanical, fail-loud applier for candy-code/COMPARISON.md edits.

patch.json shape:
{
  "headers": {"Claude Code 2.1.301": "Claude Code 2.1.311", ...},   # §2/§3 header cells + Appendix A bullet labels
  "cells": [ {"row": "MOD-03", "col": "cc|web|claude|oc1|oc2|claw",
              "expect": "<prefix of current cell>" (optional),
              "new": "<glyph> text" (optional; omit for detail-only),
              "detail": "<glyph> — text — _Evidence:_ ..." (optional; full bullet body after the label)} ],
  "new_rows": [ {"id": "MCP-N9", "feature": "🆕 ...", "cells": {col: "<glyph> text"},
                 "details": {col: "<glyph> — text — _Evidence:_ ..."}} ],
  "narrative": [ {"old": "<exact unique text>", "new": "<replacement>"} ]
}
Usage: apply_comparison.py <COMPARISON.md> <patch.json> [--check]   (--check = verify scoreboard only)
"""
import json, re, sys

COLS = ["cc", "web", "claude", "oc1", "oc2", "claw"]
GLYPHS = ["✅", "🟡", "🔌", "🧪", "❌", "➖", "❓", "·"]
ROW_RE = re.compile(r"^\| ([A-Z]+)-(N?)(\d+) \|")
CAT_RE = re.compile(r"^\| \*\*([A-Z]+)\*\* \|")


def die(msg):
    sys.stderr.write("FAIL: " + msg + "\n")
    sys.exit(1)


def split_row(line, n=8):
    cells = line.strip()[1:-1].split("|")
    if len(cells) != n:
        die(f"row does not have {n} cells: " + line[:80])
    return cells


def join_row(cells):
    return "|" + "|".join(cells) + "|"


def matrix_bounds(L):
    start = next(i for i, l in enumerate(L) if l.startswith("## 3. Full feature matrix"))
    end = next(i for i, l in enumerate(L) if l.startswith("## 4. "))
    return start, end


def find_row(L, rid):
    s, e = matrix_bounds(L)
    hits = [i for i in range(s, e) if L[i].startswith("| " + rid + " |")]
    if len(hits) != 1:
        die(f"row {rid}: {len(hits)} matches in §3")
    return hits[0]


def appendix_labels(L):
    s = next(i for i, l in enumerate(L) if l.startswith("<details><summary><b>"))
    e = s
    while L[e] != "</details>":
        e += 1
    labs = [re.match(r"- \*\*(.+?)\*\*", l).group(1) for l in L[s + 1:e] if l.startswith("- **")]
    if len(labs) != 6:
        die("first appendix block lacks 6 bullets")
    return labs


def find_detail(L, rid, colidx):
    hits = [i for i, l in enumerate(L) if l.startswith(f"<details><summary><b>{rid}</b>")]
    if len(hits) != 1:
        die(f"appendix block {rid}: {len(hits)} matches")
    i = hits[0]
    bullets = []
    j = i + 1
    while L[j] != "</details>":
        if L[j].startswith("- **"):
            bullets.append(j)
        j += 1
    if len(bullets) != 6:
        die(f"appendix block {rid} has {len(bullets)} bullets")
    return bullets[colidx]


def recompute_scoreboard(L, write):
    s, e = matrix_bounds(L)
    counts = {}
    order = []
    cat = None
    for i in range(s, e):
        m = CAT_RE.match(L[i])
        if m:
            cat = m.group(1)
            order.append(cat)
            counts[cat] = [dict() for _ in COLS]
            continue
        m = ROW_RE.match(L[i])
        if m and m.group(2) == "":
            if m.group(1) != cat:
                die(f"row {m.group(1)}-{m.group(3)} under category {cat}")
            cells = split_row(L[i])
            for k, c in enumerate(cells[2:]):
                g = c.strip()[:1]
                if g not in GLYPHS:
                    die(f"bad glyph in {m.group(1)}-{m.group(3)} col {COLS[k]}: {c[:30]!r}")
                counts[cat][k][g] = counts[cat][k].get(g, 0) + 1

    def fmt(d):
        return " ".join(f"{g}{d[g]}" for g in GLYPHS if d.get(g))

    sb = next(i for i, l in enumerate(L) if l.startswith("## 2. Scoreboard"))
    changed = 0
    total = [dict() for _ in COLS]
    for i in range(sb, s):
        line = L[i]
        if not line.startswith("| ") or line.startswith("| Category") or line.startswith("|---"):
            continue
        cells = split_row(line, 7)
        label = cells[0].strip()
        m = re.match(r"([A-Z]+) — ", label)
        if m:
            c = m.group(1)
            new = [" " + fmt(counts[c][k]) + " " for k in range(6)]
            for k in range(6):
                for g, n in counts[c][k].items():
                    total[k][g] = total[k].get(g, 0) + n
        elif label.startswith("**Total"):
            new = [" **" + fmt(total[k]) + "** " for k in range(6)]
        else:
            continue
        if [x.strip() for x in cells[1:7]] != [x.strip() for x in new]:
            changed += 1
            print(f"scoreboard {label[:40]!r}:")
            for k in range(6):
                if cells[1 + k].strip() != new[k].strip():
                    print(f"   {COLS[k]}: {cells[1 + k].strip()}  ->  {new[k].strip()}")
            if write:
                L[i] = join_row([cells[0]] + new)
    n_tax = sum(sum(counts[c][0].values()) for c in order)
    print(f"taxonomy rows counted: {n_tax}; scoreboard rows changed: {changed}")
    return changed


def main():
    path = sys.argv[1]
    L = open(path, encoding="utf-8").read().split("\n")
    if len(sys.argv) > 2 and sys.argv[2] == "--check":
        recompute_scoreboard(L, write=False)
        return
    patch = json.load(open(sys.argv[2], encoding="utf-8"))
    check_only = "--check" in sys.argv[3:]

    labels = appendix_labels(L)

    # 1) narrative first (exact unique matches against the pristine text)
    text = "\n".join(L)
    for n in patch.get("narrative", []):
        cnt = text.count(n["old"])
        if cnt != 1:
            die(f"narrative old text matched {cnt}x: {n['old'][:90]!r}")
        text = text.replace(n["old"], n["new"])
    L = text.split("\n")

    # 2) cells + details
    for c in patch.get("cells", []):
        k = COLS.index(c["col"])
        if "new" in c and c["new"]:
            i = find_row(L, c["row"])
            cells = split_row(L[i])
            cur = cells[2 + k].strip()
            exp = c.get("expect")
            if exp and not cur.startswith(exp.strip()[:60]):
                die(f"{c['row']}/{c['col']}: current cell does not start with expected prefix\n  cur={cur[:90]!r}\n  exp={exp[:90]!r}")
            new = c["new"].strip()
            if new[:1] not in GLYPHS:
                die(f"{c['row']}/{c['col']}: new cell lacks a leading glyph: {new[:40]!r}")
            cells[2 + k] = " " + new + " "
            L[i] = join_row(cells)
        if c.get("detail"):
            j = find_detail(L, c["row"], k)
            lab = re.match(r"- \*\*(.+?)\*\*", L[j]).group(1)
            L[j] = f"- **{lab}** {c['detail'].strip()}"

    # 3) new 🆕 rows: append after the last row of the category in §3 and after its last appendix block
    for r in patch.get("new_rows", []):
        rid = r["id"]
        cat = rid.split("-")[0]
        if any(l.startswith(f"| {rid} |") for l in L):
            die(f"new row {rid} already exists")
        s, e = matrix_bounds(L)
        last = max(i for i in range(s, e) if L[i].startswith(f"| {cat}-"))
        cells = [f" {rid} ", f" {r['feature']} "] + [" " + r["cells"].get(col, "· Not assessed").strip() + " " for col in COLS]
        L.insert(last + 1, join_row(cells))
        blocks = [i for i, l in enumerate(L) if l.startswith(f"<details><summary><b>{cat}-")]
        end = blocks[-1]
        while L[end] != "</details>":
            end += 1
        feat = r["feature"].replace("🆕 ", "")
        block = [f"<details><summary><b>{rid}</b> {feat}</summary>", ""]
        for k, col in enumerate(COLS):
            d = r.get("details", {}).get(col)
            if not d:
                d = r["cells"].get(col, "· Not assessed").strip()
            block.append(f"- **{labels[k]}** {d.strip()}")
        block += ["", "</details>"]
        L[end + 1:end + 1] = block

    # 4) header renames (§2 + §3 header rows, appendix labels) — after cell work so detail lookups used old labels by index
    for old, new in patch.get("headers", {}).items():
        hits = 0
        for i, l in enumerate(L):
            if (l.startswith("| Category |") or l.startswith("| ID | Feature |")) and old in l:
                L[i] = l.replace(old, new); hits += 1
            elif l.startswith(f"- **{old}**"):
                L[i] = l.replace(f"- **{old}**", f"- **{new}**", 1); hits += 1
        print(f"header {old!r} -> {new!r}: {hits} lines")

    recompute_scoreboard(L, write=True)
    if check_only:
        print("check only: not written")
        return
    open(path, "w", encoding="utf-8").write("\n".join(L))
    print("written", path)


if __name__ == "__main__":
    main()
