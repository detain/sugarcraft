<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Prompt;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Context\PromptSection;
use SugarCraft\Crush\Runtime;
use SugarCraft\Crush\Tests\BaseSystemPromptTest;

/**
 * Prompt-snapshot drift pins (step 1.A-1, after OpenClaw's prompt snapshots):
 * the two halves the harness delivers per step, each against a committed
 * snapshot under `tests/fixtures/prompt-snapshots/`.
 *
 *  - `system-sections.txt` — one manifest row per {@see PromptSection} the
 *    real assembler returns: slot, {@see \SugarCraft\Crush\Context\Stability},
 *    fence, byte length and a hash of the rendered bytes. The whole-prompt
 *    golden (`golden-system-prompt.txt`, BaseSystemPromptTest) says THAT the
 *    prompt drifted; this says WHICH SLOT did, and catches a section moving
 *    tier — a PerSession layer turning PerTurn is a cache regression the
 *    golden cannot see, because its bytes may not change at all.
 *  - `turn-context.txt` — the `<turn-context>` row for the same fixture: the
 *    git section (every field the golden used to carry: caveat, branch,
 *    three-line porcelain status, log, staged and unstaged diffs) that 1.A-1
 *    moved out of the system prompt.
 *
 * SAME FIXTURE AS THE GOLDEN, by construction: the deterministic context
 * (fixture repo under vendor/, pinned HOME, package-root cwd, frozen date,
 * host lines pinned) is BaseSystemPromptTest's own, called through its
 * private static helpers rather than copied — a second copy of that harness
 * is exactly the drift DuplicatedTestHelperDriftTest exists to catch.
 *
 * REGENERATION follows the golden's discipline: only for a change that is
 * argued in the commit, with the old-vs-new diff reviewed; the failure
 * message prints the full actual manifest, and the turn-context diff is the
 * assertion's own.
 */
final class PromptSnapshotDriftTest extends TestCase
{
    private const DIR = __DIR__ . '/../fixtures/prompt-snapshots';

    public function testEverySystemPromptSlotMatchesItsManifestRow(): void
    {
        $actual = self::render(static function (Runtime $runtime, $app): string {
            $sections = new \ReflectionMethod($runtime, 'systemPromptSections');
            $sections->setAccessible(true);

            $rows = [];
            foreach ($sections->invoke($runtime, $app) as $i => $section) {
                \assert($section instanceof PromptSection);
                $bytes = self::helper('pinHostLines', $section->render());
                $rows[] = implode("\t", [
                    sprintf('%02d', $i + 1),
                    $section->stability()->name,
                    $section->fence() === '' ? '-' : $section->fence(),
                    (string) \strlen($bytes),
                    substr(hash('sha256', $bytes), 0, 16),
                ]);
            }

            return implode("\n", $rows) . "\n";
        });

        self::assertSame(
            self::snapshot('system-sections.txt'),
            $actual,
            "a system-prompt slot drifted from tests/fixtures/prompt-snapshots/system-sections.txt "
            . "(columns: slot, stability, fence, bytes, sha256/16). Actual manifest:\n" . $actual,
        );
    }

    public function testTheTurnContextRowMatchesItsSnapshot(): void
    {
        $actual = self::render(
            static fn (Runtime $runtime, $app): string => self::helper('pinHostLines', $runtime->turnContext($app)->render()),
        );

        self::assertSame(
            self::snapshot('turn-context.txt'),
            $actual,
            'the <turn-context> row drifted from tests/fixtures/prompt-snapshots/turn-context.txt',
        );
    }

    /**
     * The split itself, pinned across the two snapshots: no git field is in
     * the system prompt, every one is in the row exactly once, and the
     * system prompt's `<env>` is the static half and still the last slot.
     */
    public function testTheTwoSnapshotsSplitTheEnvironmentWithoutOverlap(): void
    {
        $row = self::snapshot('turn-context.txt');
        $golden = (string) file_get_contents(__DIR__ . '/../fixtures/prompt/golden-system-prompt.txt');

        foreach ([
            'Note: this git state is as of',
            'Current branch:',
            "\nStatus:\n",
            "\nRecent commits:\n",
            'Staged changes (git diff --cached, index vs HEAD):',
            'Unstaged changes (git diff, working tree vs index):',
        ] as $field) {
            self::assertSame(0, substr_count($golden, $field), "the system prompt still carries {$field}");
            self::assertSame(1, substr_count($row, $field), "the turn-context row must carry {$field} exactly once");
        }

        self::assertStringEndsWith("Current date: 2026-08-26\n</env>", $golden);
        self::assertStringStartsWith("<turn-context>\n", $row);
        self::assertStringEndsWith("\n</turn-context>", $row);

        $manifest = explode("\n", trim(self::snapshot('system-sections.txt')));
        self::assertMatchesRegularExpression('~^\d+\tPerSession\t<env>\t~', (string) end($manifest), '<env> is the static, session-stable LAST slot');
    }

    /**
     * Run $build inside the golden's deterministic context.
     *
     * @param \Closure(Runtime, \SugarCraft\Crush\App\App): string $build
     */
    private static function render(\Closure $build): string
    {
        self::helper('ensureFixtureRepo');

        return self::helper(
            'renderUnderFixtureUserHome',
            static fn (): string => self::helper('inPackageRoot', static function () use ($build): string {
                [$runtime, $app] = self::helper('goldenContext');

                return $build($runtime, $app);
            }),
        );
    }

    private static function helper(string $method, mixed ...$args): mixed
    {
        $helper = new \ReflectionMethod(BaseSystemPromptTest::class, $method);
        $helper->setAccessible(true);

        return $helper->invoke(null, ...$args);
    }

    private static function snapshot(string $name): string
    {
        $path = self::DIR . '/' . $name;
        $bytes = @file_get_contents($path);
        if ($bytes === false) {
            self::fail('Snapshot missing: ' . $path . ' - regenerate it per the class docblock.');
        }

        return $bytes;
    }
}
