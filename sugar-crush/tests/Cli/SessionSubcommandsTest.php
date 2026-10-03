<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Cli\ArgvParser;
use SugarCraft\Crush\Cli\NonInteractive;
use SugarCraft\Crush\Cli\ParsedArgs;
use SugarCraft\Crush\Cli\Subcommands;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Session\EnhancedSessionStore;
use SugarCraft\Crush\Session\SessionKind;
use SugarCraft\Crush\Session\SessionResolver;
use SugarCraft\Crush\Session\TitleSource;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;

/**
 * P-A3 — `sugarcrush session list|show|rename|delete|pin|unpin|archive|
 * unarchive` and the subcommand-scoped flags that make `session list
 * --archived` parse at all (Appendix P §3.4).
 *
 * In-process, the way SubcommandsMcpImportTest drives its verb: ArgvParser →
 * Subcommands::dispatch with `HOME` pointed at a private directory, so
 * Bootstrap::sessionStore() opens the fixture database the test seeded.
 * stdout is ob-captured; stderr is not capturable in-process, so every
 * failure arm is asserted through the `--output-format json` document.
 */
final class SessionSubcommandsTest extends TestCase
{
    use HomeSandboxTrait;

    private string $home;
    private string|false $originalRetention;
    private EnhancedSessionStore $store;
    private string $kid;

    protected function setUp(): void
    {
        $this->home = (string) realpath(sys_get_temp_dir()) . '/pa3-' . uniqid((string) getmypid(), true);
        self::assertTrue(mkdir($this->home . '/.sugar-crush', 0700, true));
        chmod($this->home, 0700);
        $this->originalRetention = getenv('SUGARCRUSH_SESSION_RETENTION_DAYS');
        $this->useHomeSandbox($this->home);
        putenv('SUGARCRUSH_SESSION_RETENTION_DAYS');

        $this->store = new EnhancedSessionStore($this->home . '/.sugar-crush/session.db');
        $this->store->createSession('sess-main-0001', 'openai', 'gpt-4o', null, 'login work');
        $this->store->createSession('sess-arch-0002', 'openai', 'gpt-4o', null, 'old spike');
        $this->store->archive('sess-arch-0002');
        $this->kid = $this->store->createChildSession('sess-main-0001', SessionKind::Subagent, 'explore', 'call-1', 'openai', 'gpt-4o');
        $this->store->recordTurn('sess-main-0001', 'make the login controller use the guard');
    }

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();
        putenv($this->originalRetention === false
            ? 'SUGARCRUSH_SESSION_RETENTION_DAYS'
            : 'SUGARCRUSH_SESSION_RETENTION_DAYS=' . $this->originalRetention);
        $this->removeTree($this->home);
    }

    // ── the parser: scoped flags ─────────────────────────────────────────

    public function testAVerbsOwnFlagsParseAfterItAndNowhereElse(): void
    {
        $args = ArgvParser::parse(['sugarcrush', 'session', 'list', '--archived', '--limit', '5', '--children']);
        self::assertSame([], $args->unknownFlags);
        self::assertNull($args->usageError);
        self::assertSame(['list'], $args->subcommandArgs);
        self::assertSame(['--archived' => true, '--limit' => '5', '--children' => true], $args->subcommandFlags);

        $inline = ArgvParser::parse(['sugarcrush', 'session', 'list', '--limit=7']);
        self::assertSame(['--limit' => '7'], $inline->subcommandFlags);

        // Before the verb, or after a verb that does not own it, it is unknown.
        self::assertSame(['--all'], ArgvParser::parse(['sugarcrush', '--all', 'session', 'list'])->unknownFlags);
        self::assertSame(['--all'], ArgvParser::parse(['sugarcrush', 'models', '--all'])->unknownFlags);
        self::assertSame(['--all'], ArgvParser::parse(['sugarcrush', '--all'])->unknownFlags);

        // A global flag after the verb keeps its global meaning.
        self::assertSame('json', ArgvParser::parse(['sugarcrush', 'session', 'list', '--output-format', 'json'])->outputFormat);

        // `--` still makes a dash-led token an operand, not a flag.
        $sep = ArgvParser::parse(['sugarcrush', 'session', 'delete', '--', '--all']);
        self::assertSame(['delete', '--all'], $sep->subcommandArgs);
        self::assertSame([], $sep->subcommandFlags);
    }

    public function testAValueFlagWithoutAValueAndASwitchWithOneAreUsageErrors(): void
    {
        self::assertSame(
            'sugarcrush: session --limit expects a value, but the argument list ended',
            ArgvParser::parse(['sugarcrush', 'session', 'list', '--limit'])->usageError,
        );
        self::assertSame(
            'sugarcrush: session --limit expects a value, but the next argument is the option --all',
            ArgvParser::parse(['sugarcrush', 'session', 'list', '--limit', '--all'])->usageError,
        );
        self::assertSame(
            'sugarcrush: session --limit expects a value, but the value is empty',
            ArgvParser::parse(['sugarcrush', 'session', 'list', '--limit='])->usageError,
        );
        self::assertSame(
            'sugarcrush: session --all takes no value',
            ArgvParser::parse(['sugarcrush', 'session', 'list', '--all=yes'])->usageError,
        );
    }

    public function testEveryScopedFlagBelongsToAVerbTheParserAccepts(): void
    {
        foreach (array_keys(ParsedArgs::SUBCOMMAND_FLAGS) as $verb) {
            self::assertContains($verb, ParsedArgs::SUBCOMMANDS);
        }
    }

    // ── list ─────────────────────────────────────────────────────────────

    public function testListShowsTheUsersOwnLiveSessionsByDefault(): void
    {
        [$rc, $out] = $this->dispatch(['session', 'list']);

        self::assertSame(0, $rc, $out);
        self::assertStringContainsString('sess-main-0001', $out);
        self::assertStringContainsString('login work', $out);
        self::assertMatchesRegularExpression('/sess-main-0001\s+\S+ \S+\s+main\s+1t\s+openai\/gpt-4o\s+login work/', $out);
        self::assertStringNotContainsString('sess-arch-0002', $out, 'archived rows are hidden by default');
        self::assertStringNotContainsString($this->kid, $out, 'sub-agent rows are hidden by default');
    }

    public function testListFlagsWidenTheQueryAndMarkPinnedAndArchivedRows(): void
    {
        $this->store->setPinned('sess-main-0001', true);

        [, $archived] = $this->dispatch(['session', 'list', '--archived']);
        self::assertStringContainsString('old spike [archived]', $archived);
        self::assertStringNotContainsString($this->kid, $archived);
        self::assertMatchesRegularExpression('/^★ sess-main-0001/m', $archived);

        [, $children] = $this->dispatch(['session', 'list', '--children']);
        self::assertMatchesRegularExpression('/' . $this->kid . '\s+\S+ \S+\s+subagent/', $children);
        self::assertStringNotContainsString('sess-arch-0002', $children);

        [, $all] = $this->dispatch(['session', 'list', '--all']);
        foreach (['sess-main-0001', 'sess-arch-0002', $this->kid] as $id) {
            self::assertStringContainsString($id, $all);
        }

        [, $one] = $this->dispatch(['session', 'list', '--all', '--limit', '1']);
        self::assertSame(1, substr_count(trim($one), "\n") + 1, $one);
        self::assertStringStartsWith('★ sess-main-0001', $one, 'pinned rows list first');
    }

    public function testListJsonCarriesTheNewColumns(): void
    {
        [$rc, $out] = $this->dispatch(['session', 'list', '--all', '--output-format', 'json']);
        self::assertSame(0, $rc);
        $rows = json_decode($out, true)['result']['sessions'];
        $byId = array_column($rows, null, 'id');

        self::assertSame('main', $byId['sess-main-0001']['kind']);
        self::assertSame(1, $byId['sess-main-0001']['turns']);
        self::assertSame('gpt-4o', $byId['sess-main-0001']['model']);
        self::assertFalse($byId['sess-main-0001']['pinned']);
        self::assertNull($byId['sess-main-0001']['archived_at']);
        self::assertNotNull($byId['sess-arch-0002']['archived_at']);
        self::assertSame('subagent', $byId[$this->kid]['kind']);
        self::assertSame('sess-main-0001', $byId[$this->kid]['parent_id']);
    }

    public function testABadLimitAndAFlagForAnotherActionAreUsageErrors(): void
    {
        self::assertSame(
            'sugarcrush: session list --limit 0: not a positive whole number',
            $this->usageDoor(['session', 'list', '--limit', '0']),
        );
        self::assertSame(
            'sugarcrush: session show: --all does not apply to this action',
            $this->usageDoor(['session', 'show', 'sess-main-0001', '--all']),
        );
        self::assertSame(
            'sugarcrush: session list: --with-children does not apply to this action',
            $this->usageDoor(['session', 'list', '--with-children']),
        );
        self::assertSame('sugarcrush: session list extra: unexpected operand', $this->usageDoor(['session', 'list', 'extra']));
        self::assertSame('sugarcrush: session frob: unknown action', $this->usageDoor(['session', 'frob']));
    }

    // ── target resolution ────────────────────────────────────────────────

    public function testTargetsResolveByIdNamePrefixAcrossKindsAndArchivedRows(): void
    {
        self::assertSame('sess-main-0001', SessionResolver::find($this->store, 'sess-main-0001')?->id);
        self::assertSame('sess-main-0001', SessionResolver::find($this->store, 'login work')?->id);
        self::assertSame('sess-arch-0002', SessionResolver::find($this->store, 'sess-a')?->id, 'an archived row resolves by prefix');
        self::assertSame($this->kid, SessionResolver::find($this->store, substr($this->kid, 0, 8))?->id, 'a sub-agent row resolves by prefix');
        self::assertNull(SessionResolver::find($this->store, 'sess-'), 'an ambiguous prefix names nothing');
        self::assertCount(2, SessionResolver::matches($this->store, 'sess-'));
        self::assertSame([], SessionResolver::matches($this->store, ''));
    }

    public function testAnAmbiguousPrefixIsExitTwoAndListsTheCandidates(): void
    {
        [$rc, $out] = $this->dispatch(['session', 'pin', 'sess-', '--output-format', 'json']);

        self::assertSame(NonInteractive::EXIT_CONFIG, $rc);
        $error = json_decode($out, true)['error'];
        self::assertSame('usage', $error['type']);
        self::assertSame('sugarcrush: session pin sess-: ambiguous id prefix, 2 sessions match', $error['message']);
        self::assertFalse($this->store->listSessionsFiltered(
            \SugarCraft\Crush\Session\SessionQuery::new()->withPinnedFirst(),
        )[0]->pinned, 'nothing was pinned');
    }

    public function testAnUnknownTargetIsExitOneAndAMissingOneIsExitTwo(): void
    {
        [$rc, $out] = $this->dispatch(['session', 'show', 'zzzz', '--output-format', 'json']);
        self::assertSame(NonInteractive::EXIT_FAILURE, $rc);
        self::assertSame('not-found', json_decode($out, true)['error']['type']);

        self::assertSame('sugarcrush: session show: no session id given', $this->usageDoor(['session', 'show']));
        self::assertSame('sugarcrush: session rename: no title given', $this->usageDoor(['session', 'rename', 'sess-main-0001']));
    }

    // ── show / rename / pin / archive / delete ───────────────────────────

    public function testShowPrintsTheRowAndTheTranscript(): void
    {
        $this->store->saveTranscript('sess-main-0001', [
            Message::user('make the login controller use the guard'),
            Message::assistant('Done: LoginController now uses the session guard.'),
        ]);

        [$rc, $out] = $this->dispatch(['session', 'show', 'login work']);
        self::assertSame(0, $rc, $out);
        self::assertStringStartsWith("# login work\n", $out);
        self::assertStringContainsString('- id: sess-main-0001', $out);
        self::assertStringContainsString('- model: openai/gpt-4o', $out);
        self::assertStringContainsString('LoginController now uses the session guard', $out);

        [$rc, $json] = $this->dispatch(['session', 'show', 'sess-m', '--output-format', 'json']);
        self::assertSame(0, $rc);
        $result = json_decode($json, true)['result'];
        self::assertSame('sess-main-0001', $result['session']['id']);
        self::assertCount(2, $result['messages']);
    }

    public function testRenameRecordsAUserTitleFromEveryWordAfterTheTarget(): void
    {
        [$rc, $out] = $this->dispatch(['session', 'rename', 'sess-m', 'Auth', 'refactor']);

        self::assertSame(0, $rc, $out);
        $row = $this->store->getSession('sess-main-0001');
        self::assertSame('Auth refactor', $row['name']);
        self::assertSame(TitleSource::User->value, $row['title_source']);
    }

    public function testPinArchiveAndTheirInversesAreIdempotent(): void
    {
        [$rc, $out] = $this->dispatch(['session', 'pin', 'sess-m']);
        self::assertSame(0, $rc);
        self::assertSame("Pinned session sess-main-0001\n", $out);
        self::assertSame(1, (int) $this->store->getSession('sess-main-0001')['pinned']);

        [, $again] = $this->dispatch(['session', 'pin', 'sess-m']);
        self::assertSame("Session sess-main-0001 is already pinned\n", $again);

        [, $unpin] = $this->dispatch(['session', 'unpin', 'sess-m', '--output-format', 'json']);
        self::assertSame(['session' => 'sess-main-0001', 'action' => 'unpin', 'changed' => true], json_decode($unpin, true)['result']);

        [, $unarchive] = $this->dispatch(['session', 'unarchive', 'sess-a']);
        self::assertSame("Unarchived session sess-arch-0002\n", $unarchive);
        self::assertNull($this->store->getSession('sess-arch-0002')['archived_at']);

        [, $archive] = $this->dispatch(['session', 'archive', 'old spike']);
        self::assertSame("Archived session sess-arch-0002\n", $archive);
        self::assertNotNull($this->store->getSession('sess-arch-0002')['archived_at']);
    }

    public function testDeleteTakesSubAgentChildrenAndWithChildrenTakesBranchesToo(): void
    {
        $branch = $this->store->forkSession('sess-main-0001');

        [$rc, $out] = $this->dispatch(['session', 'delete', 'sess-m', '--output-format', 'json']);
        self::assertSame(0, $rc, $out);
        $result = json_decode($out, true)['result'];
        self::assertSame('sess-main-0001', $result['deleted']);
        self::assertSame(['sess-main-0001', $this->kid], $result['deletedIds']);
        self::assertNotNull($this->store->getSession($branch), 'a branch is detached and kept by default');

        $kid = $this->store->createChildSession($branch, SessionKind::Background, 'bg', null, 'openai', 'gpt-4o');
        [$rc, $text] = $this->dispatch(['session', 'delete', $branch, '--with-children']);
        self::assertSame(0, $rc, $text);
        self::assertSame('Deleted session ' . $branch . " and 1 child session(s)\n", $text);
        self::assertNull($this->store->getSession($kid));
    }

    /**
     * @param list<string> $args
     *
     * @return array{0:int,1:string}
     */
    private function dispatch(array $args): array
    {
        ob_start();
        $rc = Subcommands::dispatch(ArgvParser::parse(['sugarcrush', ...$args]));

        return [$rc, (string) ob_get_clean()];
    }

    /**
     * @param list<string> $args
     */
    private function usageDoor(array $args): string
    {
        [$rc, $stdout] = $this->dispatch([...$args, '--output-format', 'json']);
        self::assertSame(NonInteractive::EXIT_CONFIG, $rc, $stdout);
        $decoded = json_decode(trim($stdout), true);
        self::assertIsArray($decoded, "stdout was not a JSON envelope: {$stdout}");
        self::assertSame('usage', $decoded['error']['type'] ?? null, $stdout);

        return (string) ($decoded['error']['message'] ?? '');
    }

    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeTree($path . '/' . $entry);
            }
        }
        @rmdir($path);
    }
}
