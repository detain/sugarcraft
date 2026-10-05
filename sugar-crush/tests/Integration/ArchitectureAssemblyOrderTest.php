<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Integration;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Context\EnvironmentBlock;
use SugarCraft\Crush\Context\MemoryBlock;
use SugarCraft\Crush\Context\PromptSection;
use SugarCraft\Crush\Context\RepoMapBlock;
use SugarCraft\Crush\Context\Sections\MaximsSection;
use SugarCraft\Crush\Context\SymbolMapBlock;
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\Hooks\HookRegistry;
use SugarCraft\Crush\Runtime;
use SugarCraft\Crush\Skills\Skill;
use SugarCraft\Crush\Tests\Prompt\PromptFixture;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;
use SugarCraft\Crush\Tools\BuiltIn\Read;

/**
 * P11.S2-pin: `docs/ARCHITECTURE.md`'s "The system prompt, in assembly order"
 * section is PROSE that restates a CODE fact — the exact slot order of
 * {@see Runtime::systemPromptSections()} — and prose without a generator rots
 * (the same argument GlobFigureDriftTest makes about character counts). The
 * P11.S2 step rewrote the page to the eleven slots shipped since P9.S1; a
 * manual check was recorded in the worklog, but the plan's done-when also says
 * "if a cheap assertion can pin it, add one", and ARCHITECTURE.md carries zero
 * derived guards today. This file is that guard: it drives the REAL private
 * assembler, classifies every produced section into one token per documented
 * slot, parses the numbered list out of the page, and asserts the two
 * sequences are equal in membership AND position.
 *
 * WHY SECTION OBJECTS AND NOT THE FOLDED STRING:
 * {@see \SugarCraft\Crush\Runtime::assembleSections()} folds empty renders
 * away, so a byte-position probe cannot see a slot that is present in the
 * ordered list yet renders '' for this App — and the documented fact is about
 * the LIST ("returns the prompt as an ordered list of sections — eleven
 * slots"). SystemPromptWiringTest's fixture-order test already pins string
 * positions of the non-empty halves; this file pins the list itself, against
 * the page, which nothing else does.
 *
 * BOTH SIDES CAN RED THE TEST, AND EACH FAILURE MEANS SOMETHING DIFFERENT.
 * If a section class or fence is renamed, the classifier drops the section
 * into its fail-with-diagnostic default and says which bytes it could not
 * place. If a doc item is renumbered, reworded away from its anchor needle,
 * or reordered against the code, the needle or sequence assertion names the
 * item number and the expected wording. A section ADDED to the assembler
 * without a doc row lands on the uncategorized default too, because the
 * App below wires every optional slot, so the live sequence must have an
 * equal number of members to pass.
 *
 * The mapping between prose labels and code identities lives in
 * {@see self::DOC_SLOT_PINS} as an explicit table — doc item number to section
 * token to the needle the item's own text must still contain — so the pin is
 * readable as the sentence it enforces rather than as an anonymous array.
 *
 * No golden bytes are produced here: the test reads the section LIST and the
 * markdown file, never the assembled prompt string, so a dirty working tree
 * cannot move any assertion (the law-4h concern that keeps prompt-render
 * suites for committed states only does not apply to a list-shape probe).
 */
final class ArchitectureAssemblyOrderTest extends TestCase
{
    use HomeSandboxTrait;

    /**
     * The heading whose numbered list this test parses, spelled exactly as the
     * page prints it; the parse stops at the next heading of the same rank.
     */
    private const DOC_HEADING = '### The system prompt, in assembly order';

    /**
     * doc item number => [section token, needle the item text must contain].
     *
     * The tokens are the classifier's vocabulary below; the needles are phrases
     * lifted from the current page that name each slot the way the code names
     * it (item 11's needle doubles as the LAST-position claim the whole cache
     * paragraph rests on). Renumbering the list, deleting an item, or rewording
     * one past its anchor needle reddens {@see self::DOC_SLOT_PINS}'s consumer
     * with the item number in the message.
     */
    private const DOC_SLOT_PINS = [
        1 => ['base', 'base instructions'],
        2 => ['maxims', 'MaximsSection'],
        3 => ['tool-guidance', 'tool-guidance layer'],
        4 => ['repo-map', 'RepoMapBlock'],
        5 => ['symbol-map', 'SymbolMapBlock'],
        6 => ['user-rules', '<user-rules>'],
        7 => ['project-doc', 'InstructionFileLoader'],
        8 => ['project-rule', 'project-tier rules'],
        9 => ['memory', 'MemoryBlock'],
        10 => ['skill-body', 'full bodies'],
        11 => ['skill-listing', 'listForPrompt'],
        12 => ['plan-mode', 'PlanModeSection'],
        13 => ['env', 'LAST'],
    ];

    // Canary bytes are absurd tokens, never prose: each one is the ONLY place
    // its string appears, so classification can key on containment without a
    // false hit from ordinary layer text.
    private const USER_RULE_CANARY = 'OBSIDIAN-PIN-USER-RULE';

    private const INSTRUCTION_DOC_CANARY = 'DIAMOND-PIN-INSTRUCTION-DOC';

    private const PROJECT_RULE_CANARY = 'SAPPHIRE-PIN-PROJECT-RULE';

    private const SKILL_BODY_CANARY = 'EMERALD-PIN-SKILL-BODY';

    /** Sits in the listed skill's DESCRIPTION, so it reaches the listing line. */
    private const LISTED_SKILL_CANARY = 'GARNET-PIN-LISTED-SKILL';

    /** First words of the base heredoc — verified against Runtime::basePrompt(). */
    private const BASE_CANARY = 'an AI coding assistant working inside a terminal';

    /** First words of Read's promptGuidance() fragment — verified against the class. */
    private const READ_GUIDANCE_CANARY = 'The Read tool returns file contents one page at a time';

    /** SkillMatcher::listForPrompt()'s header — verified against the method body. */
    private const LISTING_HEADER = 'Available skills (invoke via Skill tool):';

    /** @var list<PromptFixture> */
    private array $fixtures = [];

    /** @var list<string> HOME sandboxes handed to useHomeSandbox(), removed after restore. */
    private array $tempHomes = [];

    protected function tearDown(): void
    {
        foreach ($this->fixtures as $fixture) {
            $fixture->destroy();
        }

        $this->fixtures = [];

        // Installed by assembledTokens() for the RuleLoader user tier; restored
        // before unlinking so a failed restore still gets a shot at every path.
        $this->restoreHomeSandbox();
        foreach ($this->tempHomes as $home) {
            @unlink($home . '/.sugar-crush/rules/pin-user.md');
            @rmdir($home . '/.sugar-crush/rules');
            @rmdir($home . '/.sugar-crush');
            @rmdir($home);
        }

        $this->tempHomes = [];
    }

    public function testTheDocumentedSlotOrderMatchesTheAssembledSectionOrder(): void
    {
        $tokens = $this->assembledTokens();
        $documented = $this->documentedSlotItems();

        // The page's list must be the thirteen consecutive items the code
        // produces — membership and position together. assertSame on two
        // string lists prints the first divergence, which is exactly the
        // diagnostic a reordered slot needs.
        self::assertSame(
            array_keys(self::DOC_SLOT_PINS),
            array_keys($documented),
            'the ARCHITECTURE.md assembly list must number exactly items 1..13 in ascending order; '
            . 'a renumbered, inserted, or deleted slot changes this sequence and the page and code '
            . 'have to be corrected together',
        );

        foreach (self::DOC_SLOT_PINS as $number => [$token, $needle]) {
            self::assertStringContainsString(
                $needle,
                $documented[$number],
                "doc item {$number} no longer contains the anchor phrase \"{$needle}\" that maps it "
                . "to assembler token \"{$token}\" — if the wording moved on purpose, this table is "
                . 'the mapping to update, in the same commit as the page',
            );
        }

        // array_column walks the const in file order, which is slot order 1..13.
        $expected = array_column(self::DOC_SLOT_PINS, 0);

        self::assertSame(
            $expected,
            $tokens,
            'Runtime::systemPromptSections() no longer assembles the slots in the order '
            . 'docs/ARCHITECTURE.md publishes — one side of this pair must change, in one commit',
        );
    }

    /**
     * The P3.S1 invariant on its own, apart from the doc: volatile <env> is the
     * final section of the ordered list, which is what the cache paragraph in
     * both the page and Runtime::buildSystemPrompt()'s docblock rests on. A
     * dedicated pin so an env move fails HERE with the cache argument in the
     * message, instead of only showing up as one divergence inside the
     * sequence diff above.
     */
    public function testTheEnvironmentBlockIsTheLastAssembledSection(): void
    {
        $tokens = $this->assembledTokens();

        self::assertSame('env', end($tokens), 'the volatile EnvironmentBlock must stay the LAST section');
        self::assertCount(\count(self::DOC_SLOT_PINS), $tokens, 'every documented slot is wired in this fixture, so the list length is itself pinned');
    }

    // -------------------------------------------------------------------------
    // Harness
    // -------------------------------------------------------------------------

    /**
     * Drive the real private assembler and classify each section to one token.
     *
     * The App wires EVERY optional slot — one PromptGuidance tool, one user
     * rule, one root instruction doc, one project rule, one enabled skill,
     * one listed skill — so the returned list carries exactly one section per
     * documented item and equality (not subsequence matching) is the assertable
     * relation. Runtime/EnvironmentBlock construction mirrors
     * PromptFixture::systemPrompt() (frozen date, fixed platform) because this
     * test needs the LIST, and the fixture only folds to a string.
     *
     * @return list<string> classifier tokens, in assembler order
     */
    private function assembledTokens(): array
    {
        $fixture = new PromptFixture();
        $this->fixtures[] = $fixture;

        $home = sys_get_temp_dir() . '/p11s2pin_home_' . uniqid('', true);
        mkdir($home . '/.sugar-crush/rules', 0o700, true);
        $this->tempHomes[] = $home;
        $this->useHomeSandbox($home);
        file_put_contents(
            $home . '/.sugar-crush/rules/pin-user.md',
            "---\nname: pin-user\n---\n" . self::USER_RULE_CANARY . "\n",
        );

        $fixture->write('AGENTS.md', self::INSTRUCTION_DOC_CANARY);
        $fixture->write(
            '.sugar-crush/rules/pin-project.md',
            "---\nname: pin-project\n---\n" . self::PROJECT_RULE_CANARY . "\n",
        );

        $fixture->addSkill(new Skill(
            name: 'pin-enabled-skill',
            description: 'Enabled skill whose body the prompt carries.',
            userInvocable: true,
            disableModelInvocation: false,
            allowedTools: null,
            disallowedTools: null,
            model: null,
            effort: 'low',
            context: 'thread',
            paths: [],
            content: self::SKILL_BODY_CANARY,
            sourcePath: '/pin/enabled/SKILL.md',
        ));
        // P7.S3 split: an enabled skill is excluded from the level-1 listing,
        // so the listing slot needs its own discovered-but-not-enabled skill.
        $fixture->addListedSkill(new Skill(
            name: 'pin-listed-skill',
            description: self::LISTED_SKILL_CANARY,
            userInvocable: true,
            disableModelInvocation: false,
            allowedTools: null,
            disallowedTools: null,
            model: null,
            effort: 'low',
            context: 'thread',
            paths: [],
            content: 'never reaches the prompt',
            sourcePath: '/pin/listed/SKILL.md',
        ));

        $app = $fixture->app()->withTools([new Read()]);

        // Slot 12 joins the list only while the turn's gate is in plan mode
        // (roadmap 5.7-1), so the chain carries a plan-mode gate.
        $hooks = new HookManager(new HookRegistry());
        $hooks->register(new \SugarCraft\Crush\Hooks\BuiltIn\PermissionGateHook(
            new \SugarCraft\Crush\Permissions\PermissionGate(\SugarCraft\Crush\Permissions\PermissionMode::Plan),
        ));

        $runtime = new Runtime(
            $app->provider,
            $hooks,
            new EnvironmentBlock($fixture->root(), $app->model, new DateTimeImmutable('2026-01-15 12:00:00 UTC'), 'linux'),
        );

        $sections = \Closure::bind(
            static fn(Runtime $runtime, object $app): array => $runtime->systemPromptSections($app),
            null,
            Runtime::class,
        );
        // Slot 5 joins the list only once the session holds a symbol-map
        // capture (EngineBackend primes it before a turn's fork); prime it
        // here so every documented slot is wired. The fixture is no git
        // checkout, so the capture is the empty block — the slot, not bytes.
        $runtime->primeSymbolMap($app);
        $sections = $sections($runtime, $app);

        return array_map($this->classify(...), $sections);
    }

    /**
     * One section in, one slot token out. Class identity first (the four
     * block classes own slots 2, 4, 8, 11 and cannot collide with anything),
     * then the two fences that name their slot outright, then containment of
     * the canaries — the four fenceless anonymous layers are told apart only
     * by what unique bytes they carry.
     *
     * The default arm FAILS rather than guesses: a section the pin cannot
     * place (renamed class, new slot, leaked host content) prints its fence
     * and body head so the next reader sees what arrived.
     */
    private function classify(PromptSection $section): string
    {
        if ($section instanceof MaximsSection) {
            return 'maxims';
        }
        if ($section instanceof RepoMapBlock) {
            return 'repo-map';
        }
        if ($section instanceof SymbolMapBlock) {
            return 'symbol-map';
        }
        if ($section instanceof MemoryBlock) {
            return 'memory';
        }
        if ($section instanceof EnvironmentBlock) {
            return 'env';
        }
        if ($section instanceof \SugarCraft\Crush\Context\Sections\PlanModeSection) {
            return 'plan-mode';
        }

        $body = $section->render();

        if ($section->fence() === '<user-rules>') {
            return 'user-rules';
        }
        if ($section->fence() === '<project-instructions>') {
            // Docs and project-tier rules share the fence BY DESIGN (same
            // authorship voice), so the provenance the test itself planted is
            // the only lawful way to tell slot 6 from slot 7 apart.
            if (str_contains($body, self::PROJECT_RULE_CANARY)) {
                return 'project-rule';
            }
            if (str_contains($body, self::INSTRUCTION_DOC_CANARY)) {
                return 'project-doc';
            }
        } elseif (str_contains($body, self::BASE_CANARY)) {
            return 'base';
        } elseif (str_contains($body, self::READ_GUIDANCE_CANARY)) {
            return 'tool-guidance';
        } elseif (str_contains($body, self::SKILL_BODY_CANARY)) {
            return 'skill-body';
        } elseif (str_contains($body, self::LISTING_HEADER)) {
            return 'skill-listing';
        }

        self::fail(sprintf(
            'assembled section list carries a section this pin cannot classify (fence "%s", body opens "%s") '
            . '— a renamed, reordered-into-existence, or newly added slot has to be mapped in classify() '
            . 'and DOC_SLOT_PINS together with the page',
            $section->fence(),
            substr(str_replace("\n", '\n', $body), 0, 120),
        ));
    }

    /**
     * Read the numbered list out of ARCHITECTURE.md's assembly section.
     *
     * A list item starts at a line matching /^(\d+)\. / and swallows the
     * page's 3-space continuation lines; the first unindented, non-item line
     * ends it (no item in this list contains a blank line — MEASURED at the
     * P11.S2 merge, the eleven items run back to back). Scope is the heading's
     * own section, so numbered prose elsewhere in the page cannot join the
     * sequence.
     *
     * @return array<int, string> item number => joined item text, in file order
     */
    private function documentedSlotItems(): array
    {
        $path = \dirname(__DIR__, 2) . '/docs/ARCHITECTURE.md';
        self::assertFileExists($path);

        $lines = explode("\n", (string) file_get_contents($path));
        $start = array_search(self::DOC_HEADING, $lines, true);
        self::assertIsInt($start, 'the page no longer prints its assembly-order section under the heading this pin parses');

        $items = [];
        $currentNumber = null;
        for ($i = $start + 1; $i < count($lines); $i++) {
            $line = $lines[$i];
            if (preg_match('/^#{2,3} /', $line) === 1) {
                break;
            }
            if (preg_match('/^(\d+)\. (.*)$/', $line, $m) === 1) {
                $currentNumber = (int) $m[1];
                self::assertArrayNotHasKey($currentNumber, $items, "the assembly list numbers item {$currentNumber} twice");
                $items[$currentNumber] = $m[2];
                continue;
            }
            if ($currentNumber !== null && str_starts_with($line, ' ')) {
                $items[$currentNumber] .= "\n" . $line;
                continue;
            }
            $currentNumber = null;
        }

        // Keys stay in FILE order (insertion order of the parsed items), so the
        // test's array_keys comparison sees a physically swapped pair even when
        // the swapper left the "1."/"2." labels on their moved text — sorting
        // by key here would hide exactly the reorder this pin exists to catch.
        return $items;
    }
}
