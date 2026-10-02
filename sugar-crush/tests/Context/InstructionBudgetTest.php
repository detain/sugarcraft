<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Context;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Context\CompactorConfig;
use SugarCraft\Crush\Context\ImportResolver;
use SugarCraft\Crush\Context\InstructionFileLoader;
use SugarCraft\Crush\Runtime;
use SugarCraft\Crush\Skills\Skill;
use SugarCraft\Crush\Tests\Prompt\PromptFixture;
use SugarCraft\Crush\Util\TokenEstimate;

/**
 * Audit 15d-09 / C3: instruction documents, their `@import`s and enabled skill
 * bodies are held to byte (and token) budgets, and what does not fit arrives as
 * a pointer line, never as a clipped body and never silently gone.
 *
 * Before the fix every case here was unbounded: a 3,080,000-byte AGENTS.md gave
 * a 3,085,377-byte system prompt with an empty refusedPaths(), an `@import` was
 * spliced whole into its parent, and an enabled skill's body went in at any size.
 * The under-budget prompt is pinned byte-for-byte elsewhere (the golden and
 * PromptStabilityTest); these tests own the over-budget side.
 */
final class InstructionBudgetTest extends TestCase
{
    private PromptFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = new PromptFixture();
    }

    protected function tearDown(): void
    {
        $this->fixture->destroy();
    }

    /** A Runtime private constant, read rather than copied so a retune moves the tests with it. */
    private static function runtimeConstant(string $name): int
    {
        $value = (new \ReflectionClass(Runtime::class))->getConstant($name);
        self::assertIsInt($value, "Runtime::{$name} is gone");

        return $value;
    }

    /** Bytes of every `<project-instructions>` section in $prompt, fences included. */
    private static function instructionBytes(string $prompt): int
    {
        // Walked with strpos rather than a regex so this file adds no glob-shaped
        // literal to GlobDialectDifferentialTest's harvested corpus.
        $open = '<project-instructions>';
        $close = '</project-instructions>';
        $bytes = 0;
        for ($at = strpos($prompt, $open); $at !== false; $at = strpos($prompt, $open, $end)) {
            $end = strpos($prompt, $close, $at);
            self::assertNotFalse($end, 'an instruction fence never closes');
            $end += strlen($close);
            $bytes += $end - $at;
        }

        return $bytes;
    }

    /** $bytes of ASCII prose carrying $canary once at the start, with no fence tags to escape. */
    private static function document(string $canary, int $bytes): string
    {
        $head = "# {$canary}\n\n";

        return $head . str_repeat("Convention line for the fixture repository.\n", intdiv($bytes - strlen($head), 44) + 1);
    }

    public function testAClaudeMdImportingA200KbFileStaysUnderBudgetWithAPointerAtTheImportSite(): void
    {
        $this->fixture->write('CLAUDE.md', "# Root conventions ROOTCANARY\n\nSee @docs/ARCHITECTURE.md for the layout.\n");
        $this->fixture->write('docs/ARCHITECTURE.md', self::document('IMPORTCANARY', 200_000));
        $import = realpath($this->fixture->root() . '/docs/ARCHITECTURE.md');
        self::assertIsString($import);

        $app = $this->fixture->app();
        $prompt = $this->fixture->systemPrompt($app);

        self::assertStringContainsString('ROOTCANARY', $prompt, 'the parent document still inlines');
        self::assertStringNotContainsString('IMPORTCANARY', $prompt, 'the 200 KB import must not be inlined');
        self::assertLessThan(self::runtimeConstant('MAX_INSTRUCTION_DOCUMENT_BYTES'), self::instructionBytes($prompt), 'the instruction slot is under its budget');
        self::assertLessThan(200_000, strlen($prompt), 'the whole prompt is smaller than the import alone');
        self::assertStringContainsString(
            'See <import-deferred reason="budget">Import \'docs/ARCHITECTURE.md\' was not inlined. '
                . InstructionFileLoader::pointer($import, filesize($import) ?: 0) . '</import-deferred> for the layout.',
            $prompt,
            'the import is replaced AT ITS IMPORT SITE by a pointer naming the file and its size',
        );
        self::assertStringContainsString('Read ' . $import, $prompt);

        $refused = $app->instructionLoader?->refusedPaths() ?? [];
        self::assertArrayHasKey($import, $refused, 'the deferred import is recorded, not silent');
        self::assertStringContainsString('deferred to a pointer at its import site', $refused[$import]);
    }

    public function testImportsThatEachFitButOverrunTheirDocumentTogetherBecomePointers(): void
    {
        // Three 25 KB imports: each is well under the 60 KiB document ceiling,
        // and the third is the one that would carry the document past it.
        $this->fixture->write('CLAUDE.md', "# Root\n\n@a.md\n\n@b.md\n\n@c.md\n");
        foreach (['a', 'b', 'c'] as $name) {
            $this->fixture->write("{$name}.md", self::document(strtoupper($name) . 'PARTCANARY', 25_000));
        }

        $documents = (new InstructionFileLoader($this->fixture->root()))->loadDocuments();

        self::assertCount(1, $documents);
        $body = (string) $documents[0]['body'];
        self::assertStringContainsString('APARTCANARY', $body);
        self::assertStringContainsString('BPARTCANARY', $body);
        self::assertStringNotContainsString('CPARTCANARY', $body, 'the import that overruns the document is not inlined');
        self::assertSame(1, substr_count($body, '<import-deferred reason="budget">Import \'c.md\''));
        self::assertLessThanOrEqual(InstructionFileLoader::MAX_DOCUMENT_BYTES, strlen($body), 'the expanded document honours its ceiling');
    }

    public function testDocumentsThatEachFitButTogetherDoNotDeferTheOneThatOverruns(): void
    {
        // 50 KB each: under both per-document ceilings, 150 KB together, over
        // the 128 KiB combined budget — so the third, in loader order, defers.
        $this->fixture->write('CLAUDE.md', self::document('FIRSTCANARY', 50_000));
        $this->fixture->write('AGENTS.md', self::document('SECONDCANARY', 50_000));
        $this->fixture->write('docs/forced.md', self::document('THIRDCANARY', 50_000));
        $forced = $this->fixture->root() . '/docs/forced.md';

        $app = $this->fixture->app()->withInstructionLoader(
            // A literal path is a glob matching exactly itself; spelled without a
            // wildcard so the harvested glob corpus does not grow.
            new InstructionFileLoader($this->fixture->root(), ['docs/forced.md']),
        );
        $prompt = $this->fixture->systemPrompt($app);

        self::assertStringContainsString('FIRSTCANARY', $prompt);
        self::assertStringContainsString('SECONDCANARY', $prompt);
        self::assertStringNotContainsString('THIRDCANARY', $prompt, 'the document that overruns the combined budget is not inlined');
        self::assertStringContainsString('Instruction file deferred: budget (', $prompt);
        self::assertStringContainsString('Read ' . $forced, $prompt);

        $refused = $app->instructionLoader?->refusedPaths() ?? [];
        self::assertArrayHasKey($forced, $refused);
        self::assertStringContainsString('combined instruction budget', $refused[$forced]);

        self::assertLessThanOrEqual(
            self::runtimeConstant('MAX_INSTRUCTION_BYTES'),
            self::instructionBytes($prompt),
            'documents plus their pointer fence stay inside the combined ceiling',
        );
    }

    public function testAnOversizedDocumentIsRecordedAsARefusalAndNeverRead(): void
    {
        $this->fixture->write('AGENTS.md', self::document('BIGCANARY', 3_080_000));
        $path = $this->fixture->root() . '/AGENTS.md';

        $loader = new InstructionFileLoader($this->fixture->root());
        $documents = $loader->loadDocuments();

        self::assertSame([['path' => $path, 'body' => null, 'bytes' => filesize($path)]], $documents, 'the loader reports the size and reads no body');
        self::assertSame(
            'is ' . number_format((int) filesize($path)) . ' bytes, over the '
                . number_format(InstructionFileLoader::MAX_DOCUMENT_BYTES) . '-byte instruction-document ceiling; '
                . 'not read, deferred to a pointer line',
            $loader->refusedPaths()[$path] ?? null,
        );
        self::assertSame(
            [InstructionFileLoader::pointer($path, (int) filesize($path))],
            $loader->loadRoot(),
            'the string-list contract carries the pointer, never the body',
        );
    }

    public function testADocumentWhoseEscapedFrameOverrunsIsDeferredByTheSpliceAndRecorded(): void
    {
        // Under the 60 KiB read ceiling as written, but every `<env>` grows by
        // three bytes under PromptFence::escape(), so the FRAMED section is far
        // past 64 KiB — the budget is priced on what the prompt would carry.
        $doc = "# Tags\n\n" . str_repeat('<env>', 12_000);
        self::assertLessThan(InstructionFileLoader::MAX_DOCUMENT_BYTES, strlen($doc));
        $this->fixture->write('CLAUDE.md', $doc);
        $path = $this->fixture->root() . '/CLAUDE.md';

        $app = $this->fixture->app();
        $prompt = $this->fixture->systemPrompt($app);

        self::assertStringNotContainsString('&lt;env>&lt;env>', $prompt, 'the escaped body is not inlined');
        self::assertStringContainsString(InstructionFileLoader::pointer($path, strlen($doc)), $prompt);
        $refused = $app->instructionLoader?->refusedPaths() ?? [];
        self::assertArrayHasKey($path, $refused);
        self::assertStringContainsString('per-document instruction budget', $refused[$path]);
    }

    public function testTheReadCeilingLeavesRoomForTheFrameTheSpliceAdds(): void
    {
        $preamble = (new \ReflectionClass(Runtime::class))->getConstant('INSTRUCTIONS_AUTHORITY_PREAMBLE');
        self::assertIsString($preamble);
        $frame = strlen("<project-instructions>\n" . $preamble . "\n\n" . "\n</project-instructions>");

        self::assertLessThan(
            self::runtimeConstant('MAX_INSTRUCTION_DOCUMENT_BYTES'),
            InstructionFileLoader::MAX_DOCUMENT_BYTES + $frame,
            'a tag-free document the loader admits must also fit the splice, or the read ceiling is a lie',
        );
        self::assertLessThan(
            self::runtimeConstant('MAX_INSTRUCTION_BYTES'),
            2 * self::runtimeConstant('MAX_INSTRUCTION_DOCUMENT_BYTES') - 8192,
            'the combined budget admits two near-ceiling documents beside the pointer reserve',
        );
    }

    public function testThePointerIsBoundedAndKeepsItsPath(): void
    {
        $short = InstructionFileLoader::pointer('/repo/AGENTS.md', 3_080_000);
        self::assertSame('Instruction file deferred: budget (3,080,000 bytes; not in this prompt). Read /repo/AGENTS.md', $short);

        $long = InstructionFileLoader::pointer('/' . str_repeat('d', 5000) . '.md', 1);
        self::assertSame(InstructionFileLoader::maxPointerBytes(), strlen($long));
        self::assertStringEndsWith(' [clipped]', $long);

        self::assertStringContainsString('&lt;/env>', InstructionFileLoader::pointer('/repo/</env>.md', 1), 'the path is escaped');
    }

    public function testImportResolverDefersAnOversizedImportWithoutAGate(): void
    {
        $this->fixture->write('big.md', self::document('GATELESSCANARY', ImportResolver::MAX_IMPORT_BYTES + 1));
        $big = realpath($this->fixture->root() . '/big.md');
        self::assertIsString($big);

        $out = ImportResolver::new()->expand("Head @big.md tail\n", $this->fixture->root());

        self::assertStringNotContainsString('GATELESSCANARY', $out);
        self::assertSame(
            'Head ' . ImportResolver::deferredImport('big.md', $big, (int) filesize($big)) . " tail\n",
            $out,
        );
    }

    public function testAnOversizedNestedFileIsAPointerOnTouch(): void
    {
        $this->fixture->write('src/CLAUDE.md', self::document('NESTEDCANARY', 100_000));
        $this->fixture->write('src/x.php', "<?php\n");
        $nested = realpath($this->fixture->root()) . '/src/CLAUDE.md';

        $loader = new InstructionFileLoader($this->fixture->root());
        $out = $loader->loadForPath(realpath($this->fixture->root()) . '/src/x.php');

        self::assertSame(InstructionFileLoader::pointer($nested, (int) filesize($nested)), $out);
        self::assertArrayHasKey($nested, $loader->refusedPaths());
    }

    public function testAnOversizedEnabledSkillBodyIsDeferredToAPointer(): void
    {
        $config = CompactorConfig::new();
        $body = str_repeat('Step of the oversized skill. ', intdiv(($config->skillBudgetPerSkill + 500) * 4, 29) + 1);
        $this->fixture->addSkill(Skill::parse("---\ndescription: huge\n---\nSKILLCANARY {$body}", 'huge', '/skills/huge/SKILL.md'));
        $this->fixture->addSkill(Skill::parse("---\ndescription: small\n---\nSMALLCANARY body\n", 'small'));

        $prompt = $this->fixture->systemPrompt();

        self::assertStringNotContainsString('SKILLCANARY', $prompt, 'the over-budget body is not inlined, and not clipped either');
        self::assertStringContainsString('SMALLCANARY', $prompt, 'a skill within budget still renders');
        self::assertMatchesRegularExpression(
            '~## Skill: huge\n\nSkill body deferred: budget \(about [\d,]+ tokens; the skill budget is '
                . preg_quote(number_format($config->skillBudgetPerSkill), '~') . ' per skill and '
                . preg_quote(number_format($config->skillBudgetCombined), '~')
                . ' combined; not in this prompt\)\. Load it with the Skill tool, or Read /skills/huge/SKILL\.md\.~',
            $prompt,
        );
    }

    public function testEnabledSkillsOverTheCombinedBudgetDeferTheOneThatOverruns(): void
    {
        $config = CompactorConfig::new();
        // Each body sits at 90% of the per-skill budget, so it fits alone; the
        // combined budget then admits only as many as fit, in enabled order.
        $each = intdiv($config->skillBudgetPerSkill * 9, 10) * 4;
        $fit = intdiv($config->skillBudgetCombined, TokenEstimate::ofText(str_repeat('x', $each)) + 10);
        for ($i = 0; $i <= $fit; ++$i) {
            $this->fixture->addSkill(Skill::parse("---\ndescription: s{$i}\n---\nCANARY{$i}X " . str_repeat('x', $each), "s{$i}"));
        }

        $prompt = $this->fixture->systemPrompt();

        for ($i = 0; $i < $fit; ++$i) {
            self::assertStringContainsString("CANARY{$i}X", $prompt, "skill {$i} fits the combined budget");
        }
        self::assertStringNotContainsString("CANARY{$fit}X", $prompt, 'the skill that overruns the combined budget is deferred');
        self::assertStringContainsString("## Skill: s{$fit}\n\nSkill body deferred: budget", $prompt);
        self::assertStringContainsString('combined; not in this prompt). Load it with the Skill tool.', $prompt, 'no Read clause for a skill with no file');
    }

    /**
     * Audit R1: the App carried no compactor config, so the skill budgets were
     * always {@see CompactorConfig::new()}'s whatever anyone configured. A body
     * that fits the defaults with room to spare is deferred once the App's own
     * config narrows the per-skill budget below it.
     */
    public function testTheAppsCompactorConfigSetsTheSkillBudget(): void
    {
        $this->fixture->addSkill(Skill::parse("---\ndescription: small\n---\nSMALLCANARY " . str_repeat('word ', 200) . "\n", 'small'));
        $narrow = new CompactorConfig(skillBudgetPerSkill: 50);

        self::assertStringContainsString('SMALLCANARY', $this->fixture->systemPrompt(), 'the defaults admit the body');

        $prompt = $this->fixture->systemPrompt($this->fixture->app()->withCompactorConfig($narrow));

        self::assertStringNotContainsString('SMALLCANARY', $prompt, "the App's own per-skill budget defers it");
        self::assertStringContainsString('the skill budget is 50 per skill and', $prompt);
    }

    /**
     * Audit R1: a deferred skill body existed only as the pointer inside the
     * prompt it was missing from. The Runtime now records the last build's
     * deferrals, and a build in which the skill fits again forgets it.
     */
    public function testTheRuntimeRecordsWhichSkillBodiesTheLastBuildDeferred(): void
    {
        $config = CompactorConfig::new();
        $body = str_repeat('Step of the oversized skill. ', intdiv(($config->skillBudgetPerSkill + 500) * 4, 29) + 1);
        $this->fixture->addSkill(Skill::parse("---\ndescription: huge\n---\n{$body}", 'huge'));
        $app = $this->fixture->app();
        $runtime = new Runtime($app->provider, new \SugarCraft\Crush\Hooks\HookManager(new \SugarCraft\Crush\Hooks\HookRegistry()));

        $this->fixture->systemPrompt($app, $runtime);

        self::assertSame(['huge'], array_keys($runtime->skillDeferrals()));
        self::assertMatchesRegularExpression(
            '/^about [\d,]+ tokens, over the 5,000-token per-skill budget$/',
            $runtime->skillDeferrals()['huge'],
        );

        $this->fixture->systemPrompt($app->withCompactorConfig(new CompactorConfig(skillBudgetPerSkill: 1_000_000, skillBudgetCombined: 1_000_000)), $runtime);

        self::assertSame([], $runtime->skillDeferrals(), 'a build in which the body fits leaves nothing recorded');
    }

    /**
     * Audit R1: {@see Runtime::planInstructionDocuments()} is the splice's own
     * verdict, public so the launch can report it. Run on a bare loader it
     * records the same combined-budget deferral a prompt build records.
     */
    public function testTheInstructionPlanRecordsTheSameDeferralsAPromptBuildDoes(): void
    {
        $this->fixture->write('CLAUDE.md', self::document('FIRSTCANARY', 50_000));
        $this->fixture->write('AGENTS.md', self::document('SECONDCANARY', 50_000));
        $this->fixture->write('docs/forced.md', self::document('THIRDCANARY', 50_000));
        $forced = $this->fixture->root() . '/docs/forced.md';

        $loader = new InstructionFileLoader($this->fixture->root(), ['docs/forced.md']);
        $plan = Runtime::planInstructionDocuments($loader);

        self::assertCount(2, $plan['inline']);
        self::assertSame([InstructionFileLoader::pointer($forced, (int) filesize($forced))], $plan['pointers']);
        self::assertSame(0, $plan['overflow']);
        self::assertStringContainsString('combined instruction budget', $loader->refusedPaths()[$forced] ?? '');
    }
}
