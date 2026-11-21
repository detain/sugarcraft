#!/usr/bin/env bash
# ParaTest runner for any lib's suite (default: candy-code), with
# longest-first scheduling and the worker stdin fix, and no vendor edits.
#
# Usage:
#   scripts/paratest.sh [--lib <slug>] [-p <K>] [--durations <junit.xml|tsv|none>]
#                       [--out <dir>] [--timeout <secs>] [--no-prelude]
#                       [--config-only] [-- <extra paratest args>]
#
#   --lib         lib directory slug (default: candy-code). Needs
#                 <slug>/vendor/bin/paratest (brianium/paratest ~7.4.9, the last
#                 release for PHPUnit 10).
#   -p K          worker count. Default: min(suggested K, nproc / 2), where
#                 the suggested K is ceil(1.25 x total / longest file) from
#                 the durations. Above total / longest the longest file sets
#                 the wall and extra workers only add contention, which is
#                 where timing flakes come from. The nproc / 2 cap is
#                 measured: candy-code on 64 cpus ran clean at K=32 (145 s),
#                 while K=58 cut the wall to 111 s but grew the summed test
#                 time 23% and a concurrency-timing red, because tests spawn
#                 children that need cpus too. The inflated junit then
#                 suggests a higher K still, so the rule alone ratchets up.
#                 Pass -p to go past the cap. Without durations: nproc / 2.
#   --durations   per-file durations: a junit from an earlier run of this lib,
#                 a "path<TAB>seconds" TSV, or `none` for plain alphabetical
#                 order. Default: the junit this script left in --out last
#                 time (kept as <out>/junit.prev.xml); otherwise, for
#                 candy-code, scripts/parallel-tests-durations.tsv; otherwise
#                 none.
#   --out         run directory (default: ${TMPDIR:-/tmp}/paratest-<slug>).
#                 Gets phpunit-lpt.xml, lpt.log, paratest.log (all output,
#                 with --verbose "Process N executing" lines for worker
#                 forensics), junit.xml and the result cache.
#   --timeout     whole-run guard in seconds, via timeout(1) (default: 1800)
#   --no-prelude  run the stock worker loop (for comparisons only)
#   --config-only write <out>/phpunit-lpt.xml and print the K advice; run nothing
#   --            everything after it goes to paratest verbatim
#                 (e.g. -- --max-batch-size 1, -- --order-by=random --random-order-seed 7)
#
# How it differs from a bare `vendor/bin/paratest`:
#  * Scheduling. ParaTest 7.4.x hands a free worker the next FILE in suite
#    order, which is alphabetical, so a slow file late in the alphabet starts
#    late and sets the wall (measured on candy-code: K=32 407 s alphabetical
#    against a 304 s longest file). scripts/paratest-lpt-config.php writes a
#    config listing the files longest-first, which makes the queue LPT.
#    `--order-by=duration` does not do this on 7.4.x: the parent sorts with a
#    NullResultCache.
#  * Worker stdin. scripts/paratest-worker-prelude.php is prepended to every
#    worker. It reads the parent's commands from a private handle, so a test
#    that sets STDIN non-blocking or a bootstrap that closes STDIN can no
#    longer end a worker with "Exit Code: 0(OK)" blamed on the next file.
#  * Environment. CANDY_CODE_MCP_DISABLE=1 prefixes the launch for the same
#    E737 reason scripts/parallel-tests.sh gives (only candy-code reads it),
#    LINES/COLUMNS are dropped so terminal size cannot leak in, stdin is
#    /dev/null, and colors are off.
#
# Exit status is paratest's (124 when --timeout fires).
set -u

usage() {
	awk 'NR>1 && /^#/ {sub(/^# ?/, ""); print; next} NR>1 {exit}' "$0"
}

REPO=$(git -C "$(dirname "${BASH_SOURCE[0]}")" rev-parse --show-toplevel 2>/dev/null) || {
	echo "paratest.sh: not inside a git repository" >&2
	exit 1
}

LIB=candy-code
K=""
DURATIONS=""
OUT=""
RUN_TIMEOUT=1800
PRELUDE=1
CONFIG_ONLY=0
EXTRA=()

while [ $# -gt 0 ]; do
	case "$1" in
	--lib)
		LIB=${2:?--lib needs a slug}
		shift 2
		;;
	-p | --processes)
		K=${2:?-p needs a number}
		shift 2
		;;
	--durations)
		DURATIONS=${2:?--durations needs a file or none}
		shift 2
		;;
	--out)
		OUT=${2:?--out needs a directory}
		shift 2
		;;
	--timeout)
		RUN_TIMEOUT=${2:?--timeout needs seconds}
		shift 2
		;;
	--no-prelude)
		PRELUDE=0
		shift
		;;
	--config-only)
		CONFIG_ONLY=1
		shift
		;;
	-h | --help)
		usage
		exit 0
		;;
	--)
		shift
		EXTRA=("$@")
		break
		;;
	*)
		echo "paratest.sh: unknown argument '$1' (see --help)" >&2
		exit 2
		;;
	esac
done

LIBDIR="$REPO/$LIB"
if [ ! -f "$LIBDIR/phpunit.xml" ]; then
	echo "paratest.sh: $LIBDIR/phpunit.xml not found" >&2
	exit 2
fi
if [ ! -x "$LIBDIR/vendor/bin/paratest" ]; then
	echo "paratest.sh: $LIB has no vendor/bin/paratest; install it there with" >&2
	echo "  (cd $LIB && composer require --dev 'brianium/paratest:~7.4.9')" >&2
	exit 2
fi
case "$K" in
'' | *[!0-9]* | 0)
	if [ -n "$K" ]; then
		echo "paratest.sh: -p needs a positive integer, got '$K'" >&2
		exit 2
	fi
	;;
esac

OUT=${OUT:-${TMPDIR:-/tmp}/paratest-$LIB}
mkdir -p "$OUT" || exit 1
OUT=$(cd "$OUT" && pwd)

if [ -z "$DURATIONS" ]; then
	# The last run's junit outranks the committed TSV: it is this harness's
	# own measurement and as fresh as the tree. The TSV moves only with the
	# suite-figure refresh, so after a speed fix it can name a floor file that
	# is no longer slow and suggest a K far too low.
	if [ -f "$OUT/junit.xml" ]; then
		mv -f "$OUT/junit.xml" "$OUT/junit.prev.xml"
	fi
	if [ -f "$OUT/junit.prev.xml" ]; then
		DURATIONS="$OUT/junit.prev.xml"
	elif [ "$LIB" = candy-code ] && [ -f "$REPO/scripts/parallel-tests-durations.tsv" ]; then
		DURATIONS="$REPO/scripts/parallel-tests-durations.tsv"
	else
		DURATIONS=none
	fi
fi

CONFIG="$OUT/phpunit-lpt.xml"
if ! php "$REPO/scripts/paratest-lpt-config.php" "$LIBDIR" "$DURATIONS" "$CONFIG" 2>"$OUT/lpt.log"; then
	cat "$OUT/lpt.log" >&2
	exit 1
fi
cat "$OUT/lpt.log"

SUGGESTED=$(sed -n 's/.*suggested-k=\([0-9]*\).*/\1/p' "$OUT/lpt.log" | tail -n 1)
NPROC=$(nproc)
CAP=$((NPROC / 2))
[ "$CAP" -ge 1 ] || CAP=1
if [ -z "$K" ]; then
	K=$CAP
	if [ -n "$SUGGESTED" ] && [ "$SUGGESTED" -gt 0 ] && [ "$SUGGESTED" -lt "$CAP" ]; then
		K=$SUGGESTED
	fi
fi
echo "paratest.sh: $LIB with K=$K (suggested ${SUGGESTED:-n/a} from $DURATIONS, cap $CAP = nproc $NPROC / 2)"

if [ "$CONFIG_ONLY" = 1 ]; then
	echo "paratest.sh: wrote $CONFIG"
	exit 0
fi

PASSTHRU=()
if [ "$PRELUDE" = 1 ]; then
	PASSTHRU=("--passthru-php='-d' 'auto_prepend_file=$REPO/scripts/paratest-worker-prelude.php'")
fi

START=$(date +%s.%N)
(
	cd "$LIBDIR" &&
		CANDY_CODE_MCP_DISABLE=1 env -u LINES -u COLUMNS timeout "$RUN_TIMEOUT" \
			vendor/bin/paratest -c "$CONFIG" -p "$K" --colors=never --verbose \
			--cache-directory "$OUT/cache" --log-junit "$OUT/junit.xml" \
			"${PASSTHRU[@]}" "${EXTRA[@]}" </dev/null >"$OUT/paratest.log" 2>&1
)
STATUS=$?
END=$(date +%s.%N)

# The summary lines only; failure bodies stay in the log.
grep -E '^(Time: |There (was|were) [0-9]+ |OK |OK, |Tests: |FAILURES!|ERRORS!|No tests executed)' "$OUT/paratest.log"
awk -v s="$START" -v e="$END" -v st="$STATUS" -v f="$OUT/paratest.log" \
	'BEGIN { printf "paratest.sh: exit %d, wall %.1fs, log %s\n", st, e - s, f }'
exit "$STATUS"
