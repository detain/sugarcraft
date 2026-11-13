<?php

declare(strict_types=1);

namespace SugarCraft\Testing\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Testing\Graphics\KittyImage;
use SugarCraft\Testing\Graphics\Mode;
use SugarCraft\Testing\Graphics\PixelGrid;
use SugarCraft\Testing\Graphics\SixelStream;
use SugarCraft\Testing\Snapshot\GoldenFile;
use SugarCraft\Testing\Snapshot\Assertions;
use SugarCraft\Testing\Input\ScriptedInput;
use SugarCraft\Testing\TestResult;

/**
 * Copy-paste contract: the fenced PHP under README ## Quickstart and in the
 * ## API section must EXECUTE as written — the review round found the
 * quickstart referencing a never-defined $program and echoing a model
 * object, which is fatal for the newcomer who pastes it in good faith.
 *
 * The GoldenFile fence anchors at `__DIR__` exactly as a reader's test
 * file would; here that token is substituted with a scratch directory so
 * the proof does not write into the repo tree. Semantics are unchanged —
 * only the base path moves. (PB-TESTING-19 widened the same contract from
 * ## Quickstart to all of ## API: every fence is either executed with its
 * context variables pre-seeded, or — for the one tick-factory illustration
 * that is not standalone PHP — resolved against the real code by the
 * symbol roster. Nothing is skipped silently.)
 *
 * Context variables a fence names ($program, $actual, $grid, $buffer,
 * $model, $bytes…) are documented stand-ins, exactly as in any API docs;
 * the harness seeds the REAL object or bytes before each eval so the
 * snippet body itself runs verbatim.
 */
final class ReadmeSnippetsTest extends TestCase
{
    private string $scratch;

    protected function setUp(): void
    {
        $this->scratch = \sys_get_temp_dir() . '/sugar-testing-readme-' . \bin2hex(\random_bytes(4));
        \mkdir($this->scratch, 0700, true);

        // A stale golden from a previous run would make the guarded seed
        // in the quickstart skip its creation leg; start clean.
        @\unlink(\sys_get_temp_dir() . '/sugar-testing-quickstart.golden');
    }

    protected function tearDown(): void
    {
        @\unlink(\sys_get_temp_dir() . '/sugar-testing-quickstart.golden');
        $this->removeTree($this->scratch);
    }

    public function testTheQuickstartBlockCarriesAnExecutableFence(): void
    {
        $fences = self::phpFencesBetween('## Quickstart', '## API');
        self::assertGreaterThan(0, \count($fences), 'README lost its Quickstart php fence');

        foreach ($fences as $php) {
            eval($php);
        }

        // eval() shares this method's scope, so the fence's variables are
        // visible here — pin that the snippet actually computed the demo.
        self::assertInstanceOf(TestResult::class, $result ?? null, 'quickstart fence must yield $result');
        self::assertSame('count: 1', $result->view);
        self::assertSame($result->view, GoldenFile::load(\sys_get_temp_dir() . '/sugar-testing-quickstart.golden'));
    }

    public function testTheGoldenFileBlockExecutesAgainstAScratchBase(): void
    {
        $fences = self::phpFencesBetween('### GoldenFile', '### TestResult');
        self::assertGreaterThan(0, \count($fences), 'README lost its GoldenFile php fence');

        foreach ($fences as $php) {
            // Substitute only the __DIR__ token; everything else runs verbatim.
            eval(\str_replace('__DIR__', \var_export($this->scratch, true), $php));
        }

        self::assertFileExists($this->scratch . '/fixtures/view.golden');
        self::assertSame('frame', GoldenFile::load($this->scratch . '/fixtures/view.golden'));
    }

    /**
     * PB-TESTING-19: every fenced PHP between ## API and ## Tests runs as
     * written, per-section harness first, fence body verbatim. The GoldenFile
     * section is the one exception — it has its own dedicated scratch-based
     * test above; here it is only triwired so a drift (heading gone, or a
     * second fence sneaking in) still fails this contract.
     */
    public function testEveryApiSectionFenceExecutesAsWritten(): void
    {
        // Triwire for the dedicated GoldenFile test's anchor.
        $goldenFences = self::phpFencesBetween('### GoldenFile', '### TestResult');
        self::assertCount(1, $goldenFences, '### GoldenFile gained a second fence — extend the dedicated test');

        $sections = [
            '### ProgramSimulator' => ['end' => '### Simulation subset', 'mode' => 'sim'],
            '### ScriptedInput' => ['end' => '### Assertions', 'mode' => 'scripted'],
            '### Assertions' => ['end' => '### The cell-grid schema', 'mode' => 'assertions'],
            '### The cell-grid schema (`assertCellGrid`)' => ['end' => '### GoldenFile', 'mode' => 'grid'],
            '### Graphics stream assertions' => ['end' => '### LoopPin', 'mode' => 'graphics'],
            '### LoopPin' => ['end' => '### TapeRecorder', 'mode' => 'loop'],
            '### TapeRecorder' => ['end' => '### TemporaryDirectoryTrait', 'mode' => 'tape'],
        ];

        $executed = 0;
        foreach ($sections as $heading => $spec) {
            $fences = self::phpFencesBetween($heading, $spec['end']);
            self::assertGreaterThan(0, \count($fences), "README lost its php fence under {$heading}");

            foreach ($fences as $php) {
                // The ProgramSimulator tour fence deliberately echoes its
                // view; capture the whole sweep so the suite reports zero
                // stray output (Warnings) no matter what a fence prints.
                \ob_start();
                try {
                    $this->executeApiFence($heading, $spec['mode'], $php);
                } finally {
                    \ob_end_clean();
                }
                ++$executed;
            }
        }

        self::assertGreaterThanOrEqual(8, $executed, 'API fence sweep went vacuous');
    }

    private function executeApiFence(string $heading, string $mode, string $php): void
    {
        switch ($mode) {
            case 'sim':
                $prologue = '$program = ' . self::counterExpr() . ";\n"
                    . '$script = \SugarCraft\Testing\Input\ScriptedInput::new()->key(\'a\')->key(\'b\')->build();'
                    . '$msgA = $script[0]; $msgB = $script[1]; $nextMsg = $script[0];';
                eval($prologue . "\n" . $php);
                self::assertInstanceOf(TestResult::class, $result ?? null, "{$heading}: fence must set \$result");
                self::assertSame('count: 0', $result->view, "{$heading}: last view()");
                self::assertSame($program, $finalModel, "{$heading}: \$result->model must be the final model");
                return;

            case 'assertions':
                $fixtureDir = $this->scratch . '/readme-fixtures';
                \mkdir($fixtureDir . '/tests/fixtures', 0700, true);
                \file_put_contents($fixtureDir . '/tests/fixtures/view.golden', "\x1b[1;32mHello\x1b[0m");
                $prologue = '$actual = "\x1b[1;32mHello\x1b[0m";'
                    . '$grid = [\'abcd\'];'
                    . '$buffer = ' . self::abcdBufferExpr() . ';'
                    . '$model = ' . self::counterExpr() . ';'
                    . '$previousDir = \getcwd(); \chdir(' . \var_export($fixtureDir, true) . ');';
                try {
                    eval($prologue . "\n" . $php);
                } finally {
                    \chdir((string) $previousDir);
                }
                return;

            case 'grid':
                // One fence, two schema statements, one shared buffer: row 0
                // "abcd", row 1 "ok🎉" — the wide lead at (2,1) auto-claims
                // column 3, which the array-row statement must spell itself.
                $prologue = 'use ' . Assertions::class . ';' . "\n"
                    . '$buffer = ' . self::sharedGridBufferExpr() . ';';
                eval($prologue . "\n" . $php);
                return;

            case 'graphics':
                // Real wire bytes from the committed decoder fixtures — the
                // same capture the Graphics suite round-trips against.
                $prologue = '$kittyBytes = ' . \var_export(Graphics\Fixture::bytes('kitty_red.kitty'), true) . ';'
                    . '$sixelBytes = ' . \var_export(Graphics\Fixture::bytes('sixel_red.six'), true) . ';'
                    . '$itermBytes = ' . \var_export(Graphics\Fixture::bytes('iterm2_red.iterm2'), true) . ';'
                    // The grid the doc shows a caller would pass: derived from
                    // the same fixture through the documented renderer, so the
                    // line exercises the full GD materialization path exactly.
                    . '$expectedGrid = \SugarCraft\Testing\Graphics\GraphicsAssertions::renderGrid($kittyBytes, \SugarCraft\Testing\Graphics\Mode::Kitty);';
                try {
                    eval($prologue . "\n" . $php);
                    self::assertTrue(\extension_loaded('gd'), 'ext-gd absent yet the pixel-diff leg passed — the loud-fail contract broke');
                } catch (\RuntimeException $e) {
                    // Documented behaviour: without GD the pixel paths fail LOUDLY.
                    self::assertFalse(\extension_loaded('gd'), 'assertRendersTo threw with ext-gd present: ' . $e->getMessage());
                    self::assertStringContainsString('gd', \strtolower($e->getMessage()), 'pixel-path refusal must name the extension');
                }
                self::assertInstanceOf(KittyImage::class, $kitty ?? null, 'kitty decode leg must have produced the image');
                self::assertStringStartsWith("\x89PNG", (string) $kitty->png(), 'the kitty fixture carries a real PNG');
                self::assertSame(8, $kitty->cols());
                self::assertSame(4, $kitty->rows());
                self::assertInstanceOf(SixelStream::class, $sixel ?? null, 'sixel decode leg must have produced the stream');
                self::assertInstanceOf(PixelGrid::class, $sixel->grid());
                self::assertSame(Mode::Kitty, $mode ?? null);
                return;

            case 'scripted':
                if (\str_starts_with(\ltrim($php), '->')) {
                    // The tick-factory arm is an illustration of one builder
                    // call, not standalone PHP: its example class cannot
                    // exist. Resolve it against the real code instead —
                    // never skip silently.
                    self::assertTrue(\method_exists(\SugarCraft\Testing\Input\ScriptedInput::class, 'ticks'));
                    $param = (new \ReflectionMethod(\SugarCraft\Testing\Input\ScriptedInput::class, 'ticks'))->getParameters();
                    self::assertSame(['count', 'seconds', 'factory'], \array_map(static fn (\ReflectionParameter $p): string => $p->getName(), $param));
                    self::assertStringContainsString('fn (int $i, float $s) =>', $php, 'tick illustration must keep its arrow-factory shape');
                    self::assertFalse(\class_exists('My\\Lib\\Msg\\TickMsg'), 'the illustration class must stay fictional');
                    return;
                }
                $prologue = \str_contains($php, 'use SugarCraft\Testing\Input\ScriptedInput')
                    ? ''
                    : 'use ' . ScriptedInput::class . ';' . "\n";
                eval($prologue . "\n" . $php);
                if (isset($input)) {
                    self::assertCount(10, $input, 'builder chain: 1+1+1+5 ticks+1+1 messages');
                }
                if (isset($paste)) {
                    self::assertGreaterThan(0, \count($paste), 'opt-out paste leg must still produce messages');
                }
                return;

            case 'tape':
                $tapeDir = $this->scratch . '/demo-project';
                \mkdir($tapeDir . '/.vhs', 0700, true);
                $body = \str_replace('__DIR__', \var_export($tapeDir, true), $php);
                eval($body);
                $tape = (string) \file_get_contents($tapeDir . '/.vhs/demo.tape');
                self::assertStringContainsString('Set Theme "TokyoNight"', $tape);
                self::assertStringContainsString('Type "php examples/demo.php"', $tape);
                self::assertStringContainsString('Enter', $tape);
                self::assertStringContainsString('Sleep', $tape);
                self::assertStringContainsString('# Resize 100x30', $tape);
                self::assertStringContainsString('# rendered by .github/workflows/vhs.yml', $tape);
                // The tape-side key renderer named in the same section:
                $down = \SugarCraft\Testing\Input\ScriptedInput::new()->arrow('down')->build()[0];
                self::assertNotNull(\SugarCraft\Testing\Tape\TapeRecorder::keyMsgToVhs($down), 'keyMsgToVhs must map arrows');
                return;

            case 'loop':
                // pinStableClock() stops Loop::get() process-wide and forever;
                // eval'ing it in-suite would poison every later timer-using
                // test. Prove the fence in an isolated child PHP process —
                // same contract, zero suite pollution.
                $child = $this->scratch . '/looppin-fence.php';
                self::assertIsInt(\file_put_contents(
                    $child,
                    '<?php' . "\n"
                    . 'require ' . \var_export(\dirname(__DIR__) . '/vendor/autoload.php', true) . ';' . "\n"
                    . $php . "\n"
                    . '\SugarCraft\Testing\LoopPin::pinStableClock();' . "\n" // idempotence as documented
                    . 'echo \get_class(\React\EventLoop\Loop::get()), "\n";',
                ), 'could not stage the LoopPin fence for the child process');
                $proc = \proc_open([PHP_BINARY, '-f', $child], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                self::assertIsResource($proc);
                $stdout = (string) \stream_get_contents($pipes[1]);
                $stderr = (string) \stream_get_contents($pipes[2]);
                \fclose($pipes[1]);
                \fclose($pipes[2]);
                $exit = \proc_close($proc);
                self::assertSame(0, $exit, "LoopPin fence child exited {$exit}: {$stdout}{$stderr}");
                self::assertStringContainsString('StreamSelectLoop', $stdout, 'the pinned loop must be the StreamSelectLoop every README copy depends on');
                return;
        }

        self::fail("no execution harness for API fence under {$heading} — extend this test (PB-TESTING-19 contract)");
    }

    /**
     * PB-TESTING-19 roster half: every symbol the API prose names (builder
     * rosters, verbs, traits, constants) must exist — the fence bodies cover
     * the called ones; this covers the listed-but-not-called ones.
     */
    public function testApiSectionSymbolsResolveAgainstRealCode(): void
    {
        $classes = [
            \SugarCraft\Testing\ProgramSimulator::class,
            \SugarCraft\Testing\TestResult::class,
            \SugarCraft\Testing\Input\ScriptedInput::class,
            \SugarCraft\Testing\Input\TickMsg::class,
            \SugarCraft\Testing\Snapshot\Assertions::class,
            \SugarCraft\Testing\Snapshot\GoldenFile::class,
            \SugarCraft\Testing\Graphics\GraphicsAssertions::class,
            \SugarCraft\Testing\Graphics\KittyStream::class,
            \SugarCraft\Testing\Graphics\SixelStream::class,
            \SugarCraft\Testing\Graphics\Iterm2Stream::class,
            \SugarCraft\Testing\Graphics\Mode::class,
            \SugarCraft\Testing\Graphics\PixelGrid::class,
            \SugarCraft\Testing\Graphics\DecodeLimits::class,
            \SugarCraft\Testing\Graphics\MalformedGraphicsException::class,
            \SugarCraft\Testing\Tape\TapeRecorder::class,
            \SugarCraft\Testing\Concerns\TemporaryDirectoryTrait::class,
            \SugarCraft\Testing\LoopPin::class,
        ];
        foreach ($classes as $class) {
            self::assertTrue(\class_exists($class) || \trait_exists($class), $class . ' missing');
        }

        $methods = [
            \SugarCraft\Testing\Snapshot\Assertions::class => ['assertGoldenAnsi', 'assertCellGrid', 'assertAnsiEquals', 'assertViewIdempotent'],
            \SugarCraft\Testing\Snapshot\GoldenFile::class => ['resolve', 'load', 'save', 'create', 'update'],
            \SugarCraft\Testing\Graphics\GraphicsAssertions::class => ['assertRendersTo', 'assertInlineImageDimensions', 'assertProtocolRoundTrip'],
            \SugarCraft\Testing\Graphics\Mode::class => ['detect'],
            \SugarCraft\Testing\Input\ScriptedInput::class => [
                'new', 'key', 'namedKey', 'enter', 'escape', 'backspace', 'tab', 'arrow', 'ticks',
                'resize', 'quit', 'mouse', 'wheel', 'paste', 'bytes', 'clipboard', 'keyboardEnhancements',
                'push', 'build', 'count', 'withSanitizePaste',
            ],
            \SugarCraft\Testing\Tape\TapeRecorder::class => [
                'to', 'header', 'type', 'enter', 'sleep', 'resize', 'comment', 'line', 'save', 'keyMsgToVhs',
            ],
            \SugarCraft\Testing\TestResult::class => ['assertCmdCount', 'assertCmdContains', 'assertNoCmds'],
            \SugarCraft\Testing\Concerns\TemporaryDirectoryTrait::class => ['setUpTemporaryDirectory', 'tearDownTemporaryDirectory', 'tempDirPrefix'],
            \SugarCraft\Testing\LoopPin::class => ['pinStableClock'],
        ];
        foreach ($methods as $class => $names) {
            foreach ($names as $name) {
                self::assertTrue(\method_exists($class, $name), $class . '::' . $name . ' missing');
            }
        }

        $constants = [
            \SugarCraft\Testing\Input\ScriptedInput::class => ['READ_SIZE', 'PASTE_START', 'PASTE_END'],
        ];
        foreach ($constants as $class => $names) {
            foreach ($names as $name) {
                self::assertTrue(\defined($class . '::' . $name), $class . '::' . $name . ' missing');
            }
        }
    }

    public function testTheSimulatorSectionStillDocumentsTheRealSimulator(): void
    {
        // Cheap tripwire: the rename that introduced TestResult->frames left
        // a window where the README promised methods the class does not
        // have. Every ->member named in the ProgramSimulator/API fences must
        // exist on TestResult (properties) — the fence bodies themselves
        // cover the statics by executing above.
        $text = (string) file_get_contents(\dirname(__DIR__) . '/README.md');
        preg_match_all('/\$result->(\w+)/', $text, $matches);

        $known = get_object_vars(new TestResult(
            new CounterModel(0),
            '',
            [],
            '',
        ));
        $known += ['frames' => [], 'droppedAfterStop' => 0];

        foreach (array_unique($matches[1]) as $member) {
            self::assertArrayHasKey($member, $known, "README names \$result->{$member} which TestResult does not expose");
        }
    }

    /** Expression producing the quickstart's inline counter — reused by API harnesses. */
    private static function counterExpr(): string
    {
        return <<<'PHP'
new class implements \SugarCraft\Core\Model {
        public function __construct(private int $count = 0)
        {
        }

        public function init(): ?\Closure
        {
            return null;
        }

        public function update(\SugarCraft\Core\Msg $msg): array
        {
            return [$this, null];
        }

        public function view(): string
        {
            return 'count: ' . $this->count;
        }

        public function subscriptions(): ?\SugarCraft\Core\Subscriptions
        {
            return null;
        }
    }
PHP;
    }

    private static function abcdBufferExpr(): string
    {
        return '(function (\SugarCraft\CellBuffer\Buffer $b): \SugarCraft\CellBuffer\Buffer {'
            . 'foreach ([\'a\', \'b\', \'c\', \'d\'] as $i => $r) { $b = $b->withCellAt($i, 0, \SugarCraft\CellBuffer\Cell::new($r)); }'
            . 'return $b; })(\SugarCraft\CellBuffer\Buffer::new(4, 1))';
    }

    /**
     * The single buffer the one cell-grid fence asserts against twice:
     * row 0 painted "abcd", row 1 painted "ok🎉" (wide lead at column 2
     * owning column 3 as its continuation) — 4 columns x 2 rows.
     */
    private static function sharedGridBufferExpr(): string
    {
        $party = "\xF0\x9F\x8E\x89";
        return '(function (\SugarCraft\CellBuffer\Buffer $b): \SugarCraft\CellBuffer\Buffer {'
            . 'foreach ([\'a\', \'b\', \'c\', \'d\'] as $i => $r) { $b = $b->withCellAt($i, 0, \SugarCraft\CellBuffer\Cell::new($r)); }'
            . 'return $b; })('
            . '\SugarCraft\CellBuffer\Buffer::new(4, 2)'
            . '->withCellAt(0, 1, \SugarCraft\CellBuffer\Cell::new(\'o\'))'
            . '->withCellAt(1, 1, \SugarCraft\CellBuffer\Cell::new(\'k\'))'
            . '->withCellAt(2, 1, \SugarCraft\CellBuffer\Cell::new(' . \var_export($party, true) . ', width: 2))'
            . '->withCellAt(3, 1, \SugarCraft\CellBuffer\Cell::continuation()))';
    }

    /**
     * @return list<string> every ```php fence body between the two headings
     */
    private static function phpFencesBetween(string $startHeading, string $endHeading): array
    {
        $text = (string) file_get_contents(\dirname(__DIR__) . '/README.md');

        $start = strpos($text, "\n" . $startHeading . "\n");
        self::assertIsInt($start, "README lost its '{$startHeading}' heading");
        $end = strpos($text, "\n" . $endHeading, $start);
        self::assertIsInt($end, "README lost its '{$endHeading}' heading");

        $segment = substr($text, $start, $end - $start);
        preg_match_all('/^```php\n(.*?)\n```$/ms', $segment, $matches);

        return $matches[1];
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path) && !is_link($path)) {
                $this->removeTree($path);
                @rmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
