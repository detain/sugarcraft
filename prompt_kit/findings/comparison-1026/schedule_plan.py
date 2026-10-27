#!/usr/bin/env python3
"""Parse design-*.md step blocks and pack them into waves of <= N concurrent lanes.

Concurrency rules (one wave):
  * hard-exclusive tags: protocol, headless, workflows, commands, catalog, runtime, renderer
  * any shared file path between two lanes = conflict, except:
      - candy-code/src/Chat.php        : allowed when [chat:<regions>] sets are disjoint (max CHAT_MAX)
      - candy-code/src/Cli/Bootstrap.php: allowed when [bootstrap:<methods>] sets are disjoint (max BOOT_MAX)
      - candy-code/lang/en.php         : soft (append-only, key prefixes) - no limit
      - candy-code/README.md, candy-code/docs/*.md: soft cap DOC_MAX lanes per page
        (generated regions are regenerated at weld)
  * weld-only paths are ignored: candy-code-web/dist/, suite-figure.json, parallel-tests-durations.tsv
  * deps: a step runs only after every dep step landed in an EARLIER wave
  * phase LATE steps only after every normal step is scheduled
Priority among ready steps: (class, -critical-path) where class 0 = CRITICAL/MAJOR fix + owner picks,
1 = dormant-wire + other fixes, 2 = gaps/features.

Usage: schedule_plan.py <dir> [--lanes 10] [--json out.json] [--md out.md]
"""
import json, re, sys, glob, os
from collections import defaultdict

LANES, CHAT_MAX, BOOT_MAX, DOC_MAX = 10, 3, 2, 2
DOC_CAP = {"candy-code/README.md": 4, "candy-code/docs/COMMANDS.md": 3, "candy-code/docs/SETTINGS.md": 3, "candy-code/docs/SERVER.md": 3}
EXCL = ["workflows", "catalog", "runtime", "renderer"]          # one lane per wave
CAPPED = {"protocol": 2, "commands": 2}                           # lanes per wave
# generated outputs - regenerated at weld, never a lane conflict
GENERATED = {"candy-code/docs/protocol/candy-code.v1.schema.json", "candy-code-web/src-web/protocol/generated.ts"}
# hub files: lanes may share a wave when their declared regions (files.modify parenthetical) are disjoint
HUB_MAX = {"candy-code/src/Host/SessionHost.php": 2, "candy-code/src/Cli/Subcommands.php": 2, "candy-code/src/App/App.php": 2,
           "candy-code/src/Backend/EngineBackend.php": 2, "candy-code/src/Cli/NonInteractive.php": 2,
           "candy-code/src/Cli/ArgvParser.php": 2, "candy-code/src/Cli/ParsedArgs.php": 2,
           "candy-code/src/Config/LayeredSettings.php": 2, "candy-code/src/Commands/KeyBindingRegistry.php": 2,
           "candy-code/src/Protocol/Schema/MethodSchemas.php": 2, "candy-code/src/Protocol/Schema/Definitions.php": 2,
           "candy-code/src/Protocol/ServerContext.php": 2, "candy-code/src/MCP/McpClient.php": 2}
DROPPED = {"C-TUI-1.b": None, "P-IMG.a": "P-MENU.d"}  # ruling-dropped / superseded -> replacement
PICK_ALIAS = {"P-CTX-BREAKDOWN": "P-CTX", "P-SESSION-TABS": "P-TABS", "P-AGENT-ATTACH": "P-AGENT",
              "P-IMAGE-MENU": "P-IMG", "P-MENU-REDO": "P-MENU"}
FIELDS = ["item", "kind", "approach", "files.create", "files.modify", "tests", "docs/guards",
          "serialized", "deps", "phase", "ruling", "risks"]
STEP_RE = re.compile(r"^### ((?:C-[A-Z]+-\d+|P-[A-Z]+)\.[a-z]+)\s+[—-]+\s+(.*)$")


def norm_path(p):
    p = p.strip().strip("`").split(" ")[0]
    p = re.sub(r":[\d,\-~]+.*$", "", p)          # strip :line refs
    p = re.sub(r"\(.*$", "", p)
    if not p or "*" in p or "{" in p or "<" in p:
        return None
    if p in ("bin/candy-code", "candy-code/bin/candy-code", "Dockerfile", "candy-code/Dockerfile"):
        return p if p.startswith("candy-code/") else "candy-code/" + p
    if not re.search(r"\.[a-z]{1,5}$|/$", p) or "/" not in p:
        return None
    if re.search(r"(^|/)tools/gen-[a-z-]+\.php$|(^|/)scripts/gen-protocol-schema\.php$", p):
        return None          # generators are run, never edited
    for pre in ("src/", "tests/", "lang/", "docs/", "builtin-commands/", "bin/", "scripts/gen-protocol", "tools/gen-"):
        if p.startswith(pre):
            return "candy-code/" + p
    if p.startswith("/"):
        return None
    for pre in ("components/", "stores/", "lib/", "composables/", "views/", "__tests__/", "protocol/"):
        if p.startswith(pre):
            return "candy-code-web/src-web/" + p
    if p.startswith("src-web/"):
        return "candy-code-web/" + p
    if p.startswith("web:"):
        return "candy-code-web/src-web/" + p[4:]
    return p


def weld_only(p):
    return p in GENERATED or p.startswith("candy-code-web/dist/") or p.endswith("suite-figure.json") or p.endswith("parallel-tests-durations.tsv")


def parse_file(path):
    steps = []
    area = os.path.basename(path)[len("design-"):-3]
    cur, field = None, None
    for line in open(path, encoding="utf-8"):
        line = line.rstrip("\n")
        m = STEP_RE.match(line)
        if m:
            cur = {"id": m.group(1), "title": m.group(2).strip(), "area": area, "src": os.path.basename(path)}
            cur.update({f: "" for f in FIELDS})
            steps.append(cur)
            field = None
            continue
        if line.startswith("## ") or line.startswith("### "):
            cur, field = None, None
            continue
        if cur is None:
            continue
        fm = re.match(r"^- (" + "|".join(re.escape(f) for f in FIELDS) + r"):\s*(.*)$", line)
        if fm:
            field = fm.group(1)
            cur[field] = fm.group(2)
        elif field and (line.startswith("  ") or line.startswith("\t")):
            cur[field] += "\n" + line.strip()
    return steps


def paths_in(text):
    out = set()
    for tok in re.findall(r"`([^`]+)`", text):
        p = norm_path(tok)
        if p and not weld_only(p):
            out.add(p)
    return out


def regions(text):
    """`path` (regions...) pairs from a files.modify field -> {path: set(regions) or {'?'}}"""
    out = {}
    for m in re.finditer(r"`([^`]+)`\s*(\(([^()]*(?:\([^()]*\)[^()]*)*)\))?", text):
        p = norm_path(m.group(1))
        if not p or weld_only(p):
            continue
        inner = m.group(3) or ""
        toks = set(re.findall(r"`([^`]+)`", inner)) or {w for w in re.findall(r"[A-Za-z_][A-Za-z0-9_:>-]{3,}", inner)
                                                       if w.lower() not in ("new", "method", "methods", "region", "regions", "only", "the", "and", "with")}
        out.setdefault(p, set()).update(toks or {"?"})
    return out


def tags(step):
    s = step["serialized"]
    t = {"excl": set(), "chat": None, "boot": None, "hot": set()}
    for tag in re.findall(r"\[([^\]]+)\]", s):
        name, _, arg = tag.partition(":")
        name = name.strip().lower()
        if name in EXCL:
            t["excl"].add(name)
        elif name == "chat":
            t["chat"] = {a.strip() for a in re.split(r"[,;]", arg) if a.strip()} or {"?"}
        elif name == "bootstrap":
            t["boot"] = {a.strip() for a in re.split(r"[,;]", arg) if a.strip()} or {"?"}
        elif name == "hot":
            for h in re.split(r"[,;]| \+ ", arg):
                h = h.strip().strip("`")
                if h:
                    t["hot"].add(os.path.basename(h.rstrip("/")) or h)
    return t


def main():
    a = sys.argv[1:]
    d = a[0]
    out_json = out_md = None
    global LANES
    if "--lanes" in a:
        LANES = int(a[a.index("--lanes") + 1])
    if "--json" in a:
        out_json = a[a.index("--json") + 1]
    if "--md" in a:
        out_md = a[a.index("--md") + 1]
    steps = []
    for f in sorted(glob.glob(os.path.join(d, "design-*.md"))):
        steps += parse_file(f)
    byid = {}
    for s in steps:
        if s["id"] in byid:
            sys.stderr.write(f"WARN duplicate step id {s['id']} ({byid[s['id']]['src']} vs {s['src']})\n")
        byid[s["id"]] = s
    for dead in DROPPED:
        byid.pop(dead, None)
    steps = list(byid.values())
    by_item = defaultdict(list)
    for s in steps:
        by_item[s["id"].rsplit(".", 1)[0]].append(s["id"])

    warn = []
    for s in steps:
        s["late"] = "LATE" in s["phase"].upper()
        files = paths_in(s["files.create"]) | paths_in(s["files.modify"]) | paths_in(s["tests"])
        files |= {p for p in paths_in(s["docs/guards"]) if re.search(r"Test\.php$|\.spec\.ts$", p)}
        for tag in re.findall(r"\[docs:([^\]]+)\]", s["serialized"]):
            for pg in re.split(r"[,;]", tag):
                pg = pg.strip().strip("`")
                if pg:
                    files.add("candy-code/README.md" if pg.endswith("README.md") else
                              ("candy-code/" + pg if pg.startswith("docs/") else "candy-code/docs/" + pg.split("/")[-1]))
        s["files"] = files
        s["t"] = tags(s)
        s["regions"] = regions(s["files.modify"])
        for tg in list(s["t"]["excl"]):
            pass
        s["capped"] = {c for c in CAPPED if re.search(r"\[" + c + r"\b", s["serialized"])}
        if "candy-code/src/Chat.php" in files and s["t"]["chat"] is None:
            s["t"]["chat"] = {"?"}
        if "candy-code/src/Cli/Bootstrap.php" in files and s["t"]["boot"] is None:
            s["t"]["boot"] = {"?"}
        if any(p.startswith("candy-code/src/Protocol/") or p.endswith("generated.ts") for p in files):
            s["capped"].add("protocol")
        if any(p.startswith(".github/workflows/") for p in files):
            s["t"]["excl"].add("workflows")
        deps = set()
        txt = s["deps"].strip()
        if re.match(r"(?i)^none\b", txt):
            txt = ""
        own = s["id"].rsplit(".", 1)[0]
        for m in re.finditer(r"\b((?:C-[A-Z]+-\d+|P-[A-Z]+)\.[a-z]+)\b", txt):
            deps.add(m.group(1))
        stripped = re.sub(r"\([^)]*\)", " ", txt)
        stripped = re.sub(r"\b(?:C-[A-Z]+-\d+|P-[A-Z]+)\.[a-z]+\b", " ", stripped)
        stripped = stripped.replace(own, " ")
        for m in re.finditer(r"\b(C-[A-Z]+-\d+)\b", stripped):
            deps.update(x for x in by_item.get(m.group(1), []))
        for alias, pre in PICK_ALIAS.items():
            if alias in stripped:
                deps.update(by_item.get(pre, []))
        res = set()
        for x in deps:
            if x in DROPPED:
                if DROPPED[x]:
                    res.add(DROPPED[x])
                continue
            if x == s["id"]:
                continue
            if x not in byid:
                warn.append(f"{s['id']}: unknown dep {x}")
                continue
            res.add(x)
        s["depset"] = res
    for s in steps:
        if not s["late"]:
            for x in s["depset"]:
                if byid[x]["late"]:
                    warn.append(f"{s['id']} (normal) depends on LATE {x}: promoting dep is wrong - dep dropped to keep the rule")
            s["depset"] = {x for x in s["depset"] if s["late"] or not byid[x]["late"]}
    # cycle check + critical path
    memo, stack = {}, set()

    def cp(i):
        if i in memo:
            return memo[i]
        if i in stack:
            warn.append(f"cycle at {i}")
            return 0
        stack.add(i)
        kids = [t["id"] for t in steps if i in t["depset"]]
        v = 1 + max((cp(k) for k in kids), default=0)
        stack.discard(i)
        memo[i] = v
        return v
    for s in steps:
        s["cp"] = cp(s["id"])
        k = s["kind"].upper()
        if "FIX" in k and "MAJOR" in k:
            s["cls"] = 0
        elif s["id"].startswith("P-"):
            s["cls"] = 1
        elif "DORMANT" in k or "FIX" in k:
            s["cls"] = 2
        else:
            s["cls"] = 3
        if "CRITICAL" in k:
            s["cls"] = -1

    done, waves = set(), []
    remaining = {s["id"] for s in steps}
    phase_late = False
    while remaining:
        ready = [byid[i] for i in remaining if byid[i]["depset"] <= done and (byid[i]["late"] == phase_late)]
        if not ready:
            if not phase_late and not any(not byid[i]["late"] for i in remaining):
                phase_late = True
                continue
            warn.append(f"STUCK with {len(remaining)} steps: " + ", ".join(sorted(remaining)[:15]))
            break
        ready.sort(key=lambda s: (s["cls"], -s["cp"], s["id"]))
        wave, used_files, used_excl, chat, boot, docs, hot = [], set(), set(), [], [], defaultdict(int), set()
        capc, hubs = defaultdict(int), defaultdict(list)
        for s in ready:
            if len(wave) >= LANES:
                break
            t = s["t"]
            if t["excl"] & used_excl:
                continue
            if t["hot"] & hot:
                continue
            soft_docs = {p for p in s["files"] if p == "candy-code/README.md" or p.startswith("candy-code/docs/")}
            hubset = {p for p in s["files"] if p in HUB_MAX}
            hard = s["files"] - soft_docs - hubset - {"candy-code/lang/en.php", "candy-code/src/Chat.php", "candy-code/src/Cli/Bootstrap.php"}
            if hard & used_files:
                continue
            if any(docs[p] >= DOC_CAP.get(p, DOC_MAX) for p in soft_docs):
                continue
            if any(capc[c] >= CAPPED[c] for c in s["capped"]):
                continue
            bad = False
            for p in hubset:
                r = s["regions"].get(p, {"?"})
                if hubs[p] and (len(hubs[p]) >= HUB_MAX[p] or "?" in r or any("?" in o or (o & r) for o in hubs[p])):
                    bad = True
                    break
            if bad:
                continue
            if t["chat"] is not None:
                if chat and (len(chat) >= CHAT_MAX or "?" in t["chat"] or any("?" in c or (c & t["chat"]) for c in chat)):
                    continue
            if t["boot"] is not None:
                if boot and (len(boot) >= BOOT_MAX or "?" in t["boot"] or any("?" in b or (b & t["boot"]) for b in boot)):
                    continue
            wave.append(s)
            used_files |= hard
            used_excl |= t["excl"]
            hot |= t["hot"]
            for p in soft_docs:
                docs[p] += 1
            for c in s["capped"]:
                capc[c] += 1
            for p in hubset:
                hubs[p].append(s["regions"].get(p, {"?"}))
            if t["chat"] is not None:
                chat.append(t["chat"])
            if t["boot"] is not None:
                boot.append(t["boot"])
        if not wave:
            warn.append("empty wave - stuck")
            break
        waves.append({"late": phase_late, "steps": [s["id"] for s in wave]})
        for s in wave:
            done.add(s["id"])
            remaining.discard(s["id"])

    for w in warn:
        sys.stderr.write("WARN " + w + "\n")
    print(f"steps {len(steps)} (late {sum(s['late'] for s in steps)}); waves {len(waves)} "
          f"(late waves {sum(w['late'] for w in waves)}); lanes/wave "
          + " ".join(str(len(w['steps'])) for w in waves))
    if out_json:
        json.dump({"waves": waves, "steps": {s["id"]: {k: (sorted(v) if isinstance(v, set) else v)
                   for k, v in s.items() if k not in ("t",)} | {"tags": {k: (sorted(v) if isinstance(v, set) else v) for k, v in s["t"].items()}}
                   for s in steps}, "warnings": warn}, open(out_json, "w", encoding="utf-8"), ensure_ascii=False, indent=1, default=list)


if __name__ == "__main__":
    main()
