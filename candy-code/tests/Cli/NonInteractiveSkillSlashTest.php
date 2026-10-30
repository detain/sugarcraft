<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Attachment;
use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Cli\ArgvParser;
use SugarCraft\Code\Cli\NonInteractive;
use SugarCraft\Code\Providers\CompleteRequest;
use SugarCraft\Code\Providers\CompleteResponse;
use SugarCraft\Code\Skills\Skill;
use SugarCraft\Code\Skills\SkillRegistry;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;
use SugarCraft\Code\Tests\Support\ScriptedProvider;

/**
 * C-WEB-2.c: the headless `-p` route carries the skill channels the TUI and
 * the served host already share. A typed `/skill-name args` prompt is
 * rewritten by {@see \SugarCraft\Code\Skills\SkillSlashInvocation::draft()}
 * into the `$skill-name args` mention spelling, and `Cli\NonInteractive::historyFrom()`
 * resolves every `$name` against the SAME registry the launch's
 * {@see EngineBackend} drives the Skill tool with — one gate, one
 * `$ARGUMENTS` rule, one per-turn attach, across all three routes.
 *
 * The trust boundary of audit 15b-15 stays exactly where it was: only the
 * prompt TYPED on the command line is rewritten or scanned. Piped stdin is
 * context of unknown origin and rides byte-identical, mentioning nothing.
 *
 * Driven both ways this family drives things: the pure builder for shapes,
 * and the real `NonInteractive::run()` over an engine backend (the
 * {@see AppendSystemPromptTest} live-chain convention) for the wiring — a
 * unit test of `historyFrom()` alone would stay green if the call site ever
 * stopped feeding the registry.
 */
final class NonInteractiveSkillSlashTest extends TestCase
{
    use HomeSandboxTrait;

    private string $sandbox = '';

    private string $project = '';

    protected function setUp(): void
    {
        $this->sandbox = sys_get_temp_dir() . '/sc_ni_skillslash_' . bin2hex(random_bytes(6));
        $this->project = $this->sandbox . '/project';
        mkdir($this->project . '/.candy-code/skills', 0o700, true);
        $this->useHomeSandbox($this->sandbox . '/home');
    }

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();
        if (is_dir($this->sandbox)) {
            exec('rm -rf ' . escapeshellarg($this->sandbox) . ' 2>&1');
        }
    }

    /**
     * A registry over real SKILL.md files (the mention channel re-reads each
     * body from disk at resolve time); `$off` registers a name and disables
     * it again, mirroring the served route's fixture.
     *
     * @param array<string, string> $bodies name => body
     * @param list<string>          $off
     */
    private function diskRegistry(array $bodies, array $off = []): SkillRegistry
    {
        $found = [];
        foreach ($bodies as $skillName => $body) {
            $dir = $this->project . '/.candy-code/skills/' . $skillName;
            mkdir($dir, 0o700, true);
            file_put_contents($dir . '/SKILL.md', "---\nname: {$skillName}\ndescription: the {$skillName} skill\n---\n" . $body);
            $found[] = Skill::fromFile($dir . '/SKILL.md');
        }

        $registry = new SkillRegistry();
        $registry->register($found);
        $registry->disableMultiple($off);

        return $registry;
    }

    public function testATypedSlashNameRewritesToTheMentionAndAttachesTheBody(): void
    {
        $history = NonInteractive::historyFrom(
            '/shipit review the diff',
            null,
            null,
            $notices,
            null,
            $this->diskRegistry(['shipit' => 'Review $ARGUMENTS please']),
        );

        $this->assertCount(1, $history);
        $this->assertSame('$shipit review the diff', $history[0]->content, 'the row carries the rewritten mention draft, the TUI spelling');
        $attachments = $history[0]->attachments;
        $this->assertCount(1, $attachments);
        $this->assertInstanceOf(Attachment::class, $attachments[0]);
        $this->assertSame('shipit', $attachments[0]->skill, 'the chip says which SKILL.md rode the turn');
        $this->assertSame('Review review the diff please', $attachments[0]->data, 'the body snapshot rides with $ARGUMENTS filled');
        $this->assertSame([], $notices, 'a name the registry answers needs no notice');
    }

    public function testATypedDollarMentionAttachesWithoutAnyRewrite(): void
    {
        $history = NonInteractive::historyFrom(
            '$shipit review the diff',
            null,
            null,
            $notices,
            null,
            $this->diskRegistry(['shipit' => 'Review $ARGUMENTS please']),
        );

        $this->assertSame('$shipit review the diff', $history[0]->content, 'the mention spelling is already the dispatch spelling');
        $this->assertCount(1, $history[0]->attachments);
        $this->assertSame('shipit', $history[0]->attachments[0]->skill);
        $this->assertSame('Review review the diff please', $history[0]->attachments[0]->data);
        $this->assertSame([], $notices);
    }

    public function testADollarThatIsNotASkillStaysPlainText(): void
    {
        $history = NonInteractive::historyFrom(
            'echo $HOME and $1 and $PATH from a shell line',
            null,
            null,
            $notices,
            null,
            $this->diskRegistry(['shipit' => 'x']),
        );

        $this->assertSame('echo $HOME and $1 and $PATH from a shell line', $history[0]->content, 'the registry is the gate, not the dollar sign');
        $this->assertSame([], $history[0]->attachments, 'no shell variable attaches a skill');
        $this->assertSame([], $notices, 'and never announces one either');
    }

    public function testPipedStdinIsNeverRewrittenOrScanned(): void
    {
        $registry = $this->diskRegistry(['shipit' => 'sneaky body']);

        $history = NonInteractive::historyFrom(
            'summarise this',
            "/shipit attach yourself\n\$shipit too",
            null,
            $notices,
            null,
            $registry,
        );

        $this->assertSame("/shipit attach yourself\n\$shipit too\n\nsummarise this", $history[0]->content, 'stdin rides byte-identical, rewrite or scan never touching it');
        $this->assertSame([], $history[0]->attachments, 'a pipeline cannot choose the skill of a turn it did not type');
        $this->assertSame([], $notices);
    }

    public function testWithNoRegistryThePromptRidesByteIdentical(): void
    {
        foreach (['/shipit review', '$shipit review'] as $typed) {
            $history = NonInteractive::historyFrom($typed, null, null, $notices);

            $this->assertSame($typed, $history[0]->content, 'the legacy caller shape survives: no registry, no channel');
            $this->assertSame([], $history[0]->attachments);
            $this->assertSame([], $notices);
        }
    }

    public function testADisabledSkillMentionTravelsAsANoticeOnThisRoute(): void
    {
        $history = NonInteractive::historyFrom(
            '$off please',
            null,
            null,
            $notices,
            null,
            $this->diskRegistry(['off' => 'x'], off: ['off']),
        );

        $this->assertSame('$off please', $history[0]->content, 'the refusal is an attachment decision, not a text rewrite');
        $this->assertSame([], $history[0]->attachments, 'a disabled skill attaches nothing');
        $this->assertCount(1, $notices, 'the caller drains it to stderr, the @-notice way');
        $this->assertStringContainsString('$off was not attached', $notices[0]);
    }

    public function testSkillAttachmentsJoinBeforeTheFileOnes(): void
    {
        file_put_contents($this->project . '/a.txt', "alpha\n");
        $history = NonInteractive::historyFrom(
            '/shipit read @a.txt',
            null,
            $this->project,
            $notices,
            null,
            $this->diskRegistry(['shipit' => 'Review $ARGUMENTS please']),
        );

        $this->assertSame('$shipit read @a.txt', $history[0]->content);
        $attachments = $history[0]->attachments;
        $this->assertCount(2, $attachments, 'the rewrite scanned for `@` in the rewritten text, the TUI order');
        $this->assertSame('shipit', $attachments[0]->skill, 'the skill body reads ahead of the mentioned file');
        $this->assertStringEndsWith('a.txt', $attachments[1]->path);
        $this->assertStringContainsString('read @a.txt', $attachments[0]->data, '$ARGUMENTS keeps the file mention in its words');
        $this->assertSame([], $notices);
    }

    public function testTheLiveChainFeedsTheBackendsOwnRegistryToTheTypedPrompt(): void
    {
        $provider = new ScriptedProvider([new CompleteResponse(content: 'all good')]);
        $backend = EngineBackend::new($provider, 'm')->withSkillRegistry($this->diskRegistry(['shipit' => 'Review $ARGUMENTS please']));

        $args = ArgvParser::parse(['candy-code', '-p', '/shipit review the diff']);
        $this->assertNull($args->usageError);

        ob_start();
        $code = NonInteractive::run($args, $backend);
        $console = (string) ob_get_clean();

        $this->assertSame(NonInteractive::EXIT_OK, $code);
        $this->assertSame('all good', trim($console));
        $this->assertCount(1, $provider->requests, 'exactly one billed request');
        $request = $provider->requests[0];
        $this->assertInstanceOf(CompleteRequest::class, $request);
        // print_r reaches every property, private included — the rows are
        // message objects, and json_encode of them is `[{},{}]` noise.
        $wire = (string) print_r($request->messages, true);
        $this->assertStringContainsString('$shipit review the diff', $wire, 'argv → run() → historyFrom → wire: the rewrite rode the real launch');
        $this->assertStringNotContainsString('/shipit review the diff', $wire, 'the slash spelling reached the provider nowhere');
        $this->assertStringContainsString('Review review the diff please', $wire, 'and the substituted skill body rode the same turn');
    }

    public function testTheLiveChainLeavesThePromptUntouchedWithoutARegistry(): void
    {
        $provider = new ScriptedProvider([new CompleteResponse(content: 'all good')]);
        $backend = EngineBackend::new($provider, 'm');

        $args = ArgvParser::parse(['candy-code', '-p', '/shipit review the diff']);
        $this->assertNull($args->usageError);

        ob_start();
        $code = NonInteractive::run($args, $backend);
        ob_end_clean();

        $this->assertSame(NonInteractive::EXIT_OK, $code);
        $this->assertCount(1, $provider->requests);
        $wire = (string) print_r($provider->requests[0]->messages, true);
        $this->assertStringContainsString('/shipit review the diff', $wire, 'an engine without a registry sends what was typed, byte-identical to the pre-lane shape');
        $this->assertStringNotContainsString('Review review the diff please', $wire);
    }
}
