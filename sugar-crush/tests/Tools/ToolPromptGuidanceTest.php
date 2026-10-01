<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tools;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Context\PromptSection;
use SugarCraft\Crush\Context\Stability;
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\Hooks\HookRegistry;
use SugarCraft\Crush\Providers\EchoProvider;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Runtime;
use SugarCraft\Crush\Tools\BuiltIn\Read;
use SugarCraft\Crush\Tools\BuiltIn\TaskTool;
use SugarCraft\Crush\Tools\BuiltIn\Write;
use SugarCraft\Crush\Tools\PromptGuidance;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolResult;

/**
 * The {@see PromptGuidance} seam, end to end: a wired tool that implements the
 * interface contributes one prose fragment to the assembled system prompt, in
 * `name()` order, and a tool that does not implement it contributes ZERO prompt
 * bytes (prompt_expand.md §4.17, §9.9, §10 seam 9; plan P9.S1).
 *
 * HARNESS. `Runtime::buildSystemPrompt()` and `Runtime::systemPromptSections()`
 * are private by pin (§11 — more than a dozen reflection call sites, including
 * this file's), so both are reached by reflection exactly as
 * `tests/BaseSystemPromptTest.php` reaches them, and the Apps are built with
 * `App::new(...)->withTools([...])` — a tool "disabled for this session" simply
 * is not in that list, which is the only state the renderer can observe.
 *
 * Nothing here walks a tree: the fragment assertions read the two reference
 * implementers directly, and the name-discipline sweep reads the wired roster
 * from {@see Bootstrap::tools()}. Two renders of the same {@see Runtime} share
 * one memoized `<env>` block, so the byte-identity assertions below compare
 * exactly the layer under test and nothing else.
 */
final class ToolPromptGuidanceTest extends TestCase
{
    // ─── 1. both reference tools wired → both fragments render ───────────

    public function testBothReferenceToolsContributeTheirFragments(): void
    {
        $read = new Read();
        $write = new Write();
        $runtime = $this->guidanceRuntime();
        $app = $this->toolApp(new EchoProvider(), [$read, $write]);

        $prompt = $this->promptFor($runtime, $app);

        self::assertStringContainsString($read->promptGuidance(), $prompt);
        self::assertStringContainsString($write->promptGuidance(), $prompt);

        // One section carries both, unfenced, in the Static region — the
        // envelope R-C forbids and the registration-order leak R-B forbids
        // both live or die here.
        $carriers = [];

        foreach ($this->sectionsFor($runtime, $app) as $section) {
            if (str_contains($section->render(), $read->promptGuidance())) {
                $carriers[] = $section;
            }
        }

        self::assertCount(1, $carriers, 'both fragments belong to exactly one layer');
        self::assertSame('', $carriers[0]->fence());
        self::assertSame(Stability::Static, $carriers[0]->stability());
        self::assertSame(
            $read->promptGuidance() . "\n\n" . $write->promptGuidance(),
            $carriers[0]->render(),
        );
    }

    // ─── 2. one omitted → only its fragment is absent ────────────────────

    public function testOmittingAToolDropsExactlyItsFragment(): void
    {
        $read = new Read();
        $write = new Write();
        $provider = new EchoProvider();

        $onlyRead = $this->toolApp($provider, [$read]);
        $onlyWrite = $this->toolApp($provider, [$write]);

        $runtime = $this->guidanceRuntime();
        $readPrompt = $this->promptFor($runtime, $onlyRead);
        $writePrompt = $this->promptFor($this->guidanceRuntime(), $onlyWrite);

        self::assertStringContainsString($read->promptGuidance(), $readPrompt);
        self::assertStringNotContainsString($write->promptGuidance(), $readPrompt);

        self::assertStringContainsString($write->promptGuidance(), $writePrompt);
        self::assertStringNotContainsString($read->promptGuidance(), $writePrompt);
    }

    // ─── 3. name discipline: no fragment names another wired tool ────────

    public function testNoFragmentNamesAnotherWiredTool(): void
    {
        $fragments = [
            'Read' => (new Read())->promptGuidance(),
            'Task' => (new TaskTool())->promptGuidance(),
            'Write' => (new Write())->promptGuidance(),
        ];

        self::assertNotEmpty($fragments['Read']);
        self::assertNotEmpty($fragments['Task']);
        self::assertNotEmpty($fragments['Write']);

        $wired = [];

        foreach (Bootstrap::tools(__DIR__) as $tool) {
            $wired[] = $tool->name();
        }

        self::assertContains('Read', $wired);
        self::assertContains('Write', $wired);

        foreach ($fragments as $owner => $fragment) {
            foreach ($wired as $other) {
                if ($other === $owner) {
                    continue;
                }

                self::assertStringNotContainsString(
                    $other,
                    $fragment,
                    "the {$owner} fragment names the sibling tool {$other}, which is a dangling "
                    . 'reference whenever that tool is not wired',
                );
            }
        }
    }

    // ─── 4. ordering is by name(), never by registration order ───────────

    public function testFragmentsRenderSortedByName(): void
    {
        $read = new Read();
        $write = new Write();
        $provider = new EchoProvider();

        $forward = $this->promptFor($this->guidanceRuntime(), $this->toolApp($provider, [$read, $write]));
        $reverse = $this->promptFor($this->guidanceRuntime(), $this->toolApp($provider, [$write, $read]));

        self::assertSame($forward, $reverse, 'registration order leaked into the prompt bytes');

        // And it is the SORTED order rather than any order that merely agrees
        // with itself: 'Read' < 'Write', so Read's fragment must come first.
        self::assertLessThan(
            strpos($forward, $write->promptGuidance()),
            strpos($forward, $read->promptGuidance()),
        );
    }

    // ─── 5. nothing implements the seam → zero bytes, zero sections ──────

    public function testToolSetWithoutAnImplementerContributesNothingAtAll(): void
    {
        $provider = new EchoProvider();

        $runtime = $this->guidanceRuntime();
        $noToolList = $this->promptFor($runtime, App::new($provider, 'claude-sonnet-4-6'));
        $emptyList = $this->promptFor($runtime, $this->toolApp($provider, []));
        $plainOnly = $this->promptFor($runtime, $this->toolApp($provider, [$this->plainTool()]));

        self::assertSame($noToolList, $emptyList);
        self::assertSame($noToolList, $plainOnly);

        // Byte-identity of the prompt alone would also hold for a layer that
        // renders '' and is dropped by the assembler, so the section list is
        // checked too: the absent layer must not occupy a slot at all.
        $bareSections = $this->sectionsFor(new Runtime(new EchoProvider(), new HookManager(new HookRegistry())), App::new($provider, 'claude-sonnet-4-6'));
        $emptySections = $this->sectionsFor(new Runtime(new EchoProvider(), new HookManager(new HookRegistry())), $this->toolApp($provider, []));
        $plainSections = $this->sectionsFor(new Runtime(new EchoProvider(), new HookManager(new HookRegistry())), $this->toolApp($provider, [$this->plainTool()]));

        self::assertSame($this->bodies($bareSections), $this->bodies($emptySections));
        self::assertSame($this->bodies($bareSections), $this->bodies($plainSections));
    }

    // ─── 6. an implementer returning '' contributes nothing ──────────────

    public function testEmptyFragmentContributesNoBytesBesideASpeakingSibling(): void
    {
        $provider = new EchoProvider();
        $read = new Read();

        $runtime = $this->guidanceRuntime();
        $withSilence = $this->promptFor($runtime, $this->toolApp($provider, [$read, $this->silentTool()]));
        $withoutSilence = $this->promptFor($this->guidanceRuntime(), $this->toolApp($provider, [$read]));

        self::assertSame($withoutSilence, $withSilence);
        self::assertStringContainsString($read->promptGuidance(), $withSilence);

        $runtimeAlone = $this->guidanceRuntime();
        $silenceOnly = $this->sectionsFor($runtimeAlone, $this->toolApp($provider, [$this->silentTool()]));
        $bareAlone = $this->sectionsFor(
            new Runtime(new EchoProvider(), new HookManager(new HookRegistry())),
            App::new($provider, 'claude-sonnet-4-6'),
        );

        self::assertSame($this->bodies($bareAlone), $this->bodies($silenceOnly));
    }

    // ─── harness ─────────────────────────────────────────────────────────

    private function guidanceRuntime(): Runtime
    {
        return new Runtime(new EchoProvider(), new HookManager(new HookRegistry()));
    }

    private function toolApp(ProviderInterface $provider, array $tools): App
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
     * @param list<PromptSection> $sections
     *
     * @return list<string>
     */
    private function bodies(array $sections): array
    {
        return array_map(static fn(PromptSection $section): string => $section->render(), $sections);
    }

    /**
     * A wired tool that does not implement {@see PromptGuidance} at all — the
     * unknown/user-supplied case the interface docblock calls the safe default.
     */
    private function plainTool(): Tool
    {
        return new class () implements Tool {
            public function name(): string
            {
                return 'PlainDouble';
            }

            public function description(): string
            {
                return 'A tool with nothing to say in the system prompt.';
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => []];
            }

            public function execute(array $args): ToolResult
            {
                return new ToolResult(toolCallId: '', content: '', isError: false);
            }
        };
    }

    /**
     * A wired tool that DOES implement the interface and returns `''` — the
     * second way to contribute nothing, and the one the fragment-level guard
     * is responsible for.
     */
    private function silentTool(): Tool
    {
        return new class () implements Tool, PromptGuidance {
            public function name(): string
            {
                return 'SilentDouble';
            }

            public function description(): string
            {
                return 'A tool that opted in and stayed quiet.';
            }

            public function promptGuidance(): string
            {
                return '';
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => []];
            }

            public function execute(array $args): ToolResult
            {
                return new ToolResult(toolCallId: '', content: '', isError: false);
            }
        };
    }
}
