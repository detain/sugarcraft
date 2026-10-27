#!/usr/bin/env python3
"""Assemble plan_comparison.md = static head + generated wave sections + item index + static tail.

Usage: build_plan.py <sched.json> <head.md> <tail.md> <out.md>
"""
import json, re, sys
from collections import defaultdict

AREA_DOC = "prompt_kit/findings/comparison-1026/design-{}.md"


def paths(text, kind=None):
    """backticked repo paths; kind='create'|'modify' filters test sub-bullets by verb"""
    out = []
    if kind:
        chunks = re.split(r"(?im)^(?:-\s*)?(create|modify)\b", text)
        sel, verb = [], None
        for c in chunks:
            if c.lower() in ("create", "modify"):
                verb = c.lower()
            elif verb == kind:
                sel.append(c)
        text = "\n".join(sel)
    for tok in re.findall(r"`([^`]+)`", text):
        p = tok.strip().split(" ")[0]
        p = re.sub(r":[\d,\-~]+.*$", "", p)
        if p.startswith("/"):
            continue
        for pre in ("components/", "stores/", "lib/", "composables/", "views/", "__tests__/"):
            if p.startswith(pre):
                p = "candy-code-web/src-web/" + p
        if re.search(r"(^|/)tools/gen-[a-z-]+\.php$|(^|/)scripts/gen-protocol-schema\.php$", p):
            continue
        if re.search(r"\.[a-z]{1,5}$|/$", p) and "/" in p and "<" not in p:
            if p not in out:
                out.append(p)
    return out


def oneline(text, n=230):
    t = re.sub(r"\s+", " ", text.replace("\n- ", " ").replace("\n", " ")).strip()
    t = re.sub(r"^- ", "", t)
    if len(t) > n:
        cut = t[:n]
        t = cut[:cut.rfind(" ")] + " …"
    return t


def fmt_paths(ps):
    return ", ".join(f"`{p}`" for p in ps) if ps else "none"


def main():
    sched, head, tail, outp = sys.argv[1:5]
    d = json.load(open(sched, encoding="utf-8"))
    S, W = d["steps"], d["waves"]
    wave_of = {}
    for i, w in enumerate(W, 1):
        for sid in w["steps"]:
            wave_of[sid] = i
    import os
    root = os.path.abspath(os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "..", ".."))
    def exists(x):
        return os.path.exists(os.path.join(root, x)) or os.path.exists(os.path.join(root, "candy-code", x))
    creates = {}
    for sid, s in S.items():
        for x in paths(s["files.create"]) + [y for y in paths(s["tests"]) if not exists(y)]:
            creates.setdefault(x, []).append(sid)
    def created_before(x, sid):
        return any(wave_of.get(c, 99) < wave_of.get(sid, 0) for c in creates.get(x, []) if c != sid)
    out = [open(head, encoding="utf-8").read().rstrip("\n"), ""]

    # ---- wave overview
    n_norm = sum(1 for w in W if not w["late"])
    out += ["## 3. Wave overview", "",
            f"{len(S)} steps in {len(W)} waves: W1–W{n_norm} normal, W{n_norm + 1}–W{len(W)} LATE (the large items, run last by owner rule). "
            "Lanes per wave ≤ 10, and every lane in a wave is independent of every other lane in it. Waves are a **priority order, not a barrier**: under rolling execution (§2) free agent slots start eligible steps from later waves while earlier lanes are still in review or fix. Prefix a wave with ✅ once its wave loop is clean.", "",
            "| Wave | Lanes | Steps |", "|---|---|---|"]
    for i, w in enumerate(W, 1):
        tag = " (LATE)" if w["late"] else ""
        out.append(f"| W{i}{tag} | {len(w['steps'])} | " + " · ".join(w["steps"]) + " |")
    out.append("")

    # ---- per-wave lanes
    out += ["## 4. Waves and steps", "",
            "Each lane entry is the brief's summary. **The full brief (approach, root cause, test detail, risks) is the step's block in its design file** — hand the lane agent that block plus §1, §2 and §6 of this plan (§6 settles the design's open hedges). "
            "Mark progress as each step is reviewed, fixed and committed: put ✅ and the short commit hash before the step id (e.g. `✅ a1b2c3d4 C-SEC-1.a`).", ""]
    for i, w in enumerate(W, 1):
        steps = [S[x] for x in w["steps"]]
        protos = [s["id"] for s in steps if "protocol" in s.get("capped", [])]
        chats = [f"{s['id']} ({', '.join(s['tags']['chat'])})" for s in steps if s["tags"].get("chat")]
        boots = [f"{s['id']} ({', '.join(s['tags']['boot'])})" for s in steps if s["tags"].get("boot")]
        out += [f"### W{i}{' — LATE' if w['late'] else ''}", ""]
        notes = []
        if protos:
            notes.append("protocol lanes: " + ", ".join(protos) + " (weld regenerates schema → SERVER.md → generated.ts once)")
        if chats:
            notes.append("Chat.php region claims: " + "; ".join(chats))
        if boots:
            notes.append("Bootstrap.php method claims: " + "; ".join(boots))
        if notes:
            out += ["- " + n for n in notes] + [""]
        for s in steps:
            mod = paths(s["files.modify"])
            cre = paths(s["files.create"])
            tall = [x for x in paths(s["tests"]) if re.search(r"(^|/)(tests|__tests__)/|Test\.php$|\.spec\.ts$", x)]
            tcre = [x for x in tall if not exists(x) and not created_before(x, s["id"])]
            tmod = [x for x in tall if x not in tcre]
            kind = re.sub(r"\s+", " ", s["kind"]).strip()
            deps = sorted(s["depset"], key=lambda x: (wave_of.get(x, 0), x))
            dep_s = ", ".join(f"{x} (W{wave_of.get(x, '?')})" for x in deps) if deps else "none"
            ruling = oneline(s["ruling"], 160) if s["ruling"] and not s["ruling"].lower().startswith("none") else ""
            out += [f"#### {s['id']} — {s['title']}",
                    f"- **Item:** {oneline(s['item'], 160)} · {kind}",
                    f"- **What:** {oneline(s['approach'])}",
                    f"- **Files — create:** {fmt_paths(cre)}",
                    f"- **Files — modify:** {fmt_paths(mod)}",
                    f"- **Tests — create:** {fmt_paths(tcre)} · **modify:** {fmt_paths(tmod)}",
                    f"- **Docs/guards:** {oneline(s['docs/guards'], 200) or 'none'}",
                    f"- **Serialized:** {oneline(s['serialized'], 200) or 'none'}",
                    f"- **Deps:** {dep_s}"]
            if ruling:
                out.append(f"- **Ruling:** {ruling} — see §6")
            out += [f"- **Brief:** `{AREA_DOC.format(s['area'])}` → `### {s['id']}`", ""]

    # ---- item index
    by_item = defaultdict(list)
    for sid, s in S.items():
        by_item[sid.rsplit(".", 1)[0]].append(sid)
    out += ["## 5. Item index", "", "Every picked item, its steps and the waves they land in.", "",
            "| Item | Steps (wave) |", "|---|---|"]
    def key(k):
        m = re.match(r"([CP])-([A-Z]+)-?(\d*)", k)
        return (m.group(1) != "P", m.group(2), int(m.group(3) or 0))
    for item in sorted(by_item, key=key):
        ids = sorted(by_item[item])
        out.append(f"| {item} | " + ", ".join(f"{x} (W{wave_of[x]})" for x in ids) + " |")
    out.append("")
    out.append(open(tail, encoding="utf-8").read().rstrip("\n"))
    open(outp, "w", encoding="utf-8").write("\n".join(out) + "\n")
    print(f"wrote {outp}: {sum(1 for _ in open(outp, encoding='utf-8'))} lines")


if __name__ == "__main__":
    main()
