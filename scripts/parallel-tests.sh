#!/usr/bin/env bash
# Sharded parallel PHPUnit runner for the sugar-crush suite.
#
# Usage:
#   scripts/parallel-tests.sh [K] [--junit <xml>] [--durations <tsv>]
#                             [--against-json <suite-figure.json>]
#                             [--out <dir>] [--timeout <secs>] [--manifest] [--clover]
#
#   K            shard count (default: min(nproc, 8) — K=8 is the measured
#                local floor, see "Measured discipline" below; beyond K=8
#                contention outpaces the gains, so more shards stop paying).
#                CI pins K=min(nproc,4) explicitly — its GitHub-hosted
#                ubuntu-latest runners are 2-4 vCPU and must not be
#                oversubscribed.)
#   --junit      baseline JUnit XML from a serial run — the duration source for
#                the LPT manifest, and the conservation reference. Without it,
#                --durations, or a previous <out>/durations.tsv, there is no
#                duration source and the run refuses to start.
#   --durations  explicit per-file durations TSV (e.g. the committed
#                scripts/parallel-tests-durations.tsv). Lets CI shard WITHOUT
#                a serial baseline run. Takes effect only when --junit is
#                absent; <out>/durations.tsv from a prior --junit run still
#                wins over nothing, exactly as before.
#                REGENERATION when tests are added/moved: run once with
#                `--junit <serial-baseline.xml> --out <dir>`, then
#                `cp <dir>/durations.tsv scripts/parallel-tests-durations.tsv`
#                and re-pin suite-figure.json — conservation pins both to the
#                same live enumeration; an unrefreshed TSV goes RED by design.
#   --against-json  conservation reference is a pinned suite-figure.json
#                (tests/assertions/failures/skipped) instead of a baseline
#                junit. This is the fail-closed CI shape: a test file present
#                in the tree but absent from the durations manifest silently
#                runs nowhere, its tests never enter the shard sums, and the
#                pinned figure (re-derived LIVE by
#                ReadmeSuiteFigureDriftTest::testArtifactIsNotStaleAgainstTheLiveEnumeration
#                at every merge) then refuses the shortfall.
#   --out        run directory (default: ${TMPDIR:-/tmp}/parallel-tests)
#   --timeout    per-shard wall guard in seconds (default: 250, via timeout(1))
#   --manifest   (re)generate the deterministic LPT manifests, run nothing
#   --clover     each shard additionally writes --coverage-clover <out>/clover-<i>.xml
#                (E691 — the sharded sugar-crush coverage job). Default OFF: shard
#                argv stays byte-identical to a plain run. Merge the reports after:
#                  php scripts/merge-clover.php <out> <K> <dest.xml>
#                Resume caveat: done-<i> markers are clover-agnostic — a shard marked
#                done by an earlier NON-clover run of the same --out dir produces no
#                clover file, and merge-clover.php fails closed on exactly that.
#                CI always starts from a fresh --out.
#
# Measured discipline (probe lane P @round-62 base, re-validated at b2790b1a2):
#  * Bucketing: longest-processing-time (LPT) over per-file durations extracted
#    from the baseline junit. Deterministic: same input => byte-identical
#    manifests, so a resumed run rebuilds the same plan (done-markers).
#  * Per shard: own --cache-directory, explicit file args, same -c config,
#    </dev/null, --colors=never, no coverage unless --clover opts in (E691). Each shard re-runs
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
#    root (tests/assertions/errors/failures/skipped) in a final table.
#  * MEASURED K-SCALING (11,566-test tree @56501908d, 64-core box): serial
#    565s; K=4 159s; K=8 ~65-73s wall with the largest LPT bucket ~62s — one
#    ProcessExecutorTest-class file is the hard floor below which no shard
#    count can go, and beyond K=8 contention outpaces the gains. Hence the
#    local default min(nproc, 8); CI stays at K=min(nproc, 4) because its
#    runners are 2-4 vCPU.
set -u

usage() {
	sed -n '2,47p' "$0" | sed 's/^# \{0,1\}//'
}

REPO=$(git rev-parse --show-toplevel 2>/dev/null) || {
	echo "parallel-tests: not inside a git repository" >&2
	exit 1
}

K=$(nproc)
if [ "$K" -gt 8 ]; then K=8; fi
OUT="${TMPDIR:-/tmp}/parallel-tests"
BASE_JUNIT=""
DURATIONS=""
AGAINST_JSON=""
CLOVER=""
SHARD_TIMEOUT=250
MODE=run

while [ $# -gt 0 ]; do
	case "$1" in
	--junit)
		BASE_JUNIT=${2:?--junit needs a file}
		shift 2
		;;
	--durations)
		DURATIONS=${2:?--durations needs a file}
		shift 2
		;;
	--against-json)
		AGAINST_JSON=${2:?--against-json needs a file}
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
	--clover)
		CLOVER=1
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
elif [ -n "$DURATIONS" ]; then
	[ -s "$DURATIONS" ] || {
		echo "parallel-tests: missing durations: $DURATIONS" >&2
		exit 1
	}
	SOURCE="$DURATIONS"
elif [ -s "$DUR" ]; then
	SOURCE="$DUR"
else
	echo "parallel-tests: no durations yet — pass --junit <xml> once to measure, or --durations <tsv> (see --help)" >&2
	exit 1
fi
[ -z "$AGAINST_JSON" ] || [ -s "$AGAINST_JSON" ] || {
	echo "parallel-tests: missing suite-figure json: $AGAINST_JSON" >&2
	exit 1
}

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
	COV_ARGS=()
	if [ -n "$CLOVER" ]; then
		COV_ARGS=(--coverage-clover "$OUT/clover-$i.xml")
	fi
	(
		s=$SECONDS
		timeout "$SHARD_TIMEOUT" $PHPUNIT -c sugar-crush/phpunit.xml --colors=never \
			--cache-directory "$OUT/cache-k$K-$i" \
			--log-junit "$OUT/junit-$i.xml" \
			"${COV_ARGS[@]}" \
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

if [ -n "$CLOVER" ]; then
	echo "== clover shards: $OUT/clover-0.xml .. $OUT/clover-$((K - 1)).xml =="
	echo "   merge: php scripts/merge-clover.php $OUT $K <dest.xml>"
fi

# On any red, dump the tail of each failing shard inline: CI keeps only the
# --out dir between steps, and a conservation FAIL without the offender's
# failure block forces a re-run just to read the error.
if [ "$FAILED" -ne 0 ]; then
	for i in $(seq 0 $((K - 1))); do
		[ -f "$OUT/shard-$i.log" ] || continue
		if [ ! -f "$OUT/done-$i" ]; then
			echo "== failing shard $i — last 60 lines =="
			tail -n 60 "$OUT/shard-$i.log"
		fi
	done
fi

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

if [ -n "$AGAINST_JSON" ]; then
	echo "== conservation (shard junit roots vs pinned suite figure) =="
	php -r '
$out = $argv[1]; $K = (int)$argv[2]; $figFile = $argv[3];
$fig = json_decode((string)file_get_contents($figFile), true, 512, JSON_THROW_ON_ERROR);
foreach (["tests", "assertions", "failures", "skipped"] as $k) {
  if (!isset($fig[$k]) || !is_int($fig[$k])) { fwrite(STDERR, "figure missing int key: $k\n"); exit(2); }
}
$sum = ["tests"=>0,"assertions"=>0,"errors"=>0,"failures"=>0,"skipped"=>0,"time"=>0.0];
for ($i=0;$i<$K;$i++){
  $f="$out/junit-$i.xml"; if(!is_file($f)) { fwrite(STDERR,"missing shard junit $i\n"); exit(2); }
  $d=new DOMDocument(); $d->load($f);
  $r=$d->getElementsByTagName("testsuite")->item(0); if(!$r) exit(2);
  foreach(["tests","assertions","errors","failures"] as $k){ $sum[$k]+= (int)($r->getAttribute($k) ?: 0); }
  $sum["time"] += (float)$r->getAttribute("time");
  $x=new DOMXPath($d); $sum["skipped"] += iterator_count($x->query("//testcase/skipped"));
}
printf("shards: tests=%d assertions=%d errors=%d failures=%d skipped=%d sumTime=%.1fs\n",$sum["tests"],$sum["assertions"],$sum["errors"],$sum["failures"],$sum["skipped"],$sum["time"]);
printf("figure: tests=%d assertions=%d errors=0 failures=%d skipped=%d  (%s)\n",$fig["tests"],$fig["assertions"],$fig["failures"],$fig["skipped"],$figFile);
printf("delta:  tests=%+d assertions=%+d\n",$sum["tests"]-$fig["tests"],$sum["assertions"]-$fig["assertions"]);
$ok = $sum["tests"]==$fig["tests"] && $sum["skipped"]==$fig["skipped"]
    && $sum["errors"]==0 && $sum["failures"]==$fig["failures"];
printf("CONSERVATION: %s\n", $ok?"PASS":"FAIL");
exit($ok?0:1);
' "$OUT" "$K" "$AGAINST_JSON" || FAILED=1
fi

exit $FAILED
