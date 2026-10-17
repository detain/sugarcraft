#!/usr/bin/env bash
# Assemble crush_report.md = synthesis + the trimmed per-agent reports as lettered appendices.
# Audits 15a–15e (no open findings) and impact/ research files are not assembled.
# Headings inside appendices are demoted one level (outside ``` fences only) so the
# appendix title stays the top-level heading and quoted prompts are left byte-exact.
set -euo pipefail
D=/home/sites/sugarcraft/prompt_kit/findings/crush-report
OUT=/home/sites/sugarcraft/crush_report.md
declare -a MAP=(
  "A|00-candy-code-baseline.md|candy-code feature baseline (source-verified)"
  "B|01-claude-code.md|Claude Code vs candy-code"
  "C|02-opencode.md|opencode vs candy-code"
  "D|03-opencode-dcp.md|opencode-dynamic-context-pruning (DCP) vs candy-code"
  "E|04-kilocode.md|Kilo Code vs candy-code"
  "F|05-cline.md|Cline vs candy-code"
  "G|06-openhands.md|OpenHands vs candy-code"
  "H|07-zed.md|Zed agent panel vs candy-code"
  "I|08-goose.md|Goose vs candy-code"
  "J|09-aider.md|Aider vs candy-code"
  "K|10-nanobot.md|nanobot vs candy-code"
  "L|11-openclaw.md|OpenClaw vs candy-code"
  "M|12-deepseek-harness.md|DeepSeek Harness vs candy-code"
  "N|13-settings-pane-and-configurability.md|Design: settings pane and configurability"
  "O|14-server-mode-and-web-ui.md|Design: server mode and candy-code-web"
  "P|16-sessions-and-live-agent-view.md|Design: sessions, live agent lines, agent view"
  "R|17-execution-plan.md|Execution plan: what is left"
)
{
  cat "$D/99-synthesis.md"
  printf '\n\n---\n\n# Appendices\n\nEach appendix holds one source report, trimmed to what the remaining steps need (headings demoted one level). Source files live in `prompt_kit/findings/crush-report/`.\n\n'
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
