<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tools;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Context\PromptSection;
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\Hooks\HookRegistry;
use SugarCraft\Crush\Providers\EchoProvider;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Runtime;
use SugarCraft\Crush\Tools\BuiltIn\Bash;
use SugarCraft\Crush\Tools\BuiltIn\Read;
use SugarCraft\Crush\Tools\BuiltIn\Write;

/**
 * The {@see Bash} git/PR-playbook fragment, end to end: it rides the
 * {@see \SugarCraft\Crush\Tools\PromptGuidance} seam, so it is present in the
 * assembled system prompt exactly when the Bash tool is wired and absent
 * otherwise, it carries its own unframed tag pair, and it holds the three
 * discipline rules the seam imposes (prompt_plan.md P9.S3).
 *
 * HARNESS mirrors `ToolPromptGuidanceTest`: `Runtime::buildSystemPrompt()` and
 * `Runtime::systemPromptSections()` are private by pin, so both are reached by
 * reflection, and a tool is "wired for this session" simply by appearing in
 * `App::new(...)->withTools([...])`.
 *
 * The tag-bearing assertions read the guidance LAYER section (the Static,
 * unfenced section whose body is the joined fragments) rather than counting a
 * raw token across the WHOLE prompt. That is not a weakening — the environment
 * layer embeds a live `git diff` of the working tree, so while this very file's
 * source is uncommitted the literal tag also appears inside that diff, and a
 * whole-prompt count would measure the tree state rather than the seam. The
 * layer is the seam's own surface and the one the renderer controls; the layer
 * is then pinned to appear verbatim in the assembled prompt.
 *
 * The name-discipline guard lives HERE rather than in the existing seam test:
 * that test sweeps a hardcoded fragment list (the two reference implementers),
 * so the Bash fragment could only ever be checked by a file that builds it —
 * and this is the only file this step may add. The sweep reads the LIVE
 * {@see Bootstrap::tools()} roster so a future wired tool is covered too.
 */
final class BashPromptGuidanceTest extends TestCase
{
    private const OPENER = '<git_commits>';
    private const CLOSER = '</git_commits>';

    // ─── 1. fragment present iff the Bash tool is wired ──────────────────

    public function testFragmentAppearsExactlyWhenBashIsWired(): void
    {
        $bash = new Bash();
        $read = new Read();
        $write = new Write();
        $provider = new EchoProvider();

        $withPrompt = $this->promptFor($this->runtime(), $this->app($provider, [$bash, $read, $write]));
        $withoutPrompt = $this->promptFor($this->runtime(), $this->app($provider, [$read, $write]));

        self::assertNotEmpty($bash->promptGuidance());

        $withLayer = $this->guidanceLayer($this->sectionsFor($this->runtime(), $this->app($provider, [$bash, $read, $write])), $read);
        $withoutLayer = $this->guidanceLayer($this->sectionsFor($this->runtime(), $this->app($provider, [$read, $write])), $read);

        self::assertStringContainsString($bash->promptGuidance(), $withLayer);
        self::assertStringContainsString(self::OPENER, $withLayer);
        self::assertStringContainsString(self::CLOSER, $withLayer);

        // Absence, not a placeholder: dropping Bash drops the whole block.
        self::assertStringNotContainsString(self::OPENER, $withoutLayer);
        self::assertStringNotContainsString(self::CLOSER, $withoutLayer);

        // The layer is the seam's real surface, but it must still reach the
        // model verbatim — pin it into the assembled prompt without re-counting
        // the tag there.
        self::assertStringContainsString($withLayer, $withPrompt);

        // The siblings ride along untouched either way — this fragment is not
        // allowed to disturb the layer the other two already own.
        self::assertStringContainsString($read->promptGuidance(), $withLayer);
        self::assertStringContainsString($write->promptGuidance(), $withLayer);
        self::assertStringContainsString($read->promptGuidance(), $withoutLayer);
        self::assertStringContainsString($write->promptGuidance(), $withoutLayer);
    }

    // ─── 2. the never-do list is present ─────────────────────────────────

    public function testFragmentCarriesTheNeverDoList(): void
    {
        $fragment = (new Bash())->promptGuidance();

        foreach (['--no-verify', 'force-push', 'git add -A'] as $phrase) {
            self::assertStringContainsString($phrase, $fragment, "the never-do entry '{$phrase}' went missing");
        }
    }

    // ─── 3. exactly one opener + one closer in the rendered layer ────────

    public function testTagPairBalancesToExactlyOneInTheLayer(): void
    {
        $read = new Read();
        $sections = $this->sectionsFor($this->runtime(), $this->app(new EchoProvider(), [new Bash(), $read, new Write()]));
        $layer = $this->guidanceLayer($sections, $read);

        self::assertSame(1, substr_count($layer, self::OPENER), 'the fragment must open exactly one tag pair');
        self::assertSame(1, substr_count($layer, self::CLOSER), 'the fragment must close exactly one tag pair');
    }

    // ─── 4. the commit steps precede the never-do block ──────────────────

    /**
     * The two content halves have a reason for their order: the model reads the
     * steps it must perform before the prohibitions that bound them. Reversing
     * the halves is the experiment this assertion exists to catch. The steps
     * are the generic ones since step 0.3 removed the SugarCraft PR cadence
     * (its `gh pr merge` chain now lives only in the monorepo's AGENTS.md).
     */
    public function testCommitStepsPrecedeNeverDoBlock(): void
    {
        $fragment = (new Bash())->promptGuidance();

        $stage = strpos($fragment, '2. Stage exactly the paths');
        $neverDo = strpos($fragment, '--no-verify');

        self::assertIsInt($stage);
        self::assertIsInt($neverDo);
        self::assertLessThan($neverDo, $stage, 'the staging step must precede the never-do list');
    }

    // ─── 5. name discipline: the fragment names no sibling wired tool ────

    public function testFragmentPassesLiveNameDisciplineSweep(): void
    {
        $fragment = (new Bash())->promptGuidance();

        $wired = [];
        foreach (Bootstrap::tools(__DIR__) as $tool) {
            $wired[] = $tool->name();
        }

        self::assertContains('Bash', $wired, 'Bash must be wired for this sweep to mean anything');

        foreach ($wired as $other) {
            if ($other === 'Bash') {
                continue;
            }

            self::assertStringNotContainsString(
                $other,
                $fragment,
                "the Bash fragment names the sibling tool {$other}, which is a dangling "
                . 'reference whenever that tool is not wired',
            );
        }
    }

    /**
     * The same rule stated against the four file tools a git/PR playbook is most
     * tempted to name, spelled out so the ban is legible in the failure text and
     * not only in a loop over a live roster.
     */
    public function testFragmentNamesNoForbiddenFileTool(): void
    {
        $fragment = (new Bash())->promptGuidance();

        foreach (['Read', 'Write', 'Edit', 'Grep'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $fragment, "the fragment must not name {$forbidden}");
        }
    }

    // ─── 6. the seam contract: non-empty, self-wrapped, no trailing newline ─

    public function testFragmentHonoursTheSeamContract(): void
    {
        $fragment = (new Bash())->promptGuidance();

        self::assertNotSame('', $fragment);
        self::assertStringStartsWith(self::OPENER, $fragment, 'the fragment must wrap itself');
        self::assertStringEndsWith(self::CLOSER, $fragment);
        self::assertNotSame("\n", substr($fragment, -1), 'the assembler supplies separators; the body may not end in a newline');
    }

    // ─── harness ─────────────────────────────────────────────────────────

    private function runtime(): Runtime
    {
        return new Runtime(new EchoProvider(), new HookManager(new HookRegistry()));
    }

    private function app(ProviderInterface $provider, array $tools): App
    {
        return App::new($provider, 'claude-sonnet-4-6')->withTools($tools);
    }

    private function promptFor(Runtime $runtime, App $app): string
    {
        $prompt = (new ReflectionMethod($runtime, 'buildSystemPrompt'))->invoke($runtime, $app);

        self::assertIsString($prompt);

        return $prompt;
    }

    /**
     * @return list<PromptSection>
     */
    private function sectionsFor(Runtime $runtime, App $app): array
    {
        $sections = (new ReflectionMethod($runtime, 'systemPromptSections'))->invoke($runtime, $app);

        self::assertIsArray($sections);

        return $sections;
    }

    /**
     * The one section that carries the joined tool-guidance fragments — located
     * by a fragment the test knows is wired (Read) rather than by counting
     * sections, so the guard is about the seam's surface and nothing else.
     *
     * @param list<PromptSection> $sections
     */
    private function guidanceLayer(array $sections, Read $read): string
    {
        $marker = $read->promptGuidance();
        $carriers = array_values(array_filter(
            $sections,
            static fn(PromptSection $section): bool => str_contains($section->render(), $marker),
        ));

        self::assertCount(1, $carriers, 'the guidance fragments must belong to exactly one layer');

        return $carriers[0]->render();
    }
}
