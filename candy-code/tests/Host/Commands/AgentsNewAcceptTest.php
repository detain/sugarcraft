<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Host\Commands;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Hooks\BuiltIn\ProtectFilesHook;
use SugarCraft\Code\Host\Commands\AgentsHostCommand;
use SugarCraft\Code\Host\Commands\CommandContext;
use SugarCraft\Code\Host\Commands\CommandEffectKind;
use SugarCraft\Code\Host\Commands\CommandResult;
use SugarCraft\Code\Host\Commands\NewAgentPrompt;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Role;
use SugarCraft\Code\Support\FrontmatterKeyAudit;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;

/**
 * `/agents new` drafts and `/agents accept` promotes (C-EXT-3.d).
 *
 * Every pin here guards the same law from one side: THE AGENT WRITES ONLY THE
 * DRAFT TIER, AND NOTHING MOVES INTO THE LIVE TIER EXCEPT A HUMAN-TYPED
 * `/agents accept` THAT FIRST PRINTED THE POLICY KEYS VERBATIM. The accept
 * pins check the print, the move, and every refusal that keeps an unreviewed
 * file out of `.candy-code/agents/`; the prompt pins check that the draft
 * request names the draft directory, the key list, and the names already in
 * play; and the ProtectFilesHook pin proves the deny on the live directory
 * this design stands on is still in force.
 */
final class AgentsNewAcceptTest extends TestCase
{
    use HomeSandboxTrait;

    private string $sandbox = '';
    private string $home = '';
    private string $project = '';

    protected function setUp(): void
    {
        $this->sandbox = sys_get_temp_dir() . '/crush-agents-new-' . bin2hex(random_bytes(6));
        $this->home = $this->useHomeSandbox($this->sandbox . '/home');
        $this->project = $this->sandbox . '/project';
        mkdir($this->project, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();
        if ($this->sandbox !== '' && is_dir($this->sandbox)) {
            exec('rm -rf ' . escapeshellarg($this->sandbox) . ' 2>&1');
        }
    }

    // ---------------------------------------------------------------- prompt

    /** The draft request teaches the tier, the key list, and the consent step. */
    public function testTheDraftPromptCarriesTheLawsTheAgentMustFollow(): void
    {
        $prompt = NewAgentPrompt::prompt();

        self::assertStringContainsString(NewAgentPrompt::DIRECTORY . '/', $prompt, 'the prompt names the draft directory');
        self::assertStringContainsString('/agents accept', $prompt, 'the prompt names the human step that promotes it');
        self::assertStringContainsString('bypass-permissions', $prompt, 'the self-grant modes are called out by name');
        self::assertStringContainsString('DRAFT', $prompt, 'the prompt says plainly the file is inert until promoted');
        self::assertStringNotContainsString('@', $prompt, 'no mention scanner hook in a canned prompt');
        self::assertStringNotContainsString('cover:', $prompt, 'a bare /agents new carries no focus paragraph');
        self::assertStringContainsString('no preset files in', $prompt, 'with nothing on disk the honest empty sentence stands');

        foreach (FrontmatterKeyAudit::KNOWN[FrontmatterKeyAudit::AGENT] as $key) {
            self::assertStringContainsString($key, $prompt, "the key list names `{$key}`");
        }
    }

    /** `<focus>` rides the prompt, and every taken name — live or draft — is listed. */
    public function testThePromptAppendsTheFocusAndTheNamesAlreadyInPlay(): void
    {
        $this->writeFinal('worker', "---\nname: worker\ndescription: Builds.\n---\nBody.\n");
        $this->writeDraft('debugger', "---\nname: debugger\ndescription: Debugs.\n---\nBody.\n");

        $prompt = NewAgentPrompt::prompt('review the migrations', NewAgentPrompt::existingPresets($this->project));

        self::assertStringContainsString('The user asked this preset to cover: review the migrations', $prompt);
        self::assertStringContainsString('worker.md', $prompt, 'a live preset name is taken');
        self::assertStringContainsString('debugger.md', $prompt, 'a draft name is taken too');
    }

    // ------------------------------------------------------------ serve new

    /** On serve, `new` answers with the dispatched sentence and a SubmitPrompt effect. */
    public function testServedNewDispatchesThePromptAsAnEffect(): void
    {
        $result = $this->runAgents('/agents new reviewer for migrations');

        self::assertFalse($result->isRefused());
        self::assertSame(Role::Assistant, $result->rows[\count($result->rows) - 1]->role);
        self::assertStringContainsString(
            Lang::t('host.agents.new.dispatched'),
            $result->rows[\count($result->rows) - 1]->content,
        );

        self::assertCount(1, $result->effects);
        self::assertSame(CommandEffectKind::SubmitPrompt, $result->effects[0]->kind);
        self::assertStringContainsString('reviewer for migrations', $result->effects[0]->promptText(), 'the focus rides the dispatched prompt');
        self::assertStringContainsString(NewAgentPrompt::DIRECTORY . '/', $result->effects[0]->promptText());
    }

    // --------------------------------------------------------------- accept

    /** The gate: validate, print the three policy keys verbatim, move. */
    public function testAcceptPrintsThePolicyVerbatimThenMovesTheDraft(): void
    {
        $draft = $this->writeDraft('reviewer', <<<'MD'
            ---
            name: reviewer
            description: Reviews diffs.
            permissionMode: accept-edits
            tools: [Read, Grep]
            mcpServers: db
            ---
            Review every diff for security first.
            MD);

        $body = $this->acceptBody('/agents accept reviewer');

        self::assertStringContainsString('permissionMode: accept-edits', $body, 'the mode is printed verbatim');
        self::assertStringContainsString('tools: Read, Grep', $body, 'the tool list is printed');
        self::assertStringContainsString('mcpServers: db', $body, 'the MCP list is printed');
        self::assertStringContainsString('`accept-edits` is a self-grant', $body, 'accept-edits is wider than default, so the loud row rides too');

        self::assertFileExists($this->project . '/.candy-code/agents/reviewer.md', 'the file landed in the live tier');
        self::assertFileDoesNotExist($draft, 'the draft moved; it is not left behind');
        self::assertStringContainsString(Lang::t('host.agents.accept.moved', ['name' => 'reviewer', 'path' => NewAgentPrompt::TARGET . '/reviewer.md']), $body);
    }

    /** A self-granting mode is still accepted — the accept IS the consent — but loudly. */
    public function testASelfGrantingModeIsShownLoudlyAndStillMoves(): void
    {
        $this->writeDraft('go-anywhere', "---\nname: go-anywhere\ndescription: Loud grant.\npermissionMode: bypass-permissions\n---\nBody.\n");

        $body = $this->acceptBody('/agents accept go-anywhere');

        self::assertStringContainsString('`bypass-permissions` is a self-grant', $body);
        self::assertStringContainsString('your consent', $body, 'the loud row names what the step means');
        self::assertFileExists($this->project . '/.candy-code/agents/go-anywhere.md', 'consent moves it anyway');
    }

    /** The two safe modes earn no warning row. */
    public function testAPlainOrPlanModeCarriesNoSelfGrantWarning(): void
    {
        $this->writeDraft('plain', "---\nname: plain\ndescription: Quiet.\npermissionMode: default\n---\nBody.\n");
        $this->writeDraft('planner', "---\nname: planner\ndescription: Reads only.\npermissionMode: plan\n---\nBody.\n");

        foreach (['plain', 'planner'] as $name) {
            $body = $this->acceptBody('/agents accept ' . $name);
            self::assertStringNotContainsString('self-grant', $body, "`{$name}` is not a wider surface");
            self::assertStringContainsString('permissionMode: ' . ($name === 'plain' ? 'default' : 'plan'), $body);
        }
    }

    /** An existing live preset of the same name is never overwritten; the draft stands. */
    public function testAcceptRefusesANameAlreadyTakenAndLeavesBothFiles(): void
    {
        $final = $this->writeFinal('worker', "---\nname: worker\ndescription: The original.\n---\nOriginal body.\n");
        $draft = $this->writeDraft('worker', "---\nname: worker\ndescription: The impostor.\npermissionMode: bypass-permissions\n---\nNew body.\n");

        $body = $this->runText('/agents accept worker');
        self::assertStringContainsString('already exists', $body);
        self::assertStringNotContainsString('self-grant', $body, 'a refused move prints no consent row');

        self::assertSame('The original.', trim((string) preg_replace('/\r/', '', $this->metaLine((string) file_get_contents($final), 'description'))));
        self::assertFileExists($draft, 'the refused draft waits where it was');
    }

    /** A typo'd key refuses the move — with the did-you-mean the audit offers. */
    public function testAcceptRefusesAnUnknownKeyAndSuggestsTheKnownOne(): void
    {
        $this->writeDraft('typo', "---\nname: typo\ndescription: Mis-spelled grant.\npermisionMode: bypass-permissions\n---\nBody.\n");

        $body = $this->runText('/agents accept typo');

        self::assertStringContainsString('`permisionMode`', $body);
        self::assertStringContainsString('did you mean `permissionMode`?', $body);
        self::assertFileDoesNotExist($this->project . '/.candy-code/agents/typo.md', 'nothing moves on a refused draft');
    }

    /** An unknown MODE refuses whole — the parser that skips bad files at load is not the gate. */
    public function testAcceptRefusesAModeThatDoesNotExist(): void
    {
        $this->writeDraft('wild', "---\nname: wild\ndescription: No such mode.\npermissionMode: yolo\n---\nBody.\n");

        $body = $this->runText('/agents accept wild');

        self::assertStringContainsString('yolo', $body);
        self::assertStringContainsString('bypass-permissions', $body, 'the refusal lists the modes that DO exist');
        self::assertFileDoesNotExist($this->project . '/.candy-code/agents/wild.md');
    }

    /** No frontmatter block, no promotion. */
    public function testAcceptRefusesADraftWithoutFrontmatter(): void
    {
        $this->writeDraft('bare', "Just prose.\n");

        self::assertStringContainsString('no frontmatter', $this->runText('/agents accept bare'));
        self::assertFileDoesNotExist($this->project . '/.candy-code/agents/bare.md');
    }

    /** Usage, missing drafts and unholy names are answers, not moves. */
    public function testAcceptRefusesEveryMalformedName(): void
    {
        $usage = $this->runText('/agents accept');
        self::assertStringContainsString('Usage: /agents', $usage);

        $absent = $this->runText('/agents accept nowhere');
        self::assertStringContainsString('No draft named nowhere', $absent);

        foreach (['../escape', 'Escaped'] as $form) {
            $rejected = $this->runText('/agents accept ' . $form);
            self::assertStringContainsString('is not a draft name', $rejected, "`{$form}` never reaches the filesystem");
        }

        self::assertStringContainsString('Usage: /agents', $this->runText('/agents accept two words x'), 'a multi-word accept is malformed before it is a name');

        self::assertFileDoesNotExist($this->sandbox . '/escape.md');
        self::assertDirectoryDoesNotExist($this->project . '/.candy-code/agents');
    }

    /** A symlinked draft is refused: the consent print could be spent on a target that later changes. */
    public function testAcceptRefusesASymlinkedDraftAndLeavesTheTargetAlone(): void
    {
        $victim = $this->sandbox . '/victim.md';
        file_put_contents($victim, "---\nname: ghost\ndescription: The victim.\n---\nVictim body.\n");
        $dir = $this->project . '/' . NewAgentPrompt::DIRECTORY;
        mkdir($dir, 0700, true);
        $link = $dir . '/ghost.md';
        self::assertTrue(symlink($victim, $link));

        $body = $this->runText('/agents accept ghost');

        self::assertStringContainsString('is not a draft name', $body, 'a link never reaches the validation-and-move path');
        self::assertStringNotContainsString('self-grant', $body);
        self::assertTrue(is_link($link), 'the refused link waits where it was');
        self::assertFileDoesNotExist($this->project . '/' . NewAgentPrompt::TARGET . '/ghost.md', 'nothing is promoted through a link');
        self::assertSame("---\nname: ghost\ndescription: The victim.\n---\nVictim body.\n", file_get_contents($victim), 'the victim is byte-untouched');
    }

    /**
     * The sneakier swap: the DRAFT DIRECTORY ITSELF is a symlink out of the
     * project, so every name check and every per-file `is_link` passes while
     * the bytes — and the rename — go through the grafted tree. The containment
     * compare is the door; nothing outside the project may be promoted.
     */
    public function testAcceptRefusesEveryDraftBehindASwappedDirectoryLink(): void
    {
        $outside = $this->sandbox . '/outside';
        mkdir($outside, 0700, true);
        $victim = $outside . '/reviewer.md';
        file_put_contents($victim, "---\nname: reviewer\ndescription: Grafted.\npermissionMode: bypass-permissions\n---\nVictim body.\n");
        mkdir($this->project . '/.candy-code', 0700, true);
        self::assertTrue(symlink($outside, $this->project . '/' . NewAgentPrompt::DIRECTORY));

        $body = $this->runText('/agents accept reviewer');

        self::assertStringContainsString('No draft named reviewer', $body, 'a file behind the graft is not a draft of this project');
        self::assertStringNotContainsString('self-grant', $body, 'the consent print is never even reached');
        self::assertFileDoesNotExist($this->project . '/' . NewAgentPrompt::TARGET . '/reviewer.md', 'nothing is promoted through a grafted directory');
        self::assertFileExists($victim, 'the victim file under the graft stands byte-where-it-was');
        self::assertSame("---\nname: reviewer\ndescription: Grafted.\npermissionMode: bypass-permissions\n---\nVictim body.\n", file_get_contents($victim), 'the grafted body is byte-untouched');
    }

    /** The listing holds the same door: drafts behind a grafted directory are not this project's. */
    public function testProposedSkipsDraftsBehindASwappedDirectoryLink(): void
    {
        $outside = $this->sandbox . '/outside';
        mkdir($outside, 0700, true);
        file_put_contents($outside . '/grafted.md', "---\nname: grafted\ndescription: Not listed.\n---\nBody.\n");
        mkdir($this->project . '/.candy-code', 0700, true);
        self::assertTrue(symlink($outside, $this->project . '/' . NewAgentPrompt::DIRECTORY));

        self::assertStringContainsString('No drafts waiting', $this->runText('/agents proposed'));
    }

    /** A read-only window does not promote. */
    public function testAReadOnlyWindowRefusesThePromotion(): void
    {
        $draft = $this->writeDraft('reviewer', "---\nname: reviewer\ndescription: Waits.\n---\nBody.\n");

        $result = (new AgentsHostCommand())->run(
            CommandContext::new(root: $this->project, readOnly: true),
            '/agents accept reviewer',
        );

        self::assertTrue($result->isRefused(), 'promotion is a write and the door holds');
        self::assertFileExists($draft, 'the draft waits for a writable window');
    }

    // ------------------------------------------------------------- proposed

    /** `proposed` answers with the draft tier only, name and description each. */
    public function testProposedListsTheDrafts(): void
    {
        self::assertStringContainsString('No drafts waiting', $this->runText('/agents proposed'));

        $this->writeDraft('reviewer', "---\nname: reviewer\ndescription: Reviews diffs.\n---\nBody.\n");
        $this->writeDraft('tester', "---\nname: tester\ndescription: Tests code.\n---\nBody.\n");
        $this->writeFinal('worker', "---\nname: worker\ndescription: Live already.\n---\nBody.\n");

        $body = $this->runText('/agents proposed');

        self::assertStringContainsString(Lang::t('host.agents.proposed.heading'), $body);
        self::assertStringContainsString('reviewer - Reviews diffs.', $body);
        self::assertStringContainsString('tester - Tests code.', $body);
        self::assertStringNotContainsString('worker', $body, 'the listing is drafts, not the live tier');
    }

    // -------------------------------------------------- roster fall-through

    /** The roster arm is unchanged: without a manager it answers "not configured" … */
    public function testRosterFormsStillAnswerNotConfiguredWithoutAManager(): void
    {
        foreach (['/agents', '/agents list', '/agents somePreset'] as $text) {
            $body = $this->runText($text);
            self::assertStringContainsString(Lang::t('host.agents.not_configured'), $body, "`{$text}` keeps the old door");
        }
    }

    /** … but the draft verbs are file operations and need no manager at all. */
    public function testTheDraftVerbsWorkWithoutAManager(): void
    {
        $this->writeDraft('reviewer', "---\nname: reviewer\ndescription: No manager needed.\n---\nBody.\n");

        self::assertStringContainsString('reviewer', $this->runText('/agents proposed'));
        self::assertStringContainsString('is now', $this->runText('/agents accept reviewer'));
        self::assertStringContainsString(Lang::t('host.agents.new.dispatched'), $this->runText('/agents new reviewer'));
    }

    // ------------------------------------------------------------- the floor

    /**
     * The whole design stands on one fact: the LIVE directory is denied to the
     * agent's own writes, and the DRAFT directory is not. Pin both halves,
     * and pin the deny pattern itself so a loosening of
     * `ProtectFilesHook::WRITE_ONLY_PATTERNS` reddens here even if no other
     * guard notices (a preset's permissionMode is a self-grant).
     */
    public function testTheLiveDirectoryStaysDeniedToAgentWritesAndTheDraftTierDoesNot(): void
    {
        self::assertContains('#(^|/)\.candy-code/agents/#', ProtectFilesHook::WRITE_ONLY_PATTERNS, 'the deny-class row this design stands on must not be loosened');

        $live = '/w/repo/.candy-code/agents/reviewer.md';
        $draftFile = '/w/repo/.candy-code/agents-proposed/reviewer.md';

        $deniesLive = false;
        $deniesDraft = false;
        foreach (ProtectFilesHook::WRITE_ONLY_PATTERNS as $pattern) {
            $deniesLive = $deniesLive || preg_match($pattern, $live) === 1;
            $deniesDraft = $deniesDraft || preg_match($pattern, $draftFile) === 1;
        }

        self::assertTrue($deniesLive, 'a Write into the live tier stays denied');
        self::assertFalse($deniesDraft, 'the draft tier is an ordinary gated write — the design dies if it becomes deny-class');
    }

    // -------------------------------------------------------------- helpers

    /** Runs the host command with no manager attached (the file-op surface). */
    private function runAgents(string $text): CommandResult
    {
        return (new AgentsHostCommand())->run(CommandContext::new(root: $this->project), $text);
    }

    /** The transcript body of a run whose rows answer with text. */
    private function runText(string $text): string
    {
        $result = $this->runAgents($text);
        $last = $result->rows[\count($result->rows) - 1];

        return $last->content;
    }

    /** Runs a form that must end in the assistant reply of an accepted move. */
    private function acceptBody(string $text): string
    {
        $result = $this->runAgents($text);
        self::assertFalse($result->isRefused(), $text);
        $last = $result->rows[\count($result->rows) - 1];
        self::assertSame(Role::Assistant, $last->role, $last->content);

        return $last->content;
    }

    /** Writes a draft under the project draft tier and returns its path. */
    private function writeDraft(string $name, string $body): string
    {
        return $this->writeUnder(NewAgentPrompt::DIRECTORY, $name, $body);
    }

    /** Writes a live preset under the project tier and returns its path. */
    private function writeFinal(string $name, string $body): string
    {
        return $this->writeUnder(NewAgentPrompt::TARGET, $name, $body);
    }

    private function writeUnder(string $directory, string $name, string $body): string
    {
        $dir = $this->project . '/' . $directory;
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        $file = $dir . '/' . $name . '.md';
        file_put_contents($file, $body);

        return $file;
    }

    /** The frontmatter line `{key}:` carries, or null. */
    private function metaLine(string $content, string $key): ?string
    {
        return isset(FrontmatterKeyAudit::metaOf($content)[$key])
            ? (string) FrontmatterKeyAudit::metaOf($content)[$key]
            : null;
    }
}
