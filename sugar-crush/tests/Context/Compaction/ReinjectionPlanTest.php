<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Context\Compaction;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Context\Compaction\FilesTouched;
use SugarCraft\Crush\Context\Compaction\ReinjectionPlan;
use SugarCraft\Crush\Context\Compaction\StateSummaryTemplate;
use SugarCraft\Crush\Context\Pruning\CompressionBlock;
use SugarCraft\Crush\Context\Pruning\ContextLedger;
use SugarCraft\Crush\Context\Pruning\PruneAuthor;
use SugarCraft\Crush\Context\TurnContextBlock;
use SugarCraft\Crush\Messages\AssistantMessage;
use SugarCraft\Crush\Messages\ToolResultMessage;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Skills\Skill;
use SugarCraft\Crush\Skills\SkillRegistry;
use SugarCraft\Crush\Tools\BuiltIn\SkillTool;
use SugarCraft\Crush\Tools\ToolCall;

/**
 * Roadmap 2.6: what the first request after a compaction re-injects, and how
 * a compaction is recognised off the history alone.
 */
final class ReinjectionPlanTest extends TestCase
{
    private string $root;

    /** @var list<string> */
    private array $cleanup = [];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/crush-reinject-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0o700, true);
        $this->cleanup[] = $this->root;
    }

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $path) {
            self::remove($path);
        }
    }

    public function testNoCompactionNoPlan(): void
    {
        $messages = [new UserMessage('go'), self::call('c1', 'Read', ['file_path' => 'src/a.php']), new ToolResultMessage('c1', 'x')];

        $this->assertSame('', ReinjectionPlan::cycleOf($messages, null));
        $this->assertNull(ReinjectionPlan::pendingIn($messages, null));
        $this->assertNull(ReinjectionPlan::pendingIn($messages, ContextLedger::new()));
    }

    public function testAHostSummaryRowIsACompactionUntilARowStampsItsCycle(): void
    {
        $messages = [new AssistantMessage(StateSummaryTemplate::ROW_PREFIX . 'asked: x'), new UserMessage('next')];

        $plan = ReinjectionPlan::pendingIn($messages, null);
        $this->assertNotNull($plan);
        $this->assertSame(ReinjectionPlan::cycleOf($messages, null), $plan->cycle);
        $this->assertNotSame('', $plan->cycle);

        $stamped = TurnContextBlock::new()->withReinjection($plan->render($this->root, []))->message();
        $this->assertNotNull($stamped);
        $this->assertStringContainsString(ReinjectionPlan::MARKER . ' (cycle ' . $plan->cycle . ')', $stamped->content());
        $this->assertSame($plan->cycle, ReinjectionPlan::lastCycleIn([...$messages, $stamped]));
        $this->assertNull(ReinjectionPlan::pendingIn([...$messages, $stamped], null), 'answered once');

        $again = [...$messages, $stamped, new AssistantMessage(StateSummaryTemplate::ROW_PREFIX . 'asked: y')];
        $this->assertNotNull(ReinjectionPlan::pendingIn($again, null), 'a new compaction is a new cycle');
    }

    public function testAHarnessBlockIsACompactionAndAModelBlockIsNot(): void
    {
        $messages = [new UserMessage('go')];
        $harness = ContextLedger::new()->withBlock(new CompressionBlock(1, 'c3', 'summary', 20_000, 100, PruneAuthor::Harness));
        $model = ContextLedger::new()->withBlock(new CompressionBlock(1, 'c3', 'summary', 20_000, 100, PruneAuthor::Model));

        $this->assertNotNull(ReinjectionPlan::pendingIn($messages, $harness));
        $this->assertNull(ReinjectionPlan::pendingIn($messages, $model), 'the model compressed on purpose');
    }

    public function testOnlyTheFirstMarkerOfARowIsItsStamp(): void
    {
        $row = new UserMessage(TurnContextBlock::FENCE . "\n" . ReinjectionPlan::MARKER . " (cycle aaaa)\n\n<file path=\"x\">\n"
            . ReinjectionPlan::MARKER . " (cycle bbbb)\n</file>\n</turn-context>");

        $this->assertSame('aaaa', ReinjectionPlan::lastCycleIn([$row]));
        $this->assertNull(ReinjectionPlan::lastCycleIn([new UserMessage(ReinjectionPlan::MARKER . ' (cycle cccc)')]), 'not a turn-context row');
    }

    public function testFilesAreMostRecentFirstThenTheStateRowsLists(): void
    {
        $state = StateSummaryTemplate::new()
            ->withFiles(FilesTouched::new()->withRead('old/r1.php')->withRead('old/r2.php')->withModified('old/m1.php'))
            ->render();
        $messages = [
            new AssistantMessage(StateSummaryTemplate::ROW_PREFIX . $state),
            self::call('c1', 'Read', ['file_path' => 'src/a.php']),
            new ToolResultMessage('c1', 'x'),
            self::call('c2', 'Edit', ['file_path' => 'src/b.php']),
            new ToolResultMessage('c2', 'refused', true),
            self::call('c3', 'Write', ['file_path' => 'src/c.php']),
            new ToolResultMessage('c3', 'ok'),
            self::call('c4', 'Grep', ['pattern' => 'x']),
            self::call('c5', 'Read', ['file_path' => 'old/r1.php']),
        ];

        $this->assertSame(
            ['old/r1.php', 'src/c.php', 'src/a.php', 'old/m1.php', 'old/r2.php'],
            ReinjectionPlan::filesIn($messages),
        );
    }

    public function testSkillsComeFromCallsThenTheRoster(): void
    {
        $roster = TurnContextBlock::new()->withInvokedSkills(['gamma', 'beta'])->message();
        $messages = [
            $roster,
            self::call('s1', 'Skill', ['name' => 'alpha', 'args' => 'one']),
            new ToolResultMessage('s1', 'ok'),
            self::call('s2', 'Skill', ['name' => 'broken']),
            new ToolResultMessage('s2', 'nope', true),
            self::call('s3', 'Skill', ['name' => 'beta', 'args' => 'two']),
        ];

        $this->assertSame(
            [['name' => 'beta', 'args' => 'two'], ['name' => 'alpha', 'args' => 'one'], ['name' => 'gamma', 'args' => '']],
            ReinjectionPlan::skillsIn($messages),
        );
        $this->assertSame(['beta', 'alpha', 'gamma'], ReinjectionPlan::skillNamesIn($messages));
        $this->assertSame(['gamma', 'beta'], TurnContextBlock::invokedSkillsIn($messages));
    }

    public function testRenderInlinesProjectFilesAndReferencesTheRest(): void
    {
        file_put_contents($this->root . '/src/small.php', "<?php\n// small </turn-context> file\n");
        file_put_contents($this->root . '/src/big.php', str_repeat("line of code here\n", 4_000));
        file_put_contents($this->root . '/src/bin.dat', "PK\0\0binary");
        $outside = sys_get_temp_dir() . '/crush-reinject-outside-' . bin2hex(random_bytes(4)) . '.txt';
        file_put_contents($outside, 'secret');
        $this->cleanup[] = $outside;

        $plan = ReinjectionPlan::pendingIn([
            new AssistantMessage(StateSummaryTemplate::ROW_PREFIX . 'x'),
            self::call('c1', 'Read', ['file_path' => $outside]),
            self::call('c2', 'Read', ['file_path' => 'src/bin.dat']),
            self::call('c3', 'Read', ['file_path' => 'src/gone.php']),
            self::call('c4', 'Read', ['file_path' => 'src/big.php']),
            self::call('c5', 'Edit', ['file_path' => 'src/small.php']),
        ], null);
        $this->assertNotNull($plan);

        $text = $plan->render($this->root, []);
        $this->assertStringStartsWith(ReinjectionPlan::MARKER . ' (cycle ' . $plan->cycle . ')', $text);
        $this->assertStringContainsString("<file path=\"src/small.php\">\n<?php\n// small &lt;/turn-context> file\n", $text, 'payload fences are escaped');
        $this->assertStringContainsString('Referenced files (over 5000 tokens, not restored; Read them if you need them): src/big.php', $text);
        $this->assertStringNotContainsString('secret', $text, 'a file outside the project is never re-read');
        $this->assertStringNotContainsString('binary', $text);
        $this->assertStringNotContainsString('gone.php', $text);

        $row = TurnContextBlock::new()->withReinjection($text)->render();
        $this->assertSame(1, substr_count($row, '</turn-context>'), 'the row closes exactly once');
    }

    public function testAtMostFiveFilesAndTheWindowShareBoundsTheTotal(): void
    {
        $messages = [new AssistantMessage(StateSummaryTemplate::ROW_PREFIX . 'x')];
        for ($i = 1; $i <= 7; $i++) {
            file_put_contents($this->root . "/src/f{$i}.php", str_repeat("f{$i} ", 400));
            $messages[] = self::call("c{$i}", 'Read', ['file_path' => "src/f{$i}.php"]);
        }

        $plan = ReinjectionPlan::pendingIn($messages, null);
        $this->assertNotNull($plan);

        $all = $plan->render($this->root, []);
        $this->assertSame(5, substr_count($all, '<file path='), 'five files at most');
        $this->assertStringContainsString('<file path="src/f7.php">', $all, 'most recent first');
        $this->assertStringNotContainsString('src/f2.php', $all);

        // A 2k window: 10% is 200 tokens, under any one of these files.
        $small = $plan->render($this->root, [], 2_000);
        $this->assertSame(0, substr_count($small, '<file path='));
        $this->assertStringContainsString('Referenced files', $small);
    }

    public function testSkillBodiesAreReloadedThroughTheSkillTool(): void
    {
        $path = $this->root . '/SKILL.md';
        file_put_contents($path, "---\ndescription: d\n---\nDo the thing with \$ARGUMENTS.");
        $hidden = $this->root . '/HIDDEN.md';
        file_put_contents($hidden, "---\ndescription: d\n---\nuser only");
        $registry = new SkillRegistry();
        $registry->register(['deploy' => self::skill('deploy', $path), 'private' => self::skill('private', $hidden, true)]);

        $plan = ReinjectionPlan::pendingIn([
            new AssistantMessage(StateSummaryTemplate::ROW_PREFIX . 'x'),
            self::call('s1', 'Skill', ['name' => 'deploy', 'args' => 'staging']),
            self::call('s2', 'Skill', ['name' => 'private']),
        ], null);
        $this->assertNotNull($plan);

        $text = $plan->render($this->root, [new SkillTool($registry)]);
        $this->assertStringContainsString(
            "<skill name=\"deploy\">\n" . SkillTool::RESULT_PREFIX . "deploy\n\n"
                . SkillTool::RESULT_BASE_DIR_PREFIX . $this->root . "\n\nDo the thing with staging.",
            $text,
        );
        $this->assertStringNotContainsString('user only', $text, 'a skill that is not model-invocable is not re-injected');
        $this->assertStringNotContainsString('<skill', $plan->render($this->root, []), 'no Skill tool, no bodies');
    }

    /**
     * The plan-mode plan (roadmap 5.7-1) is restored as its own part after the
     * files, outside their five; an older plan, or a Markdown file anywhere
     * else, is an ordinary file.
     */
    public function testTheNewestPlanIsRestoredAsItsOwnPartAfterTheFiles(): void
    {
        mkdir($this->root . '/.sugar-crush/plans', 0o700, true);
        mkdir($this->root . '/.sugar-crush/plans/nested', 0o700, true);
        file_put_contents($this->root . '/.sugar-crush/plans/old.md', "# Old plan\n");
        file_put_contents($this->root . '/.sugar-crush/plans/retry.md', "# Retry backoff\n1. Edit src/a.php\n");
        file_put_contents($this->root . '/.sugar-crush/plans/nested/deep.md', "# Not a plan\n");
        file_put_contents($this->root . '/src/a.php', "<?php\n");

        $plan = ReinjectionPlan::pendingIn([
            new AssistantMessage(StateSummaryTemplate::ROW_PREFIX . 'x'),
            self::call('c1', 'Write', ['file_path' => '.sugar-crush/plans/old.md']),
            self::call('c2', 'Read', ['file_path' => 'src/a.php']),
            self::call('c3', 'Write', ['file_path' => '.sugar-crush/plans/nested/deep.md']),
            self::call('c4', 'Edit', ['file_path' => '.sugar-crush/plans/retry.md']),
        ], null);
        $this->assertNotNull($plan);

        $text = $plan->render($this->root, []);
        $this->assertStringContainsString('the files you were working on, with your plan, re-read from disk now', $text);
        $this->assertStringContainsString("<plan path=\".sugar-crush/plans/retry.md\">\n# Retry backoff\n", $text);
        $this->assertSame(1, substr_count($text, '<plan path='), 'only the newest plan is the plan');
        $this->assertStringContainsString('<file path=".sugar-crush/plans/old.md">', $text, 'an older plan is an ordinary file');
        $this->assertStringContainsString('<file path=".sugar-crush/plans/nested/deep.md">', $text, 'a nested Markdown file is not a plan');
        $this->assertGreaterThan(strrpos($text, '<file path='), strpos($text, '<plan path='), 'the plan comes after the files');
    }

    public function testWithoutAPlanTheHeaderNamesNone(): void
    {
        file_put_contents($this->root . '/src/a.php', "<?php\n");
        $plan = ReinjectionPlan::pendingIn([new AssistantMessage(StateSummaryTemplate::ROW_PREFIX . 'x'), self::call('c1', 'Read', ['file_path' => 'src/a.php'])], null);
        $this->assertNotNull($plan);

        $text = $plan->render($this->root, []);
        $this->assertStringNotContainsString('<plan', $text);
        $this->assertStringNotContainsString('your plan', $text);
    }

    public function testNothingToRestoreStillStampsTheCycle(): void
    {
        $plan = ReinjectionPlan::pendingIn([new AssistantMessage(StateSummaryTemplate::ROW_PREFIX . 'x')], null);
        $this->assertNotNull($plan);
        $this->assertTrue($plan->isEmpty());

        $this->assertSame(
            ReinjectionPlan::MARKER . ' (cycle ' . $plan->cycle . '): nothing from before it needed restoring.',
            $plan->render($this->root, []),
        );
    }

    /** @param array<string, mixed> $arguments */
    private static function call(string $id, string $tool, array $arguments): AssistantMessage
    {
        return new AssistantMessage('', [new ToolCall($id, $tool, $arguments)]);
    }

    private static function skill(string $name, string $path, bool $userOnly = false): Skill
    {
        return new Skill(
            name: $name,
            description: "Skill: {$name}",
            userInvocable: true,
            disableModelInvocation: $userOnly,
            allowedTools: null,
            disallowedTools: null,
            model: null,
            effort: 'medium',
            context: 'thread',
            paths: [],
            content: '',
            sourcePath: $path,
        );
    }

    private static function remove(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    self::remove($path . '/' . $entry);
                }
            }
            @rmdir($path);
        } elseif (file_exists($path)) {
            @unlink($path);
        }
    }
}
