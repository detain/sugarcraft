<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Backend;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Context\Compaction\FilesTouched;
use SugarCraft\Crush\Context\Compaction\ReinjectionPlan;
use SugarCraft\Crush\Context\Compaction\StateSummaryTemplate;
use SugarCraft\Crush\Context\Compaction\StepSummarizer;
use SugarCraft\Crush\Context\TurnContextBlock;
use SugarCraft\Crush\Messages\AssistantMessage;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Skills\Skill;
use SugarCraft\Crush\Skills\SkillRegistry;
use SugarCraft\Crush\Tests\Support\ScriptedProvider;
use SugarCraft\Crush\Tools\BuiltIn\SkillTool;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolCall;
use SugarCraft\Crush\Tools\ToolResult;
use SugarCraft\Crush\Usage;

/**
 * Roadmap 2.6: the first request after a compaction — the host's, or a step
 * summary the turn wrote itself — carries the files the agent was working on
 * and the skills it had loaded, on that step's `<turn-context>` row, once.
 */
final class PostCompactionReinjectionTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/crush-postcompact-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0o700, true);
        file_put_contents($this->root . '/src/app.php', "<?php\n// THE CURRENT APP SOURCE\n");
        file_put_contents($this->root . '/SKILL.md', "---\ndescription: d\n---\nDEPLOY STEPS for \$ARGUMENTS");
    }

    protected function tearDown(): void
    {
        foreach (['/src/app.php', '/SKILL.md'] as $file) {
            @unlink($this->root . $file);
        }
        @rmdir($this->root . '/src');
        @rmdir($this->root);
    }

    public function testAHostCompactionIsAnsweredOnTheNextTurnsFirstRowAndOnlyThere(): void
    {
        $provider = new ScriptedProvider([new CompleteResponse(content: 'done')]);
        $engine = EngineBackend::new($provider, 'm')->withoutHooks()->withRoot($this->root)
            ->withTools([new SkillTool(self::registry($this->root . '/SKILL.md'))]);

        $state = StateSummaryTemplate::new()->withFiles(FilesTouched::new()->withModified('src/app.php'))->render();
        $compacted = [
            // What a host compaction leaves: the state row, and a preserved
            // turn-context row whose roster names the skill a hidden call loaded.
            new AssistantMessage(StateSummaryTemplate::ROW_PREFIX . $state),
            TurnContextBlock::new()->withInvokedSkills(['deploy'])->message(),
            new UserMessage('carry on'),
        ];

        $first = $engine->completeTranscript($compacted);
        $row = self::lastContextRow($provider->requests[0]->messages);
        $this->assertNotNull($row);
        $this->assertStringContainsString(ReinjectionPlan::MARKER . ' (cycle ', $row);
        $this->assertStringContainsString("<file path=\"src/app.php\">\n<?php\n// THE CURRENT APP SOURCE", $row);
        $this->assertStringContainsString("<skill name=\"deploy\">\n" . SkillTool::RESULT_PREFIX . "deploy\n\nDEPLOY STEPS for", $row);
        $this->assertStringContainsString(TurnContextBlock::SKILLS_LINE . 'deploy', $row, 'the roster rides on');

        // The row rides the transcript back; the next turn owes nothing.
        $engine->completeTranscript([...$first->transcript, new UserMessage('and now?')]);
        $next = self::lastContextRow($provider->requests[1]->messages);
        $this->assertNotNull($next);
        $this->assertStringNotContainsString(ReinjectionPlan::MARKER, $next, 'one-shot per compaction');
        $this->assertStringNotContainsString('THE CURRENT APP SOURCE', $next);
    }

    public function testAStepSummaryIsAnsweredInTheRequestTheStepThenSends(): void
    {
        $step = 0;
        $provider = new ScriptedProvider([
            static function (CompleteRequest $request) use (&$step): CompleteResponse {
                $last = $request->messages[array_key_last($request->messages)] ?? null;
                if ($last instanceof UserMessage && $last->content() === StepSummarizer::INSTRUCTION) {
                    return new CompleteResponse(content: 'SUMMARY: read three files.', usage: Usage::new(60, 0.0, 50, 10, 0));
                }
                $step++;

                return $step <= 3
                    ? new CompleteResponse(content: '', toolCalls: [new ToolCall("c{$step}", 'Read', ['file_path' => $step === 1 ? 'src/app.php' : "src/gone{$step}.php"])])
                    : new CompleteResponse(content: 'all read');
            },
        ], contextWindow: 100_000);

        $turn = EngineBackend::new($provider, 'm')->withoutHooks()->withRoot($this->root)
            ->withTools([self::bulkyRead()])
            ->completeTranscript([new UserMessage('read the app')]);

        // Three tool steps, the summary request, the step it relieved.
        $this->assertCount(5, $provider->requests);
        $this->assertNull(self::markerIn($provider->requests[2]->messages), 'nothing re-injected before the summary');

        $after = $provider->requests[4]->messages;
        $row = self::lastContextRow($after);
        $this->assertNotNull($row);
        $this->assertStringContainsString(ReinjectionPlan::MARKER . ' (cycle ', $row);
        $this->assertStringContainsString("<file path=\"src/app.php\">\n<?php\n// THE CURRENT APP SOURCE", $row, 're-read from disk, not the tool\'s output');
        $this->assertSame('all read', $turn->reply->content);
        $this->assertNotNull(self::markerIn($turn->transcript), 'the stamped row is in the transcript');
    }

    /** @param list<mixed> $messages */
    private static function lastContextRow(array $messages): ?string
    {
        $latest = null;
        foreach ($messages as $message) {
            if (TurnContextBlock::isTurnContext($message)) {
                $latest = $message->content();
            }
        }

        return $latest;
    }

    /** @param list<mixed> $messages */
    private static function markerIn(array $messages): ?string
    {
        return ReinjectionPlan::lastCycleIn($messages);
    }

    /** A `Read` that answers with 30k of text, whatever the file holds. */
    private static function bulkyRead(): Tool
    {
        return new class () implements Tool {
            public function name(): string
            {
                return 'Read';
            }

            public function description(): string
            {
                return 'Reads a lot.';
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => ['file_path' => ['type' => 'string']]];
            }

            public function execute(array $args): ToolResult
            {
                return new ToolResult(toolCallId: '', content: str_repeat('lorem ipsum ', 10_000));
            }
        };
    }

    private static function registry(string $path): SkillRegistry
    {
        $registry = new SkillRegistry();
        $registry->register(['deploy' => new Skill(
            name: 'deploy',
            description: 'Skill: deploy',
            userInvocable: true,
            disableModelInvocation: false,
            allowedTools: null,
            disallowedTools: null,
            model: null,
            effort: 'medium',
            context: 'thread',
            paths: [],
            content: '',
            sourcePath: $path,
        )]);

        return $registry;
    }
}
