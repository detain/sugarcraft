#!/usr/bin/env bash
# Sharded parallel PHPUnit runner for the sugar-crush suite.
#
# Usage:
#   scripts/parallel-tests.sh [K] [--junit <xml>] [--out <dir>]
#                             [--timeout <secs>] [--manifest]
#
#   K            shard count (default: nproc)
#   --junit      baseline JUnit XML from a serial run — the duration source for
#                the LPT manifest, and the conservation reference. Without it,
#                reuses <out>/durations.tsv from a previous --junit invocation.
#   --out        run directory (default: ${TMPDIR:-/tmp}/parallel-tests)
#   --timeout    per-shard wall guard in seconds (default: 250, via timeout(1))
#   --manifest   (re)generate the deterministic LPT manifests, run nothing
#
# Measured discipline (probe lane P @round-62 base, re-validated at b2790b1a2):
#  * Bucketing: longest-processing-time (LPT) over per-file durations extracted
#    from the baseline junit. Deterministic: same input => byte-identical
#    manifests, so a resumed run rebuilds the same plan (done-markers).
#  * Per shard: own --cache-directory, explicit file args, same -c config,
#    </dev/null, --colors=never, NO coverage. Each shard re-runs
#    tests/bootstrap.php in its own process (TMPDIR sandbox, loop pin, stdin
#    pin, HOME handling are all per-process).
#  * CRITICAL RUNNER REQUIREMENT (measured 2026-09-10): do NOT wrap shards in
#    `setsid -w` — util-linux 2.39.3 setsid dies with SIGHUP/rc=129 after
#    ~90s when sibling shards run concurrently (killed 2/4 shards in run-k4;
#    the identical command runs green WITHOUT setsid).
#  * CTTY caveat: a runner WITHOUT a controlling terminal (CI steps, pipes)
#    falls to the documented 60x200 TuiRenderer size default. A runner WITH a
#    ctty (dev tty, PTY sessions) previously hit the E655 ambient-size
#    fragility (CompactModelSummaryTest + MouseModalGuardTest); lane-G's
#    size-pin (d8efb147b) landed on master 2026-09-10, so both shapes are now
#    safe — keep the pin intact.
#  * Conservation is asserted from per-shard junit roots vs the baseline junit
#    root (tests/assertions/errors/failures/skipped) in a final table; the
#    critical path floor on this box at K=8 was ProcessExecutorTest ~66s
#    (serial full suite ~548s), i.e. <2min goal met at K=8.
set -u

usage() {
	sed -n '2,16p' "$0" | sed 's/^# \{0,1\}//'
}

REPO=$(git rev-parse --show-toplevel 2>/dev/null) || {
	echo "parallel-tests: not inside a git repository" >&2
	exit 1
}

K=$(nproc)
OUT="${TMPDIR:-/tmp}/parallel-tests"
BASE_JUNIT=""
SHARD_TIMEOUT=250
MODE=run

while [ $# -gt 0 ]; do
	case "$1" in
	--junit)
		BASE_JUNIT=${2:?--junit needs a file}
		shift 2
		;;
	--out)
		OUT=${2:?--out needs a directory}
		shift 2
		;;
	--timeout)
		SHARD_TIMEOUT=${2:?--timeout needs seconds}
		shift 2
		;;
	--manifest)
		MODE=--manifest
		shift
		;;
	-h | --help)
		usage
		exit 0
		;;
	'' | *[!0-9]*)
		echo "parallel-tests: unknown argument: $1" >&2
		usage >&2
		exit 1
		;;
	*)
		K=$1
		shift
		;;
	esac
done

DUR=$OUT/durations.tsv
if [ -n "$BASE_JUNIT" ]; then
	[ -s "$BASE_JUNIT" ] || {
		echo "parallel-tests: missing junit: $BASE_JUNIT" >&2
		exit 1
	}
	SOURCE="$BASE_JUNIT"
elif [ -s "$DUR" ]; then
	SOURCE="$DUR"
else
	echo "parallel-tests: no durations yet — pass --junit <xml> once to measure (see --help)" >&2
	exit 1
fi

mkdir -p "$OUT"
php "$REPO/scripts/parallel-tests-make-shards.php" "$SOURCE" "$K" "$OUT" || exit 1
if [ "$MODE" = --manifest ]; then
	cat "$OUT/plan-k$K.tsv"
	exit 0
fi

cd "$REPO" || exit 1
PHPUNIT="php sugar-crush/vendor/bin/phpunit"
declare -a PIDS
START=$(date -u +%s)

for i in $(seq 0 $((K - 1))); do
	[ -s "$OUT/shard-$i.list" ] || continue
	if [ -f "$OUT/done-$i" ]; then
		echo "shard $i: DONE (skipped, resumable)"
		PIDS[$i]=""
		continue
	fi
	mapfile -t FILES <"$OUT/shard-$i.list"
	(
		s=$SECONDS
		timeout "$SHARD_TIMEOUT" $PHPUNIT -c sugar-crush/phpunit.xml --colors=never \
			--cache-directory "$OUT/cache-k$K-$i" \
			--log-junit "$OUT/junit-$i.xml" \
			"${FILES[@]}" </dev/null >"$OUT/shard-$i.log" 2>&1
		rc=$?
		echo $((SECONDS - s)) >"$OUT/wall-$i"
		[ $rc -eq 0 ] && touch "$OUT/done-$i"
		echo "shard $i: rc=$rc wall=$((SECONDS - s))s"
	) &
	PIDS[$i]=$!
done

FAILED=0
for i in $(seq 0 $((K - 1))); do
	if [ -n "${PIDS[$i]:-}" ]; then
		wait "${PIDS[$i]}" || FAILED=1
	fi
done
TOTAL=$(($(date -u +%s) - START))

echo "== run wall: ${TOTAL}s (K=$K) load=$(cat /proc/loadavg) =="
for i in $(seq 0 $((K - 1))); do
	[ -f "$OUT/shard-$i.log" ] || continue
	echo "-- shard $i: wall=$(cat "$OUT/wall-$i" 2>/dev/null || echo '?')s rc_marker=$([ -f "$OUT/done-$i" ] && echo ok || echo MISSING)"
	grep -E '^(OK|Tests:|FAILURES|ERRORS)' "$OUT/shard-$i.log" | tail -2
done

if [ -n "$BASE_JUNIT" ]; then
	echo "== conservation (shard junit roots vs baseline) =="
	php -r '
$out = $argv[1]; $K = (int)$argv[2]; $base = $argv[3];
$sum = ["tests"=>0,"assertions"=>0,"errors"=>0,"failures"=>0,"skipped"=>0,"time"=>0.0];
for ($i=0;$i<$K;$i++){
  $f="$out/junit-$i.xml"; if(!is_file($f)) { fwrite(STDERR,"missing shard junit $i\n"); exit(2); }
  $d=new DOMDocument(); $d->load($f);
  $r=$d->getElementsByTagName("testsuite")->item(0); if(!$r) exit(2);
  foreach(["tests","assertions","errors","failures"] as $k){ $sum[$k]+= (int)($r->getAttribute($k) ?: 0); }
  $sum["time"] += (float)$r->getAttribute("time");
  $x=new DOMXPath($d); $sum["skipped"] += iterator_count($x->query("//testcase/skipped"));
}
$b=new DOMDocument(); $b->load($base); $br=$b->getElementsByTagName("testsuite")->item(0);
$bx=new DOMXPath($b); $bs=[
  "tests"=>(int)$br->getAttribute("tests"), "assertions"=>(int)$br->getAttribute("assertions"),
  "errors"=>(int)$br->getAttribute("errors"), "failures"=>(int)$br->getAttribute("failures"),
  "skipped"=>iterator_count($bx->query("//testcase/skipped"))];
printf("shards: tests=%d assertions=%d errors=%d failures=%d skipped=%d sumTime=%.1fs\n",$sum["tests"],$sum["assertions"],$sum["errors"],$sum["failures"],$sum["skipped"],$sum["time"]);
printf("base:   tests=%d assertions=%d errors=%d failures=%d skipped=%d\n",$bs["tests"],$bs["assertions"],$bs["errors"],$bs["failures"],$bs["skipped"]);
printf("delta:  assertions=%+d\n",$sum["assertions"]-$bs["assertions"]);
$ok = $sum["tests"]==$bs["tests"] && $sum["skipped"]==$bs["skipped"] && $sum["errors"]==0 && $sum["failures"]==0;
printf("CONSERVATION: %s\n", $ok?"PASS":"FAIL");
exit($ok?0:1);
' "$OUT" "$K" "$BASE_JUNIT" || FAILED=1
fi

exit $FAILED
