<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tools;

// Fixture driver executed as a SUBPROCESS by
// {@see BuiltInToolCorpusTest::testTheScannerSurvivesAMisnamespacedFileUnderTheRealComposerAutoloader()}
// and the wired-directory test beside it. Never by PHPUnit directly: no `Test`
// suffix, so it is not collected.
//
// WHY A SUBPROCESS AT ALL: the defect this drives out is PROCESS-LEVEL. The
// scanner's old gate asked `class_exists() && interface_exists() &&
// trait_exists()` of a name the file does not declare; each probe made COMPOSER's
// autoloader `include` the same file again (its `includeFile()` is a plain
// `include`, not `include_once`), and the second include redeclares the class the
// first one defined — a fatal that kills the runner before any assertion runs.
// That cannot be observed from inside the process that dies, and it cannot be
// reproduced by the suite's own synthetic probe autoloader, which uses
// `require_once` and so escaped it. This driver therefore registers its probe
// trees on the REAL composer ClassLoader and crashes, or does not, exactly as a
// real suite run would.
//
// Contract with the test that spawns it: argv[1] is an empty scratch root; the
// driver writes two temp trees under it, walks each through the scanner, prints
// `PHASE`/`RESULT` markers, and exits 0 on arrival. A pre-fix scanner never
// reaches the DONE marker: the process dies with rc 255 and a redeclare fatal on
// stderr.

if ($argc !== 2) {
    fwrite(STDERR, "usage: corpus-real-autoloader-driver.php <scratch-root>\n");
    exit(2);
}

$root = $argv[1];
if (!is_dir($root)) {
    fwrite(STDERR, "scratch root does not exist: {$root}\n");
    exit(2);
}

$loader = require \dirname(__DIR__, 2) . '/vendor/autoload.php';

/**
 * Write `<tree>/src/...` files for one probe tree: a well-formed anchor tool so
 * the corpus is never empty, and one file whose declared namespace disagrees
 * with its path. $place decides WHERE the bad file lands — the wired tool
 * directory (a named throw is the honest answer) or elsewhere (a reported
 * exemption is).
 */
$buildTree = static function (string $dir, string $prefix, string $place): void {
    $tool = static fn (string $namespace, string $class): string => <<<PHP
        <?php

        declare(strict_types=1);

        namespace {$namespace};

        use SugarCraft\Crush\Tools\Tool;
        use SugarCraft\Crush\Tools\ToolResult;

        final class {$class} implements Tool
        {
            public function name(): string { return strtolower('{$class}'); }
            public function description(): string { return strtolower('{$class}'); }
            public function inputSchema(): array { return []; }
            public function execute(array \$args): ToolResult { return ToolResult::error('{$class}'); }
        }
        PHP;

    mkdir($dir . '/src/Tools/BuiltIn', 0o777, true);
    file_put_contents($dir . '/src/Tools/BuiltIn/Anchor.php', $tool($prefix . 'Tools\BuiltIn', 'Anchor'));

    $ghostNamespace = $prefix . ('Tools/BuiltIn' === $place ? 'Elsewhere' : 'Haunted');
    if (!is_dir($dir . '/src/' . $place)) {
        mkdir($dir . '/src/' . $place, 0o777, true);
    }
    file_put_contents(
        $dir . '/src/' . $place . '/Ghost.php',
        "<?php\n\ndeclare(strict_types=1);\n\nnamespace {$ghostNamespace};\n\nfinal class Ghost\n{\n}\n",
    );
};

$echo = static function (string $line): void {
    echo $line, "\n";
};

// ─── tree A: mis-namespaced file OUTSIDE the wired directory ─────────
$dirA = $root . '/a';
$buildTree($dirA, 'CorpusRealAutoloadProbeA\\', 'Support');
$loader->addPsr4('CorpusRealAutoloadProbeA\\', $dirA . '/src');

$echo('PHASE A:classNames');
$classes = BuiltInToolCorpus::classNames($dirA . '/src', 'CorpusRealAutoloadProbeA\\');
$echo('RESULT A_CLASSES=' . implode(',', $classes));

$echo('PHASE A:nonClassSources');
$exempt = BuiltInToolCorpus::nonClassSources($dirA . '/src', 'CorpusRealAutoloadProbeA\\');
$echo('RESULT A_EXEMPT=' . implode(',', $exempt));

// ─── PHASE C: the REFLECTION census instrument, over the poisoned tree ─
// E636's hazard was that the balance test could never speak because the
// reflection census above it took the runner down FIRST on a mis-namespaced
// file. That ordering is moot only if the census itself cannot fatal — so this
// phase drives the census's real classifier
// ({@see BuiltInToolCorpusTest::classifyFilePsr4Symbol()}) over tree A: the
// mis-namespaced file must answer `none` and the good file `concrete`, and the
// subprocess must still arrive at DONE. Pre-fix this is where a second rc-255
// would land even if the scanner's own gate survived.
$echo('PHASE C:census-instrument');
$kinds = [];
foreach (['Support/Ghost.php', 'Tools/BuiltIn/Anchor.php'] as $relative) {
    $expected = 'CorpusRealAutoloadProbeA\\' . str_replace('/', '\\', substr($relative, 0, -4));
    $kinds[] = $relative . ':' . BuiltInToolCorpusTest::classifyFilePsr4Symbol($dirA . '/src/' . $relative, $expected);
}
$echo('RESULT C_KINDS=' . implode(',', $kinds));

// ─── tree B: mis-namespaced file INSIDE the wired directory ──────────
$dirB = $root . '/b';
$buildTree($dirB, 'CorpusRealAutoloadProbeB\\', 'Tools/BuiltIn');
$loader->addPsr4('CorpusRealAutoloadProbeB\\', $dirB . '/src');

$echo('PHASE B:classNames');
try {
    BuiltInToolCorpus::classNames($dirB . '/src', 'CorpusRealAutoloadProbeB\\');
    $echo('RESULT B_THROW=none');
} catch (\RuntimeException $e) {
    $echo('RESULT B_THROW=' . preg_replace('/\s+/', ' ', $e->getMessage()));
}

$echo('PHASE DONE');
exit(0);
