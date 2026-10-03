#!/usr/bin/env bash
# Worktree helper for the crush_report.md Appendix R wave execution plan.
#   prompt_kit/tools/crush-wave.sh mk w3 a b c   → /home/sites/sugarcraft-wt/w3-{a,b,c} on fix/w3-<g>
#   prompt_kit/tools/crush-wave.sh rm w3 a b c   → remove those worktrees + branches
# Only the vendor trees the sugar-crush roadmap touches are copied (linked mode,
# relative path-repo symlinks resolve inside the worktree); a group that needs
# another lib's vendor runs `composer install` there itself.
set -euo pipefail
R=/home/sites/sugarcraft
W=/home/sites/sugarcraft-wt
VENDORS=(vendor sugar-crush/vendor sugar-mcp/vendor candy-core/vendor)

cmd=${1:?mk|rm}; w=${2:?wave}; shift 2
case "$cmd" in
  mk)
    mkdir -p "$W/.scratch"
    git -C "$R" fetch -q origin
    for g in "$@"; do
      d="$W/$w-$g"
      git -C "$R" worktree add -q -b "fix/$w-$g" "$d" origin/master
      for rel in "${VENDORS[@]}"; do
        [ -d "$R/$rel" ] || continue
        mkdir -p "$d/$(dirname "$rel")"
        cp -a "$R/$rel" "$d/$rel"
      done
      mkdir -p "$W/.scratch/$w-$g"
      echo "created $d"
    done
    ;;
  rm)
    for g in "$@"; do
      git -C "$R" worktree remove --force "$W/$w-$g" 2>/dev/null || true
      git -C "$R" branch -D "fix/$w-$g" 2>/dev/null || true
    done
    git -C "$R" worktree prune
    ;;
  *) echo "usage: $0 mk|rm <wave> <group>..." >&2; exit 2 ;;
esac
