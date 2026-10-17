<?php

declare(strict_types=1);

// P7.S4 FP measurement. READ-ONLY against the production classes; every
// alternative matcher lives ONLY in this file. Run with the worktree autoloader.

require '/tmp/p7s4-measure-wt/candy-code/vendor/autoload.php';

use SugarCraft\Code\Skills\Skill;
use SugarCraft\Code\Skills\SkillLoader;
use SugarCraft\Code\Skills\SkillRegistry;
use SugarCraft\Code\Context\Triggers\KeywordTrigger;

// ---------------------------------------------------------------------------
// 1. Build corpus through the REAL loader / registry (native discovery shape).
// ---------------------------------------------------------------------------
$loader = new SkillLoader(false);
$builtIn = $loader->loadBuiltInSkills(); // array<string,Skill>

$registry = new SkillRegistry();
$registry->register($builtIn);
$skills = array_values($registry->all()); // all enabled

echo "== CORPUS INVENTORY ==\n";
printf("builtInSkillsDir source: %s\n", (new ReflectionClass(SkillLoader::class))->getFileName());
printf("skill count: %d\n", count($skills));
foreach ($skills as $s) {
    printf("  [%-22s] autoInvocable=%s userInvocable=%s disableModel=%s\n",
        $s->name,
        $registry->isAutoInvocable($s->name) ? 'y' : 'n',
        $s->userInvocable ? 'y' : 'n',
        $s->disableModelInvocation ? 'y' : 'n');
}
echo "\n";

// ---------------------------------------------------------------------------
// 2. The prompt battery.
// ---------------------------------------------------------------------------
// (a) NEUTRAL - everyday developer/assistant chat, unrelated to any skill.
$neutral = [
    'fix a typo in the README',
    "what's the weather like today",
    'rename the variable foo to bar',
    'commit these changes',
    'hello there',
    'thanks, that works now',
    'list the files in the current directory',
    'what time is it in Tokyo',
    'open the settings file',
    'change the button color from red to blue',
    'ignore the previous instruction',
    'summarize the last message',
    'how do I scroll up in the terminal',
    'make the font size a little bigger',
    'print the current date',
];

// (b) OBVIOUS - one true-positive reference per skill (intended = the skill).
$obvious = [
    'api-design'              => 'design a REST API with JSON:API pagination and auth flows',
    'composer-wizard'         => 'add a composer dependency and fix version constraints',
    'explore-codebase'        => 'help me understand the structure of this unfamiliar lib before editing it',
    'laravel-best-practices'  => 'write Laravel Eloquent query and Blade template following standards',
    'matchups-sync'           => 'sync matchups, a new port landed in the monorepo',
    'mcp-authoring'           => 'add an MCP tool and expose this lib over the protocol',
    'php-best-practices'      => 'review this PHP for PSR-12 compliance and type safety',
    'phpunit-master'          => 'write PHPUnit tests with mocking and data providers',
    'security-audit'          => 'audit this code for SQL injection and XSS vulnerabilities',
    'symfony-best-practices'  => 'configure Symfony services and event dispatcher subscribers',
    'testing-strategies'      => 'set up test suites and mocks, improve test coverage',
    'worktree-workflow'       => 'claim task and create worktree, then open PR',
];

// (c) BOUNDARY - the skill's OWN description token embedded in an off-domain
//     sentence (intent has nothing to do with the skill that fires).
$boundary = [
    'I am reviewing rental applications for the property',
    'the security cameras at the museum were stolen',
    'can you audit my home heating bill please',
    'describe color patterns, especially in tropical birds',
    'the bank offers many services, including checking accounts',
    'handle this package with care at the airport',
    'let me know when you are ready for lunch',
    'the shipping container was unusually heavy today',
    'excellent news coverage. The whole city watched',
    'they used professional mock ups, in the art director deck',
    'composer Schumann wrote lovely lieder',
    'the blade, of grass bent in the wind',
    'long authentication at the theme park gate today',
    'modern furniture design sells well now',
    'the birthday event was canceled last minute',
    'I enjoy working from home on fridays',
    'the setting sun looked beautiful over the lake',
    'please audit the financial books for the cafe',
    'check please under the sofa cushions',
    'the form of the contract is confusing',
    'follow the diplomatic protocol. It really matters',
    'we are designing a new logo for the bakery',
    'the structure of a five paragraph essay',
    'we collected survey responses. Thank you kindly',
    'the museum docent explained the ancient mosaic',
];

// ---------------------------------------------------------------------------
// 3. Tokenizer + predicate replication for the CURRENT crude matcher.
//    (mirrors Skill::matchesPrompt exactly)
// ---------------------------------------------------------------------------
function crudeTokens(string $description): array {
    return array_values(array_filter(explode(' ', strtolower($description))));
}
// tokens that are actually ELIGIBLE as needles under the >3 byte rule
function crudeNeedles(string $description): array {
    return array_values(array_filter(crudeTokens($description), fn($k) => strlen($k) > 3));
}
// return the list of needles that match this prompt (first one is what returns true)
function crudeMatchTokens(string $description, string $prompt): array {
    $hits = [];
    foreach (crudeNeedles($description) as $k) {
        if (stripos($prompt, $k) !== false) {
            $hits[] = $k;
        }
    }
    return $hits;
}

// Whole-word variants (implemented ONLY here) ---------------------------------
// Variant W1: minimal diff - SAME needles (>3 byte tokens incl. punctuation),
//             predicate swapped to \b...\b whole word.
function wwMatchTokens_sameNeedles(Skill $s, string $prompt): array {
    $hits = [];
    foreach (crudeNeedles($s->description) as $k) {
        $q = preg_quote($k, '/');
        if (@preg_match('/\b' . $q . '\b/iu', $prompt) === 1) {
            $hits[] = $k;
        }
    }
    return $hits;
}
// Variant W2: realistic improved matcher - extract clean word tokens (\p{L}\p{N}+),
//             keep >3, feed KeywordTrigger (the actual P6.S1 class), whole word.
function cleanWords(string $description): array {
    preg_match_all('/[\p{L}\p{N}]+/u', mb_strtolower($description), $m);
    return array_values(array_unique(array_filter($m[0], fn($w) => strlen($w) > 3)));
}
function wwMatchTokens_clean(Skill $s, string $prompt): array {
    $words = cleanWords($s->description);
    if ($words === []) {
        return [];
    }
    $trigger = KeywordTrigger::new($words);
    return $trigger->matchedWords($prompt);
}

// ---------------------------------------------------------------------------
// 4. Run the battery. Collect (skill,prompt) match pairs + triggering token.
// ---------------------------------------------------------------------------
/**
 * currentFindForPrompt: faithful to registry.findForPrompt (set of names).
 */
function currentMatches(SkillRegistry $registry, string $prompt): array {
    return array_map(fn(Skill $s) => $s->name, $registry->findForPrompt($prompt));
}
function variantMatches(array $skills, string $prompt, callable $pred): array {
    $out = [];
    foreach ($skills as $s) {
        if ($pred($s, $prompt) !== []) {
            $out[] = $s->name;
        }
    }
    return $out;
}

$predW1 = fn(Skill $s, string $p) => wwMatchTokens_sameNeedles($s, $p);
$predW2 = fn(Skill $s, string $p) => wwMatchTokens_clean($s, $p);

$rows = []; // per prompt: category, intended, matches per system
$systems = ['CURRENT', 'WW_sameNeedles', 'WW_cleanWords'];

function record(array &$rows, string $cat, string $prompt, ?string $intended, array $perSystem): void {
    $rows[] = ['cat' => $cat, 'prompt' => $prompt, 'intended' => $intended, 'sets' => $perSystem];
}

foreach ($neutral as $p) {
    record($rows, 'neutral', $p, null, [
        'CURRENT' => currentMatches($registry, $p),
        'WW_sameNeedles' => variantMatches($skills, $p, $predW1),
        'WW_cleanWords' => variantMatches($skills, $p, $predW2),
    ]);
}
foreach ($obvious as $intended => $p) {
    record($rows, 'obvious', $p, $intended, [
        'CURRENT' => currentMatches($registry, $p),
        'WW_sameNeedles' => variantMatches($skills, $p, $predW1),
        'WW_cleanWords' => variantMatches($skills, $p, $predW2),
    ]);
}
foreach ($boundary as $p) {
    record($rows, 'boundary', $p, null, [
        'CURRENT' => currentMatches($registry, $p),
        'WW_sameNeedles' => variantMatches($skills, $p, $predW1),
        'WW_cleanWords' => variantMatches($skills, $p, $predW2),
    ]);
}

// ---------------------------------------------------------------------------
// 5. Detail: every CURRENT (skill,prompt) pair that matched, with token.
// ---------------------------------------------------------------------------
echo "== CURRENT matcher: matched pairs (skill | category | triggering needles) ==\n";
foreach ($rows as $r) {
    if ($r['sets']['CURRENT'] === []) {
        continue;
    }
    foreach ($r['sets']['CURRENT'] as $name) {
        $s = null;
        foreach ($skills as $cand) { if ($cand->name === $name) { $s = $cand; break; } }
        $needles = crudeMatchTokens($s->description, $r['prompt']);
        printf("  %-22s %-8s  first=%-14s all=[%s]  <= %s\n",
            $name, $r['cat'], $needles[0] ?? '?', implode(',', $needles), $r['prompt']);
    }
}
echo "\n";

// ---------------------------------------------------------------------------
// 6. Aggregate metrics per system.
// ---------------------------------------------------------------------------
function metrics(array $rows, string $sys): array {
    $neutralFire = 0; $boundaryFire = 0;
    $neutralPairs = 0; $boundaryPairs = 0;
    $obviousRecallHit = 0; $obviousTotal = 0;
    $obviousExtraPairs = 0;   // non-intended skills fired on obvious prompts
    $TP = 0; $FP = 0; $FN = 0; $TN = 0;
    foreach ($rows as $r) {
        $set = $r['sets'][$sys];
        $n = count($set);
        if ($r['cat'] === 'neutral') {
            if ($n > 0) { $neutralFire++; $neutralPairs += $n; }
            $FP += $n; $TN += (12 - $n);
        } elseif ($r['cat'] === 'boundary') {
            if ($n > 0) { $boundaryFire++; $boundaryPairs += $n; }
            $FP += $n; $TN += (12 - $n);
        } else { // obvious
            $obviousTotal++;
            $intended = $r['intended'];
            $hit = in_array($intended, $set, true);
            if ($hit) { $obviousRecallHit++; $TP++; } else { $FN++; }
            $extra = $n - ($hit ? 1 : 0);
            $obviousExtraPairs += $extra;
            $FP += $extra;
            $TN += (12 - $n);
        }
    }
    $precision = ($TP + $FP) > 0 ? $TP / ($TP + $FP) : 1.0;
    $recall = $obviousTotal > 0 ? $TP / $obviousTotal : 0.0;
    return [
        'neutral_total' => count(array_filter($rows, fn($r) => $r['cat'] === 'neutral')),
        'neutral_fire' => $neutralFire,
        'neutral_pairs' => $neutralPairs,
        'boundary_total' => count(array_filter($rows, fn($r) => $r['cat'] === 'boundary')),
        'boundary_fire' => $boundaryFire,
        'boundary_pairs' => $boundaryPairs,
        'obvious_total' => $obviousTotal,
        'obvious_recall_hit' => $obviousRecallHit,
        'obvious_extra_pairs' => $obviousExtraPairs,
        'TP' => $TP, 'FP' => $FP, 'FN' => $FN, 'TN' => $TN,
        'precision' => $precision, 'recall' => $recall,
    ];
}

$summary = [];
foreach ($systems as $sys) {
    $summary[$sys] = metrics($rows, $sys);
}

echo "== SUMMARY METRICS ==\n";
$hdr = ["metric", ...$systems];
printf("  %-22s %16s %16s %16s\n", ...$hdr);
$keys = ['neutral_total','neutral_fire','neutral_pairs','boundary_total','boundary_fire',
         'boundary_pairs','obvious_total','obvious_recall_hit','obvious_extra_pairs',
         'TP','FP','FN','TN','precision','recall'];
foreach ($keys as $k) {
    printf("  %-22s %16s %16s %16s\n", $k,
        round($summary['CURRENT'][$k], 4),
        round($summary['WW_sameNeedles'][$k], 4),
        round($summary['WW_cleanWords'][$k], 4));
}
echo "\n";

// Headline rates
foreach ($systems as $sys) {
    $m = $summary[$sys];
    printf("[%s] FP/neutral = %d/%d (%s%%)  FP/boundary = %d/%d (%s%%)  precision = %.3f  recall = %.3f\n",
        $sys,
        $m['neutral_fire'], $m['neutral_total'], round(100 * $m['neutral_fire'] / $m['neutral_total'], 1),
        $m['boundary_fire'], $m['boundary_total'], round(100 * $m['boundary_fire'] / $m['boundary_total'], 1),
        $m['precision'], $m['recall']);
}
echo "\n";

// ---------------------------------------------------------------------------
// 7. Token-length distribution of what the >3 substring rule lets through.
// ---------------------------------------------------------------------------
echo "== NEEDLE INVENTORY (crude rule: lowercased description token, strlen>3) ==\n";
$byLen = [];
$cleanCommon = [];
$COMMON = ['when','with','other','best','modern','check','audit','security','service','services',
    'container','structure','form','event','working','setting','patterns','responses','designing',
    'creating','writing','reviewing','organization','configur','handling','issues','version',
    'constraints','management','conventions','dependencies','standards','templates','optimization',
    'implementing','structuring','analyzing','mocking','providers','autoloading','authentication',
    'compliance','safety','vulnerabilities','injection','subscribers','dispatcher','definition'];
foreach ($skills as $s) {
    foreach (crudeNeedles($s->description) as $k) {
        $byLen[strlen($k)] = ($byLen[strlen($k)] ?? 0) + 1;
        $bare = trim($k, " \t.,;:!?'\"()[]{}");
        if ($bare === $k) { // clean (no adjacent punctuation)
            $cleanCommon[$s->name][] = $k;
        }
    }
}
ksort($byLen);
echo "  length -> count: ";
foreach ($byLen as $len => $cnt) { echo "$len:$cnt "; }
echo "\n";
$totNeedles = array_sum($byLen);
$cleanCount = 0; foreach ($cleanCommon as $l) { $cleanCount += count($l); }
printf("  total needles(>3) across corpus: %d ; clean (no punctuation) : %d ; punctuation-bound : %d\n",
    $totNeedles, $cleanCount, $totNeedles - $cleanCount);
echo "\n  CLEAN common-word needles (fire on plain prose, no punctuation shield) by skill:\n";
foreach ($cleanCommon as $name => $list) {
    $commonHere = array_values(array_intersect($list, $COMMON));
    printf("    %-22s clean=%-2d  common=[%s]\n", $name, count($list), implode(' ', $commonHere));
}
echo "\n";

// ---------------------------------------------------------------------------
// 8. Worst offender pairs (CURRENT): (skill,prompt) where match fired but the
//    category says it should not. Rank by how generic the triggering needle is.
// ---------------------------------------------------------------------------
echo "== WORST CURRENT (skill,prompt) false pairs (off-domain categories) ==\n";
$worst = [];
foreach ($rows as $r) {
    if ($r['cat'] === 'obvious') { continue; }
    foreach ($r['sets']['CURRENT'] as $name) {
        $s = null; foreach ($skills as $c) { if ($c->name === $name) { $s = $c; break; } }
        foreach (crudeMatchTokens($s->description, $r['prompt']) as $needle) {
            $bare = trim($needle, " \t.,;:!?'\"()[]{}");
            // genericness: clean + a common English function/everyday word
            $score = ($bare === $needle ? 2 : 0) + (in_array($bare, $COMMON, true) ? 3 : 0) + max(0, 6 - strlen($bare));
            $worst[] = ['skill'=>$name,'cat'=>$r['cat'],'needle'=>$needle,'score'=>$score,'prompt'=>$r['prompt']];
        }
    }
}
usort($worst, fn($a,$b) => $b['score'] <=> $a['score']);
$seen = [];
$shown = 0;
foreach ($worst as $w) {
    $key = $w['skill'].'|'.$w['needle'].'|'.$w['prompt'];
    if (isset($seen[$key])) { continue; }
    $seen[$key] = 1;
    printf("  score=%d  %-20s needle=%-16s [%s] \"%s\"\n",
        $w['score'], $w['skill'], "'".$w['needle']."'", $w['cat'], $w['prompt']);
    if (++$shown >= 20) { break; }
}
echo "\n";

// ---------------------------------------------------------------------------
// 9. Per-system diff table for the neutral+boundary rows (did WW fix them?).
// ---------------------------------------------------------------------------
echo "== PER-PROMPT set sizes CURRENT vs WW_sameNeedles vs WW_cleanWords (off-domain only) ==\n";
foreach ($rows as $r) {
    if ($r['cat'] === 'obvious') { continue; }
    $c = count($r['sets']['CURRENT']);
    $w1 = count($r['sets']['WW_sameNeedles']);
    $w2 = count($r['sets']['WW_cleanWords']);
    if ($c === 0 && $w1 === 0 && $w2 === 0) { continue; }
    printf("  [%-8s] C=%2d W1=%2d W2=%2d  \"%s\"\n", $r['cat'], $c, $w1, $w2, $r['prompt']);
}
echo "\n";

// 9b. Decisive split: for every CURRENT off-domain FALSE (skill,prompt,needle),
//     would whole-word anchoring REMOVE it (substring artifact) or KEEP it
//     (the bare word genuinely appears as a whole word in the prompt)?
echo "== WHOLE-WORD FIXABILITY of CURRENT false pairs (neutral+boundary) ==\n";
$artifact = 0; $genuine = 0;
$genuineWords = [];
$artifactPairs = [];
foreach ($rows as $r) {
    if ($r['cat'] === 'obvious') { continue; }
    foreach ($r['sets']['CURRENT'] as $name) {
        $s = null; foreach ($skills as $c) { if ($c->name === $name) { $s = $c; break; } }
        foreach (crudeMatchTokens($s->description, $r['prompt']) as $needle) {
            $bare = trim($needle, " \t.,;:!?'\"()[]{}");
            $wholeWordPresent = @preg_match('/\b' . preg_quote($bare, '/') . '\b/iu', $r['prompt']) === 1;
            if ($wholeWordPresent) {
                $genuine++;
                $genuineWords[$bare] = ($genuineWords[$bare] ?? 0) + 1;
            } else {
                $artifact++;
                $artifactPairs[] = "$name <- '$needle' in \"$r[prompt]\"";
            }
        }
    }
}
$totalFalse = $artifact + $genuine;
printf("  CURRENT off-domain false (skill,prompt,needle) pairs: %d\n", $totalFalse);
printf("  whole-word REMOVES (substring artifact): %d  (%s%%)\n", $artifact, round(100 * $artifact / max(1, $totalFalse), 1));
printf("  whole-word KEEPS  (common word really in prompt): %d  (%s%%)\n", $genuine, round(100 * $genuine / max(1, $totalFalse), 1));
arsort($genuineWords);
echo "  most frequent WHOLE-WORD-PROOF triggers (word -> #false-pairs): \n    ";
$i = 0;
foreach ($genuineWords as $w => $c) { printf("'%s':%d  ", $w, $c); if (++$i % 10 === 0) { echo "\n    "; } }
echo "\n\n  substring artifacts whole-word would delete:\n";
foreach ($artifactPairs as $p) { echo "    $p\n"; }
echo "\n";

// 10. Which obvious skills the whole-word-clean variant LOSES (recall drop)
echo "== WW_cleanWords: obvious prompts that NO LONGER fire intended skill ==\n";
foreach ($rows as $r) {
    if ($r['cat'] !== 'obvious') { continue; }
    $hitC = in_array($r['intended'], $r['sets']['CURRENT'], true);
    $hitW = in_array($r['intended'], $r['sets']['WW_cleanWords'], true);
    if ($hitC && !$hitW) {
        printf("  LOST: intended=%s  WWclean set=[%s]  \"%s\"\n",
            $r['intended'], implode(',', $r['sets']['WW_cleanWords']), $r['prompt']);
    }
}
echo "DONE\n";
