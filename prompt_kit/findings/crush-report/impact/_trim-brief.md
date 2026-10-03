# Trim brief (crush_report appendices)

Goal: cut each assigned source file in `prompt_kit/findings/crush-report/` down to ONLY what an implementer of the remaining roadmap steps needs. The roadmap is 99-synthesis.md Part III (step IDs 0.x–5.x, with a "Sources" column naming the competitor reports each item came from: CC=01 Claude Code, OC=02 opencode, DCP=03, Kilo=04, Cline=05, OH=06 OpenHands, Zed=07, Goose=08, Aider=09, nano=10 nanobot, Claw=11 OpenClaw, dsh=12 DeepSeek Harness; "N reports" means many) plus Part VIII (settings N-*, server O-*, sessions P-*), and the step index in `17-execution-plan.md` §5. Current sugar-crush facts are in `impact/*.md`.

KEEP (only if some remaining step uses it):
- how the competitor implements a feature a step builds: algorithms, thresholds, data shapes, file/class layouts, protocols, prompt text, schemas, edge cases, test ideas, pitfalls to avoid in that feature;
- the report's recommendation sections (§13/§14-style) rows that map to a step — keep them tight, tag each kept recommendation with the step ID(s) it feeds, e.g. "(→ 2.2-1, 3.B-3)".

REMOVE:
- narrative comparison prose, scoring, "what X does best", feature tours with no matching step, methodology, repo inventories, restatements of the sugar-crush baseline;
- statements about sugar-crush that are now false/fixed (e.g. `maxSteps = 8` (now 1000), sub-agent turns 50 (now 200), CacheBreakpoints/`observeCacheHealth` dormant (wired), Vertex/Bedrock cache marks missing, SGLang auto-discovery missing, attachments/`@file`/image paste missing, MCP trust pins missing, hooks refusal field missing, read-only second TUI/`/branch` missing, initial prompt from CLI words missing, WebSearch private default host, `uiOnly` missing). Do not replace them with "fixed" notes — just delete;
- recommendations for things already done or not in the roadmap.

Format rules: keep it Markdown, keep the top `#` title, keep fenced code byte-exact if kept, keep heading levels consistent (the assembler demotes them). Add one line under the title: `Feeds steps: <comma-separated step IDs>`. If nothing in the file feeds any remaining step, do not edit it — report `DROP` instead.

Hard rules: edit ONLY your assigned files. No git commands that write. No other files. Report: per file, bytes before → after, the step IDs it feeds, and DROP if applicable.
