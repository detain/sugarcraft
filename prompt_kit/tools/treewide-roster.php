<?php
declare(strict_types=1);
/**
 * F1 GENERATOR — derive the tree-wide-guard roster from the tree.
 * §16.8 rule 15: derive the roster, never hand-maintain it.
 *
 * A tree-wide guard is a test whose verdict is a function of the WHOLE file
 * population of this library, so it is in no step's declared file list and
 * no step-scoped review runs it. Two channels, both structural:
 *
 *   A. it `use`s the shared walker trait `Support\TestFileWalkTrait`
 *      (`everyTestFile()`), or
 *   B. its own code opens a directory iterator / glob / scandir AND anchors
 *      that walk at the package root (`dirname(__DIR__)`, `inPackageRoot()`,
 *      `packageRoot()`), rather than at a temp dir the test just made.
 */
$root = '/home/sites/prompt-step-P3.CLOSE-r1/sugar-crush';
$walkers = ['RecursiveDirectoryIterator', 'DirectoryIterator', 'FilesystemIterator', 'glob', 'scandir', 'readdir'];
$anchorRe = '/dirname\(__DIR__|inPackageRoot|packageRoot|__DIR__ \. \'\/\.\./';

$a = []; $b = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/tests', FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if ($f->getExtension() !== 'php' || !str_ends_with($f->getFilename(), 'Test.php')) { continue; }
    $rel = str_replace($root . '/', '', $f->getPathname());
    $src = (string) file_get_contents($f->getPathname());
    $tokens = token_get_all($src);

    $usesTrait = false; $hasWalker = false;
    foreach ($tokens as $t) {
        if (!is_array($t)) { continue; }
        if (in_array($t[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
            $bare = ltrim($t[1], '\\');
            if (str_ends_with($bare, 'TestFileWalkTrait')) { $usesTrait = true; }
            foreach ($walkers as $w) { if (strcasecmp($bare, $w) === 0) { $hasWalker = true; } }
        }
    }
    if ($usesTrait) { $a[] = $rel; continue; }
    if ($hasWalker && preg_match($anchorRe, $src) === 1) { $b[] = $rel; }
}
sort($a); sort($b);
$census = [
 'tests/SymbolCitationDriftTest.php','tests/SwallowingCatchCensusTest.php',
 'tests/Support/DuplicatedTestHelperDriftTest.php','tests/Support/ChildWallClockBudgetTest.php',
 'tests/Config/EnvRosterDriftTest.php','tests/Tools/BuiltInToolCorpusTest.php',
 'tests/Support/InterpolationOpenerTokenTest.php','tests/Support/ChildStderrCaptureTest.php',
 'tests/Config/GlobFigureDriftTest.php',
];
$all = array_values(array_unique(array_merge($a, $b))); sort($all);
echo "CHANNEL A (shared walker trait): ", count($a), "\n"; foreach ($a as $r) { echo ($in = in_array($r,$census,true)) ? '  [in list]  ' : '  MISSING    ', $r, "\n"; }
echo "\nCHANNEL B (own root-anchored walk): ", count($b), "\n"; foreach ($b as $r) { echo in_array($r,$census,true) ? '  [in list]  ' : '  MISSING    ', $r, "\n"; }
echo "\nDERIVED TOTAL: ", count($all), "   census list: ", count($census), "\n";
echo "IN THE LIST BUT NOT DERIVED (my detector's own misses):\n";
foreach (array_diff($census, $all) as $r) { echo '  ', $r, "\n"; }
