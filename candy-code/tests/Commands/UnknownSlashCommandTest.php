<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Commands;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Code\Backend\EchoBackend;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Commands\CommandLoader;
use SugarCraft\Code\Message;
use SugarCraft\Code\Role;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;

/**
 * ISS-037: a draft that LOOKS like an advertised slash command but is claimed
 * by nothing is refused LOCALLY, with the popup's own anchored matcher as the
 * did-you-mean source — not shipped to the model as an expensive essay topic.
 *
 * The refusals and the prose survivors are pinned TOGETHER, in one file,
 * because the defect class was a boundary nobody wrote down: every prose
 * half of the rule (paths, the `//` escape, digit-leading words, claimed names
 * declining their argument, uppercase spellings) is exactly as load-bearing as
 * the refusal half, and either one can be broken without touching the other.
 *
 * Driven through the real `submit()` path, per this family's standing
 * convention: a refusal is a behavior of the whole chain, and a test that
 * called `Chat::unknownSlashNotice()` directly could stay green while the
 * call site went missing.
 */
final class UnknownSlashCommandTest extends TestCase
{
    use HomeSandboxTrait;

    private string $sandbox = '';

    private string $project = '';

    protected function setUp(): void
    {
        $this->sandbox = sys_get_temp_dir() . '/crush-unknown-' . bin2hex(random_bytes(6));
        $this->project = $this->sandbox . '/project';
        mkdir($this->project . '/.candy-code/commands', 0700, true);
        $this->useHomeSandbox($this->sandbox . '/home');
    }

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();
        if (is_dir($this->sandbox)) {
            exec('rm -rf ' . escapeshellarg($this->sandbox));
        }
    }

    private function chat(string $draft): Chat
    {
        return new Chat(
            history: [Message::user('hello'), Message::assistant('hi')],
            inputBuf: $draft,
            backend: new EchoBackend(),
            projectRoot: $this->project,
            commandLoader: new CommandLoader(),
        );
    }

    private function pressEnter(string $draft): Chat
    {
        [$next] = $this->chat($draft)->update(new KeyMsg(KeyType::Enter));

        return $next;
    }

    /** The messages $draft appended to the two-message fixture history. */
    private function added(Chat $next): array
    {
        return array_slice($next->history, 2);
    }

    // ── the refusal ──────────────────────────────────────────────────────

    public function testAnUnclaimedSlashWordIsRefusedWithoutCostingATurn(): void
    {
        $next = $this->pressEnter('/hepl');

        $this->assertFalse($next->inFlight, 'a typo must not start a model turn');
        $added = $this->added($next);
        $this->assertCount(1, $added, 'the refusal is the only row the submit appends');
        $this->assertSame(Role::System, $added[0]->role, 'the refusal is a notice, not a prompt');
        $this->assertStringContainsString('Nothing is named `/hepl`', $added[0]->content);
        // `hepl` is not a subsequence of `help` in either direction the
        // anchored matcher admits, so an honest refusal says nothing further.
        $this->assertStringNotContainsString('Did you mean', $added[0]->content);
    }

    /**
     * Where the completion popup CANNOT follow — a colon form whose popup
     * query `key:status` no command name contains — the anchored matcher
     * still gets a clean vote on the name before the colon, and the refusal
     * says so. This is the one shape where "did you mean" is not redundant
     * with what Enter would have done; the `/compac` spelling, by contrast,
     * is completion's territory (pinned by SlashMenuTest) and reaches
     * neither the refusal nor this sentence.
     */
    public function testTheColonFormStillGetsADidYouMeanFromTheNameAlone(): void
    {
        $added = $this->added($this->pressEnter('/key:status'));

        $this->assertStringContainsString('Nothing is named', $added[0]->content);
        $this->assertStringContainsString('Did you mean /keys', $added[0]->content);
    }

    public function testTheColonFormOfANamingNothingIsRefusedToo(): void
    {
        $added = $this->added($this->pressEnter('/nosuchthing:x'));

        $this->assertFalse($this->pressEnter('/nosuchthing:x')->inFlight);
        $this->assertStringContainsString('Nothing is named', $added[0]->content);
    }

    /**
     * `new-picker` is the palette twin of `/new` with `slashVisible: false`, so
     * it is not in the row set `slashCommandRows()` advertises and "did you
     * mean" can never recommend a spelling the popup does not list.
     */

    public function testTheDidYouMeanNeverRecommendsAPaletteOnlyRow(): void
    {
        $added = $this->added($this->pressEnter('/new-pick'));

        $this->assertStringContainsString('Nothing is named `/new-pick`', $added[0]->content);
        $this->assertStringNotContainsString('Did you mean', $added[0]->content);
    }

    public function testAWordNamingAProjectSkillSaysSoWithoutInvokingIt(): void
    {
        mkdir($this->project . '/.candy-code/skills/shipit', 0700, true);
        file_put_contents(
            $this->project . '/.candy-code/skills/shipit/SKILL.md',
            "---\ndescription: Ship it\n---\nShip the checked-in work.",
        );

        $added = $this->added($this->pressEnter('/shipit'));

        $this->assertFalse($this->pressEnter('/shipit')->inFlight, 'the namesake is still not a prompt');
        $this->assertStringContainsString('Nothing is named', $added[0]->content);
        $this->assertStringContainsString('`shipit` names a skill', $added[0]->content);
    }

    /**
     * The one non-prose, non-refusal slash shape the issue left alone: a bare
     * "/" is the popup trigger and Enter COMPLETES its highlighted row rather
     * than submitting anything. Pinned here so ISS-037's boundary is closed
     * world: "/" is the popup's, "//" is prose's, "/word" is either a claim
     * or a refusal, and nothing else exists.
     */
    public function testABareSlashBelongsToThePopupNotTheRefusal(): void
    {
        $next = $this->pressEnter('/');

        $this->assertFalse($next->inFlight, 'the popup completion consumed the Enter');
        $this->assertSame([], $this->added($next), 'and nothing was appended');
    }

    public function testTheDraftSurvivesTheRefusal(): void
    {
        $next = $this->pressEnter('/hepl');

        $this->assertSame('/hepl', $next->inputBuf, 'a refused draft is still in the box to fix');
    }

    // ── the prose that must STAY prose ───────────────────────────────────

    /** @return iterable<string, array{0: string}> */
    public static function draftsThatAreNotCommandShaped(): iterable
    {
        // The declared escape: a second slash says "this line is prose".
        yield '//keep this as prose' => ['//keep this as prose'];
        yield '/etc/hosts, the issue\'s own example' => ['/etc/hosts look at /etc/hosts please'];
        // A command name never starts with a digit.
        yield '/2fa' => ['/2fa'];
        // Not a slash draft at all.
        yield 'ordinary prose' => ['please compact this'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('draftsThatAreNotCommandShaped')]
    public function testWordsTheRefusalCannotClaimStillReachTheModel(string $draft): void
    {
        $next = $this->pressEnter($draft);

        $this->assertTrue($next->inFlight, "{$draft} must go to the model as before");
        $added = $this->added($next);
        $this->assertSame($draft, $added[0]->content, 'and verbatim, as the prompt it is');
    }

    public function testAClaimedNameDecliningItsArgumentStaysProse(): void
    {
        // `/exit now` is the pinned case: `exit` is claimed, its `accepts()`
        // gate declines the trailing word, and the draft is prose exactly as
        // it was before ISS-037 drew the boundary.
        $next = $this->pressEnter('/exit now');

        $this->assertTrue($next->inFlight, 'a claimed name must not be refused as unknown');
        $this->assertSame('/exit now', $this->added($next)[0]->content);
    }

    public function testUppercaseSpellingsStayProseBecauseDispatchIsByteExact(): void
    {
        // `SlashDispatchTest::testTheRefactorDidNotMakeCommandsCaseInsensitive`
        // pins that `/HELP` never dispatches. ISS-037 must not quietly undo
        // that by refusing the uppercase class: the claim check case-FOLDS, so
        // every uppercase spelling of a real name keeps its prose route.
        foreach (['/KEYS', '/HELP', '/CoMpAcT now'] as $draft) {
            $next = $this->pressEnter($draft);
            $this->assertTrue($next->inFlight, "{$draft} is prose, exactly as it was");
        }
    }

    public function testAFileCommandOfTheSessionIsNeverRefused(): void
    {
        file_put_contents($this->project . '/.candy-code/commands/review.md', 'Review it.');

        $next = $this->pressEnter('/review');

        $this->assertTrue($next->inFlight, 'the file command runs, ISS-037 not withstanding');
        $this->assertSame('Review it.', $this->added($next)[0]->content);
    }
}
