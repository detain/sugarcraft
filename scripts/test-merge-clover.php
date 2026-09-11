#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Self-check for scripts/merge-clover.php (E691).
 *
 * Not a PHPUnit test — merge-clover.php is a CI script, not suite code, and
 * the sugar-crush suite figure is deliberately untouched by this lane (+0T).
 * This script is invoked by the sharded coverage job BEFORE the expensive
 * merge of real data, so a merger regression fails in seconds.
 *
 * What it pins, on synthetic two-shard fixtures with hand-computed unions:
 *  1. count summation (covered-in-either-shard and covered-in-both cases);
 *  2. crap recomputation after merge (covered -> m, uncovered -> m*(m+1));
 *  3. cond truecount/falsecount summation + covered derivation;
 *  4. a file present in only one shard still lands in the union;
 *  5. file <metrics> == independent recomputation from the emitted <line>
 *     elements (double-entry bookkeeping), for every merged file;
 *  6. project <metrics> == sum over emitted files, and the elements identity;
 *  7. single-class files recompute class covered* exactly; multi-class files
 *     take the conservative max-across-shards floor (never a shard sum);
 *  8. determinism: merging the same inputs twice yields byte-identical output
 *     (generated/timestamp are carried from shard 0, not "now");
 *  9. fail-closed negative controls: missing shard clover and malformed XML
 *     both exit 2 with no destination written; wrong argc exits 1.
 *
 * Usage: php scripts/test-merge-clover.php   (exit 0 = all checks pass)
 */

$checks = 0;

function check(bool $condition, string $label): void
{
    global $checks;
    $checks++;
    if (!$condition) {
        fwrite(STDERR, "test-merge-clover: FAILED — {$label}\n");
        exit(1);
    }
}

function runMerge(string $dir, string $k, string $dest): array
{
    $cmd = sprintf(
        'php %s %s %s %s 2>&1',
        escapeshellarg(__DIR__ . '/merge-clover.php'),
        escapeshellarg($dir),
        escapeshellarg($k),
        escapeshellarg($dest)
    );
    exec($cmd, $output, $rc);

    return [$rc, implode("\n", $output)];
}

function putClover(string $path, string $body): void
{
    file_put_contents($path, "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<coverage generated=\"1700000000\">\n<project timestamp=\"1700000000\">\n{$body}\n</project>\n</coverage>\n");
}

$dir = sys_get_temp_dir() . '/merge-clover-selfcheck-' . getmypid();
check(is_dir($dir) || mkdir($dir, 0o755, true) || is_dir($dir), "fixture dir {$dir}");

// ---------- shard 0 ----------
putClover("{$dir}/clover-0.xml", <<<'XML'
  <file name="/root/A.php">
    <class name="A" namespace="global"><metrics complexity="6" methods="2" coveredmethods="0" conditionals="1" coveredconditionals="0" statements="2" coveredstatements="1" elements="5" coveredelements="1"/></class>
    <line num="10" type="stmt" count="0"/>
    <line num="12" type="stmt" count="1"/>
    <line num="20" type="method" name="m1" visibility="public" complexity="3" crap="12" count="0"/>
    <line num="30" type="method" name="m2" visibility="public" complexity="2" crap="6" count="0"/>
    <line num="40" type="cond" name="0" truecount="0" falsecount="1"/>
    <metrics loc="50" ncloc="30" classes="1" methods="2" coveredmethods="0" conditionals="1" coveredconditionals="0" statements="2" coveredstatements="1" elements="5" coveredelements="1"/>
  </file>
  <file name="/root/B.php">
    <class name="B" namespace="global"><metrics complexity="1" methods="1" coveredmethods="1" conditionals="0" coveredconditionals="0" statements="1" coveredstatements="1" elements="2" coveredelements="2"/></class>
    <line num="5" type="method" name="b" visibility="public" complexity="1" crap="1" count="4"/>
    <line num="6" type="stmt" count="7"/>
    <metrics loc="10" ncloc="6" classes="1" methods="1" coveredmethods="1" conditionals="0" coveredconditionals="0" statements="1" coveredstatements="1" elements="2" coveredelements="2"/>
  </file>
  <file name="/root/M.php">
    <class name="M1" namespace="global"><metrics complexity="1" methods="1" coveredmethods="1" conditionals="0" coveredconditionals="0" statements="1" coveredstatements="1" elements="2" coveredelements="2"/></class>
    <class name="M2" namespace="global"><metrics complexity="1" methods="1" coveredmethods="0" conditionals="0" coveredconditionals="0" statements="1" coveredstatements="0" elements="2" coveredelements="0"/></class>
    <line num="3" type="method" name="x" visibility="public" complexity="1" crap="1" count="2"/>
    <line num="4" type="stmt" count="2"/>
    <line num="8" type="method" name="y" visibility="public" complexity="1" crap="2" count="0"/>
    <line num="9" type="stmt" count="0"/>
    <metrics loc="20" ncloc="12" classes="2" methods="2" coveredmethods="1" conditionals="0" coveredconditionals="0" statements="2" coveredstatements="1" elements="4" coveredelements="2"/>
  </file>
  <metrics files="3" loc="80" ncloc="48" classes="4" methods="5" coveredmethods="2" conditionals="1" coveredconditionals="0" statements="5" coveredstatements="3" elements="11" coveredelements="5"/>
XML);

// ---------- shard 1: disjoint hits; A.m1 now covered; M flipped; C appears ----------
putClover("{$dir}/clover-1.xml", <<<'XML'
  <file name="/root/A.php">
    <class name="A" namespace="global"><metrics complexity="6" methods="2" coveredmethods="1" conditionals="1" coveredconditionals="1" statements="2" coveredstatements="1" elements="5" coveredelements="3"/></class>
    <line num="10" type="stmt" count="2"/>
    <line num="12" type="stmt" count="3"/>
    <line num="20" type="method" name="m1" visibility="public" complexity="3" crap="3" count="1"/>
    <line num="30" type="method" name="m2" visibility="public" complexity="2" crap="6" count="0"/>
    <line num="40" type="cond" name="0" truecount="1" falsecount="0"/>
    <metrics loc="50" ncloc="30" classes="1" methods="2" coveredmethods="1" conditionals="1" coveredconditionals="1" statements="2" coveredstatements="1" elements="5" coveredelements="3"/>
  </file>
  <file name="/root/B.php">
    <class name="B" namespace="global"><metrics complexity="1" methods="1" coveredmethods="1" conditionals="0" coveredconditionals="0" statements="1" coveredstatements="1" elements="2" coveredelements="2"/></class>
    <line num="5" type="method" name="b" visibility="public" complexity="1" crap="1" count="1"/>
    <line num="6" type="stmt" count="1"/>
    <metrics loc="10" ncloc="6" classes="1" methods="1" coveredmethods="1" conditionals="0" coveredconditionals="0" statements="1" coveredstatements="1" elements="2" coveredelements="2"/>
  </file>
  <file name="/root/M.php">
    <class name="M1" namespace="global"><metrics complexity="1" methods="1" coveredmethods="0" conditionals="0" coveredconditionals="0" statements="1" coveredstatements="0" elements="2" coveredelements="0"/></class>
    <class name="M2" namespace="global"><metrics complexity="1" methods="1" coveredmethods="1" conditionals="0" coveredconditionals="0" statements="1" coveredstatements="1" elements="2" coveredelements="2"/></class>
    <line num="3" type="method" name="x" visibility="public" complexity="1" crap="1" count="0"/>
    <line num="4" type="stmt" count="0"/>
    <line num="8" type="method" name="y" visibility="public" complexity="1" crap="1" count="5"/>
    <line num="9" type="stmt" count="7"/>
    <metrics loc="20" ncloc="12" classes="2" methods="2" coveredmethods="1" conditionals="0" coveredconditionals="0" statements="2" coveredstatements="1" elements="4" coveredelements="2"/>
  </file>
  <file name="/root/C.php">
    <class name="C" namespace="global"><metrics complexity="1" methods="1" coveredmethods="1" conditionals="0" coveredconditionals="0" statements="1" coveredstatements="1" elements="2" coveredelements="2"/></class>
    <line num="1" type="method" name="c" visibility="public" complexity="1" crap="1" count="5"/>
    <line num="2" type="stmt" count="5"/>
    <metrics loc="8" ncloc="5" classes="1" methods="1" coveredmethods="1" conditionals="0" coveredconditionals="0" statements="1" coveredstatements="1" elements="2" coveredelements="2"/>
  </file>
  <metrics files="4" loc="88" ncloc="53" classes="5" methods="6" coveredmethods="4" conditionals="1" coveredconditionals="1" statements="6" coveredstatements="4" elements="13" coveredelements="9"/>
XML);

// ---------- happy path ----------
$dest = "{$dir}/merged.xml";
[$rc, $log] = runMerge($dir, '2', $dest);
check($rc === 0, "merge exit 0 (log: {$log})");
check(is_file($dest), 'merged file exists');

$xml = simplexml_load_file($dest);
check($xml !== false, 'merged output is well-formed XML');
$root = $xml; // simplexml returns the document element itself: <coverage>
check($root->getName() === 'coverage', '<coverage> root present');
check((string) $root['generated'] === '1700000000', 'generated carried from shard 0');

$byName = [];
foreach ($root->project->file as $f) {
    $byName[(string) $f['name']] = $f;
}
check(array_keys($byName) === ['/root/A.php', '/root/B.php', '/root/M.php', '/root/C.php'], 'file order = shard-0 order, shard-only file appended');

$a = $byName['/root/A.php'];
$lines = [];
foreach ($a->line as $l) {
    $lines[(int) $l['num']] = $l;
}
check((string) $lines[10]['count'] === '2', 'A:10 stmt count 0+2 summed');
check((string) $lines[12]['count'] === '4', 'A:12 stmt count 1+3 summed');
check((string) $lines[20]['count'] === '1' && (string) $lines[20]['crap'] === '3', 'A:20 method covered after merge -> crap 12 recomputed to 3');
check((string) $lines[30]['count'] === '0' && (string) $lines[30]['crap'] === '6', 'A:30 method still uncovered -> crap m*(m+1)=6');
check((string) $lines[40]['truecount'] === '1' && (string) $lines[40]['falsecount'] === '1', 'A:40 cond branches summed 0+1 / 1+0');

// double-entry: recompute every file's metrics from its emitted lines, compare.
$projectSum = ['files' => 0, 'loc' => 0, 'ncloc' => 0, 'classes' => 0, 'methods' => 0, 'coveredmethods' => 0, 'conditionals' => 0, 'coveredconditionals' => 0, 'statements' => 0, 'coveredstatements' => 0, 'elements' => 0, 'coveredelements' => 0];
foreach ($byName as $name => $f) {
    $derived = ['methods' => 0, 'coveredmethods' => 0, 'statements' => 0, 'coveredstatements' => 0, 'conditionals' => 0, 'coveredconditionals' => 0];
    foreach ($f->line as $l) {
        $type = (string) $l['type'];
        $covered = $type === 'cond'
            ? ((int) $l['truecount'] + (int) $l['falsecount'] > 0)
            : ((int) $l['count'] > 0);
        $derived[$type === 'method' ? 'methods' : ($type === 'cond' ? 'conditionals' : 'statements')]++;
        $derived['covered' . ($type === 'method' ? 'methods' : ($type === 'cond' ? 'conditionals' : 'statements'))] += $covered ? 1 : 0;
    }
    $m = $f->metrics;
    foreach ($derived as $k => $v) {
        check((int) $m[$k] === $v, "{$name}: metrics {$k} == recomputed from lines ({$v})");
    }
    check((int) $m['elements'] === (int) $m['methods'] + (int) $m['statements'] + (int) $m['conditionals'], "{$name}: elements identity");
    check((int) $m['coveredelements'] === (int) $m['coveredmethods'] + (int) $m['coveredstatements'] + (int) $m['coveredconditionals'], "{$name}: coveredelements identity");
    foreach (array_keys($projectSum) as $k) {
        if ($k === 'files') {
            $projectSum['files']++;
        } else {
            $projectSum[$k] += (int) $m[$k];
        }
    }
}
$pm = $root->project->metrics;
foreach ($projectSum as $k => $v) {
    check((int) $pm[$k] === $v, "project metrics {$k} == sum over files ({$v})");
}

// line-sort order within a file
$nums = [];
foreach ($a->line as $l) {
    $nums[] = (int) $l['num'];
}
check($nums === [10, 12, 20, 30, 40], 'A: lines sorted by num');

// single-class recompute vs multi-class conservative floor
$bClasses = $byName['/root/B.php']->class;
check((int) $bClasses[0]->metrics['coveredstatements'] === 1 && (int) $bClasses[0]->metrics['coveredmethods'] === 1, 'B: single-class covered* recomputed exactly');
$m = $byName['/root/M.php']->class;
check((string) $m[0]['name'] === 'M1' && (int) $m[0]->metrics['coveredmethods'] === 1, 'M: multi-class M1 coveredmethods = max(1,0) not sum');
check((string) $m[1]['name'] === 'M2' && (int) $m[1]->metrics['coveredmethods'] === 1, 'M: multi-class M2 coveredmethods = max(0,1) not sum');

// C (shard-1-only file) still fully present
$c = $byName['/root/C.php'];
check((int) $c->line[0]['count'] === 5 && (int) $c->metrics['coveredstatements'] === 1, 'C: shard-only file merged intact');

// ---------- determinism ----------
$dest2 = "{$dir}/merged-again.xml";
[$rc2, ] = runMerge($dir, '2', $dest2);
check($rc2 === 0, 'second merge exit 0');
check((string) file_get_contents($dest) === (string) file_get_contents($dest2), 're-merge byte-identical (generated/timestamp not clock-based)');

// ---------- negative controls (fail-closed) ----------
unlink($dest);
[$rc3, $log3] = runMerge($dir, '3', $dest);
check($rc3 === 2 && !is_file($dest), "missing shard clover exits 2, nothing written (rc={$rc3} log={$log3})");

@mkdir("{$dir}/broken");
putClover("{$dir}/broken/clover-0.xml", '<coverage><project>');
[$rc4, ] = runMerge("{$dir}/broken", '1', $dest);
check($rc4 === 2 && !is_file($dest), 'malformed XML exits 2, nothing written');

$badUsage = sprintf('php %s onlytwoargs 2>&1', escapeshellarg(__DIR__ . '/merge-clover.php'));
exec($badUsage, $badOut, $rc5);
check($rc5 === 1, 'wrong argc exits 1');

// cleanup fixtures (best effort — CI runners are ephemeral, dev boxes accumulate nothing)
foreach (glob("{$dir}/*") ?: [] as $entry) {
    is_dir($entry) ? @rmdir($entry) : @unlink($entry);
}
@rmdir($dir);

printf("test-merge-clover: OK (%d checks)\n", $checks);
exit(0);
