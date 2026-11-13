#!/bin/sh
# candy-codetime editor-save heartbeat hook — a WORKED EXAMPLE (this is not a
# shipped plugin: no editor plugins ship with candy-codetime, and none is
# required for this script to work).
#
# Wire it into your editor's save hook and one heartbeat lands per save:
#   vim:  autocmd BufWritePost * silent! !sh /path/to/plugin-heartbeat.example.sh "$EXPANDEF"
#   cron: run it on a schedule with a fixed file argument instead.
#
# Usage: plugin-heartbeat.example.sh <path-saved> [project] [language]
#   <path-saved>  the file that was just written (recorded verbatim)
#   [project]     override; else nearest git root basename, else cwd basename
#   [language]    override; else mapped from the file extension
#
# Environment: CANDY_CODETIME_DIR (data dir, same as the CLI),
#   CODETIME_HOOK_BIN (path to the candy-codetime binary; on PATH by default),
#   CODETIME_HOOK_DURATION (seconds per beat, default 60).
set -eu

FILE=${1:?usage: plugin-heartbeat.example.sh <path-saved> [project] [language]}
DUR=${CODETIME_HOOK_DURATION:-60}
DIR=${CANDY_CODETIME_DIR:-${XDG_DATA_HOME:-$HOME/.local/share}/candy-codetime}
BIN=${CODETIME_HOOK_BIN:-candy-codetime}

if [ $# -ge 2 ] && [ -n "${2:-}" ]; then PROJECT=$2; else
	ROOT=$(cd "$(dirname "$FILE")" 2>/dev/null && git rev-parse --show-toplevel 2>/dev/null || true)
	PROJECT=${ROOT##*/}
	[ -n "$PROJECT" ] || PROJECT=$(basename "$PWD")
fi
if [ $# -ge 3 ] && [ -n "${3:-}" ]; then LANG_=$3; else
	case "${FILE##*.}" in
	php) LANG_=php ;; js | mjs | cjs) LANG_=javascript ;; ts | tsx) LANG_=typescript ;;
	py) LANG_=python ;; go) LANG_=go ;; rs) LANG_=rust ;; rb) LANG_=ruby ;;
	c | h) LANG_=c ;; cpp | hpp | cc) LANG_=cpp ;; sh | bash) LANG_=sh ;;
	*) LANG_=text ;;
	esac
fi
NOW=$(date +%s)
LINE="{\"time\": $NOW, \"project\": \"$PROJECT\", \"language\": \"$LANG_\", \"file\": \"$FILE\", \"duration\": $DUR, \"tags\": []}"

# Prefer `push --` (stdin batch): it passes the ignore door, the clamps and the
# advisory lock. Raw `>>` is the fallback for hosts with no binary on PATH — it
# bypasses all three, so it is wrapped in flock where flock exists (a
# hand-echoed line could otherwise interleave with a concurrent append; see
# README "Data layout").
if command -v "$BIN" >/dev/null 2>&1 &&
	printf '%s\n' "$LINE" | "$BIN" push -- >/dev/null 2>&1; then
	exit 0
fi

umask 077
mkdir -p "$DIR"
DAY=$DIR/$(date +%F).jsonl
if command -v flock >/dev/null 2>&1; then
	printf '%s\n' "$LINE" | flock "$DIR/.append.lock" sh -c 'cat >>"$1"' _ "$DAY"
else
	printf '%s\n' "$LINE" >>"$DAY"
fi
