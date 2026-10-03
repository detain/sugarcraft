#!/usr/bin/env bash
# Assemble crush_report.md = synthesis + every per-agent report as a lettered appendix.
# Headings inside appendices are demoted one level (outside ``` fences only) so the
# appendix title stays the top-level heading and quoted prompts are left byte-exact.
set -euo pipefail
D=/home/sites/sugarcraft/prompt_kit/findings/crush-report
OUT=/home/sites/sugarcraft/crush_report.md
declare -a MAP=(
  "A|00-sugar-crush-baseline.md|sugar-crush feature baseline (source-verified)"
  "B|01-claude-code.md|Claude Code vs sugar-crush"
  "C|02-opencode.md|opencode vs sugar-crush"
  "D|03-opencode-dcp.md|opencode-dynamic-context-pruning (DCP) vs sugar-crush"
  "E|04-kilocode.md|Kilo Code vs sugar-crush"
  "F|05-cline.md|Cline vs sugar-crush"
  "G|06-openhands.md|OpenHands vs sugar-crush"
  "H|07-zed.md|Zed agent panel vs sugar-crush"
  "I|08-goose.md|Goose vs sugar-crush"
  "J|09-aider.md|Aider vs sugar-crush"
  "K|10-nanobot.md|nanobot vs sugar-crush"
  "L|11-openclaw.md|OpenClaw vs sugar-crush"
  "M|12-deepseek-harness.md|DeepSeek Harness vs sugar-crush"
  "N|13-settings-pane-and-configurability.md|Design: settings pane and configurability"
  "O|14-server-mode-and-web-ui.md|Design: server mode and sugar-crush-web"
  "P|16-sessions-and-live-agent-view.md|Design: sessions, live agent lines, agent view"
  "Q|15a-audit-engine-providers.md|Audit: engine, runtime and providers"
  "R|15b-audit-chat-tui.md|Audit: Chat, TUI and rendering"
  "S|15c-audit-tools-permissions.md|Audit: tools, permissions and hooks"
  "T|15d-audit-context-memory-config.md|Audit: context, memory, skills and config"
  "U|15e-audit-agents-sessions-mcp-cli.md|Audit: agents, sessions, MCP and CLI"
  "V|17-execution-plan.md|Execution plan: concurrency-aware waves"
)
{
  cat "$D/99-synthesis.md"
  printf '\n\n---\n\n# Appendices\n\nEach appendix reproduces one agent report verbatim (headings demoted one level). Source files live in `prompt_kit/findings/crush-report/`.\n\n'
  for row in "${MAP[@]}"; do
    IFS='|' read -r L F T <<<"$row"
    printf -- '- [Appendix %s — %s](#appendix-%s) (`%s`)\n' "$L" "$T" "$(echo "$L" | tr 'A-Z' 'a-z')" "$F"
  done
  for row in "${MAP[@]}"; do
    IFS='|' read -r L F T <<<"$row"
    printf '\n\n---\n\n<a id="appendix-%s"></a>\n\n# Appendix %s — %s\n\n*Source: `prompt_kit/findings/crush-report/%s`*\n\n' "$(echo "$L" | tr 'A-Z' 'a-z')" "$L" "$T" "$F"
    if [[ -f "$D/$F" ]]; then
      awk 'BEGIN{f=0} /^[[:space:]]*(```|~~~)/{f=!f; print; next} { if(!f && $0 ~ /^#{1,5} /) print "#" $0; else print }' "$D/$F"
    else
      printf '_Report not produced._\n'
    fi
  done
} > "$OUT"
wc -l "$OUT"
