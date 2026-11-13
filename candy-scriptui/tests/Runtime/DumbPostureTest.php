<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\ScriptUi\Runtime\ColourGate;
use SugarCraft\ScriptUi\Runtime\InteractiveRuntime;
use SugarCraft\Style\Style;

/**
 * scriptui-36 — dumb-terminal posture keys off ONE shared predicate over
 * the whole dumb-equivalent class (TERM=dumb, empty, or unset), not just
 * the literal 'dumb'. An unset TERM is the dumbest terminal of all: the
 * escape grammar is unknown, so DEC private modes (alternate screen,
 * cursor hide, 2027 grapheme paste negotiation) must all stay off and the
 * colour ladder must fall to plain bytes.
 */
final class DumbPostureTest extends TestCase
{
    private const GATED_ENVS = ['NO_COLOR', 'FORCE_COLOR', 'CLICOLOR', 'CLICOLOR_FORCE', 'TERM'];

    /** @var array<string,string|false> */
    private array $savedEnv = [];

    private ?ColorProfile $savedAmbient = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->savedAmbient = Style::defaultProfile();
        foreach (self::GATED_ENVS as $name) {
            $this->savedEnv[$name] = getenv($name);
            putenv($name);
        }
    }

    protected function tearDown(): void
    {
        InteractiveRuntime::setTerminalProbesForTesting(null);
        foreach (self::GATED_ENVS as $name) {
            $original = $this->savedEnv[$name];
            if ($original === false) {
                putenv($name);
            } else {
                putenv($name . '=' . $original);
            }
        }
        Style::setDefaultProfile($this->savedAmbient);
        parent::tearDown();
    }

    /**
     * @return array<string,array{0:?string}>
     */
    public static function dumbTerms(): array
    {
        return [
            'literal dumb' => ['dumb'],
            'empty TERM'   => [''],
            'unset TERM'   => [null],
        ];
    }

    /**
     * @param array{0:?string} $_
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('dumbTerms')]
    public function testTheSharedPredicateAdmitsTheWholeDumbClass(?string $term): void
    {
        self::assertTERMPosture($term);
    }

    /**
     * Helper seam so each leg reads linearly: assign, ask, restore.
     */
    private static function assertTERMPosture(?string $term): void
    {
        if ($term === null) {
            putenv('TERM');
        } else {
            putenv('TERM=' . $term);
        }

        self::assertTrue(
            ColourGate::termIsDumb(),
            'TERM=' . var_export($term, true) . ' is a dumb-equivalent posture',
        );
        self::assertSame(
            ColorProfile::NoTty,
            ColourGate::cap(),
            'dumb-equivalent TERM lowers colour to the plain tier',
        );
    }

    public function testARealTerminalKeepsTheRichPosture(): void
    {
        putenv('TERM=xterm-256color');

        self::assertFalse(ColourGate::termIsDumb());
        self::assertSame(ColorProfile::TrueColor, ColourGate::cap());
    }

    public function testProgramOptionsSuppressEveryDumbIncapableMode(): void
    {
        putenv('TERM=');
        InteractiveRuntime::setTerminalProbesForTesting([
            'stdout' => true,
            'stderr' => false,
            'stdin'  => true,
            'tty'    => null,
        ]);

        $options = InteractiveRuntime::programOptions(true, true);

        self::assertNotNull($options);
        self::assertTrue($options->inlineMode, 'empty TERM paints inline');
        self::assertFalse($options->useAltScreen, 'empty TERM never enters the alternate screen');
        self::assertFalse($options->hideCursor, 'empty TERM keeps the cursor visible');
        self::assertFalse($options->unicodeMode, 'empty TERM suppresses DEC 2027 negotiation');
    }

    public function testProgramOptionsStayRichOnADeclaredTerminal(): void
    {
        putenv('TERM=xterm');
        InteractiveRuntime::setTerminalProbesForTesting([
            'stdout' => true,
            'stderr' => false,
            'stdin'  => true,
            'tty'    => null,
        ]);

        $options = InteractiveRuntime::programOptions(true, true);

        self::assertNotNull($options);
        self::assertFalse($options->inlineMode);
        self::assertTrue($options->useAltScreen);
        self::assertTrue($options->hideCursor);
        self::assertTrue($options->unicodeMode, 'a declared terminal still negotiates 2027');
    }

    public function testTheDumbQuestionIsAskedInExactlyOnePlace(): void
    {
        // Behavior-level posture is pinned above; this single source-text
        // pin keeps the predicate from being re-forked into a second
        // `=== 'dumb'` comparison (the finding's root: two partial answers).
        $runtime = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Runtime/InteractiveRuntime.php');

        self::assertStringContainsString('ColourGate::termIsDumb()', $runtime);
        self::assertStringNotContainsString("getenv('TERM')", $runtime);
    }
}
