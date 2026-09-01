#!/usr/bin/env python3
"""Regenerate prompt_kit/CONTEXT.md from the Claude Code per-project memory store.

CONTEXT.md exists so that the accumulated project context survives a change of
harness or the loss of ~/.claude. It is GENERATED — hand-editing it guarantees it
drifts from the store it claims to mirror.

This script is deliberately fail-closed. Its first version read each entry's type
with an unanchored `type:\\s*(\\S+)`, which matched `node_type: memory` on the line
ABOVE the real field; every entry was classified `memory`, fell outside the
grouping table, and was silently dropped, producing a plausible ~17 KB file with
no entries in it. It was caught only because 17 KB was compared against an
expected ~224 KB. So: the type field is anchored to its own line, an unknown type
is an error rather than a skip, and after rendering we assert that every input
file AND a probe drawn from each body is present in the output. A generator that
answers the same way for every input reads as working.

Usage:  python3 prompt_kit/tools/context-gen.py [--check] [--memory-dir DIR]
        --check writes nothing and exits 1 if CONTEXT.md is out of date.
"""
import argparse
import pathlib
import re
import sys

DEFAULT_MEMORY_DIR = pathlib.Path.home() / ".claude/projects/-home-sites-sugarcraft/memory"

# type -> (section title, sort order). An unlisted type is a hard error.
GROUPS = [
    ("user", "Who the user is"),
    ("feedback", "How the user wants the work done — corrections and confirmed approaches"),
    ("project", "Ongoing work, goals and constraints not derivable from the code or git history"),
    ("reference", "Pointers to external resources"),
]

NAME_RE = re.compile(r"^[ \t]*name:[ \t]*(.+?)[ \t]*$", re.M)
DESC_RE = re.compile(r"^[ \t]*description:[ \t]*(.+?)[ \t]*$", re.M)
TYPE_RE = re.compile(r"^[ \t]*type:[ \t]*(\S+)[ \t]*$", re.M)


def parse(path):
    raw = path.read_text(encoding="utf-8")
    if not raw.startswith("---\n"):
        sys.exit(f"{path.name}: no frontmatter")
    end = raw.index("\n---\n", 3)
    fm, body = raw[4:end], raw[end + 5:].strip("\n")
    def one(rx, label):
        m = rx.search(fm)
        if not m:
            sys.exit(f"{path.name}: no {label} in frontmatter")
        return m.group(1)
    kind = one(TYPE_RE, "type")
    if kind not in {k for k, _ in GROUPS}:
        sys.exit(f"{path.name}: unknown type {kind!r} — add it to GROUPS or fix the file")
    if not body.strip():
        sys.exit(f"{path.name}: empty body")
    return {"file": path.name, "name": one(NAME_RE, "name"),
            "desc": one(DESC_RE, "description"), "type": kind, "body": body}


def render(entries, header_tmpl):
    n = len(entries)
    words = {44: "Forty-four", 45: "Forty-five", 46: "Forty-six", 47: "Forty-seven",
             48: "Forty-eight", 49: "Forty-nine", 50: "Fifty"}
    out = [header_tmpl.replace("{COUNT}", words.get(n, str(n)))]
    out.append("## Index\n")
    for kind, title in GROUPS:
        group = [e for e in entries if e["type"] == kind]
        if not group:
            continue
        out.append(f"\n\n**{title}** ({len(group)})\n\n")
        for e in group:
            out.append(f"- `{e['name']}` — {e['desc']}\n")
    out.append("\n")  # the index's last entry line, then a blank, then the first section rule
    for kind, title in GROUPS:
        group = [e for e in entries if e["type"] == kind]
        if not group:
            continue
        out.append(f"\n---\n\n# {title}\n\n")
        for e in group:
            out.append(f"\n## `{e['name']}`\n\n*{e['desc']}*\n\n"
                       f"<sub>originally `{e['file']}`, type `{e['type']}`</sub>\n\n{e['body']}\n\n")
    return "".join(out)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--memory-dir", type=pathlib.Path, default=DEFAULT_MEMORY_DIR)
    ap.add_argument("--check", action="store_true")
    args = ap.parse_args()

    here = pathlib.Path(__file__).resolve().parent
    target = here.parent / "CONTEXT.md"
    header = (here / "context-header.md").read_text(encoding="utf-8")

    if not args.memory_dir.is_dir():
        sys.exit(f"memory dir not found: {args.memory_dir}")
    files = sorted(p for p in args.memory_dir.glob("*.md") if p.name != "MEMORY.md")
    if not files:
        sys.exit(f"no memory files under {args.memory_dir}")
    entries = [parse(p) for p in files]

    text = render(entries, header)

    # Fail closed: every input file, and a probe from inside each body, must survive
    # into the output. This is the assertion the silently-empty first version lacked.
    for e in entries:
        if f"`{e['file']}`" not in text:
            sys.exit(f"BUG: {e['file']} vanished from the output")
        probe = max(e["body"].splitlines(), key=len).strip()[:60]
        if probe and probe not in text:
            sys.exit(f"BUG: body of {e['file']} vanished from the output")
    if len(text) < 100_000:
        sys.exit(f"BUG: output is only {len(text)} bytes — expected >100 KB for {len(entries)} entries")

    if args.check:
        cur = target.read_text(encoding="utf-8") if target.exists() else ""
        if cur != text:
            sys.exit("CONTEXT.md is out of date — run prompt_kit/tools/context-gen.py")
        print(f"CONTEXT.md up to date ({len(entries)} entries, {len(text)} bytes)")
        return
    target.write_text(text, encoding="utf-8")
    print(f"wrote {target} — {len(entries)} entries, {len(text)} bytes")


if __name__ == "__main__":
    main()
