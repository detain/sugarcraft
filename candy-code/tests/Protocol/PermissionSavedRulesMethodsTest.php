<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Protocol;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Background\BackgroundSupervisor;
use SugarCraft\Code\Permissions\PermissionAction;
use SugarCraft\Code\Permissions\PermissionGate;
use SugarCraft\Code\Permissions\PermissionMode;
use SugarCraft\Code\Permissions\PermissionRule;
use SugarCraft\Code\Protocol\ErrorCode;
use SugarCraft\Code\Tests\Server\Support\ProtocolFixture;
use SugarCraft\Code\Tests\Server\Support\WireClient;

/**
 * C-SEC-6.c: `remember: "user"` over the wire — the web answer that says
 * "always, and write it down". A hosted `always` with that remember saves
 * exactly the TUI `p` set (the same {@see \SugarCraft\Code\Permissions\SessionPermissionMemo::patternsFor()}
 * derivation and the same "only if a rule shape reproduces the grant" gate)
 * through {@see \SugarCraft\Code\Permissions\SavedRules}, and the notice rides
 * back in the result's `saved`. `permission.rules` then tells the saved rows
 * apart with `tier: "user"` and the 1-based `savedIndex` that
 * `permission.forget` — the wire twin of `/permissions forget` — addresses by,
 * and `permission.forget` takes one back out.
 *
 * The refusals that survive: `remember: "project"` (permission rules are
 * user-tier only), a relayed background question (`remember_user_unavailable`
 * — the daemon's approver records no grant to persist), and every not-found /
 * already-resolved edge, each of which must leave the config file untouched —
 * a refused answer that still wrote a rule would be the lie this whole screen
 * exists to prevent.
 */
final class PermissionSavedRulesMethodsTest extends TestCase
{
    private ProtocolFixture $fixture;

    /** @var list<string> */
    private array $extraDirs = [];

    protected function setUp(): void
    {
        $this->fixture = ProtocolFixture::new();
    }

    protected function tearDown(): void
    {
        $this->fixture->tearDown();
        foreach ($this->extraDirs as $dir) {
            ProtocolFixture::removeTree($dir);
        }
        $this->extraDirs = [];
    }

    public function testRememberUserAnswersAlwaysAndSavesThePatterns(): void
    {
        [$a, $sessionId] = $this->turnWithAClient();
        $ask = $this->fixture->backend->ask('c1', 'Bash', ['command' => 'git status']);
        $this->fixture->run(0.08);

        $answer = $a->call('permission.respond', [
            'sessionId' => $sessionId,
            'askId' => $ask->askId,
            'reply' => 'once',
            'remember' => 'user',
        ]);

        self::assertTrue($answer['applied']);
        self::assertSame('always', $answer['reply'], 'the remember implies the session grant: the answer echoes `always`');
        self::assertIsString($answer['saved']);
        self::assertStringContainsString('Allowed Bash(git status), Bash(git status *)', $answer['saved'], 'the notice is the same wording `p` has always given');
        self::assertStringContainsString('saved to', $answer['saved']);
        self::assertTrue($ask->isSettled());

        self::assertSame([
            ['pattern' => 'Bash(git status)', 'action' => 'allow'],
            ['pattern' => 'Bash(git status *)', 'action' => 'allow'],
        ], $this->savedRows(), 'the file carries exactly the TUI `p` pair');
    }

    public function testRememberSessionAndPlainAnswersNeverTouchTheFile(): void
    {
        [$a, $sessionId] = $this->turnWithAClient();
        $sessionGrant = $this->fixture->backend->ask('c1', 'Bash', ['command' => 'ls']);
        $this->fixture->run(0.08);

        self::assertNull($a->call('permission.respond', [
            'sessionId' => $sessionId,
            'askId' => $sessionGrant->askId,
            'reply' => 'once',
            'remember' => 'session',
        ])['saved'], 'a session remember echoes `always` and saves nothing');
        self::assertFalse(\is_file($this->fixture->dir . '/config.json'), 'the `saved` of a session answer must not have knocked on the door');

        $plain = $this->fixture->backend->ask('c2', 'Bash', ['command' => 'git push']);
        $this->fixture->run(0.08);
        self::assertNull($a->call('permission.respond', [
            'sessionId' => $sessionId,
            'askId' => $plain->askId,
            'reply' => 'always',
        ])['saved'], 'a bare `always` stays in the session');
        self::assertFalse(\is_file($this->fixture->dir . '/config.json'));
    }

    public function testProjectRememberStaysRefusedAndUnwritten(): void
    {
        [$a, $sessionId] = $this->turnWithAClient();
        $ask = $this->fixture->backend->ask('c1', 'Bash', ['command' => 'ls']);
        $this->fixture->run(0.08);

        $late = $a->request('permission.respond', [
            'sessionId' => $sessionId,
            'askId' => $ask->askId,
            'reply' => 'always',
            'remember' => 'project',
        ]);

        self::assertSame(ErrorCode::Forbidden->value, $late['error']['code']);
        self::assertSame('remember_refused', $late['error']['data']['kind']);
        self::assertFalse($ask->isSettled());
        self::assertFalse(\is_file($this->fixture->dir . '/config.json'));
    }

    public function testRememberUserOnAQuestionWithoutAlwaysOffersSavesNothing(): void
    {
        [$a, $sessionId] = $this->turnWithAClient();
        $ask = $this->fixture->backend->ask('c1', 'Bash', ['command' => 'ls'], offersAlways: false);
        $this->fixture->run(0.08);

        $answer = $a->call('permission.respond', [
            'sessionId' => $sessionId,
            'askId' => $ask->askId,
            'reply' => 'always',
            'remember' => 'user',
        ]);

        self::assertTrue($answer['applied']);
        self::assertNull($answer['saved'], 'a question that never offered `always` has no grant to reproduce');
        self::assertFalse(\is_file($this->fixture->dir . '/config.json'));
    }

    public function testRememberUserOnAToolThatTranslatesToNoPatternSavesNothing(): void
    {
        [$a, $sessionId] = $this->turnWithAClient();
        $ask = $this->fixture->backend->ask('c1', 'PtyRun', ['command' => 'vim', 'steps' => [['send' => 'i']]]);
        $this->fixture->run(0.08);

        $answer = $a->call('permission.respond', [
            'sessionId' => $sessionId,
            'askId' => $ask->askId,
            'reply' => 'always',
            'remember' => 'user',
        ]);

        self::assertTrue($answer['applied']);
        self::assertNull($answer['saved'], 'a keystroke session has no honest general pattern — saving nothing beats saving a lie');
        self::assertFalse(\is_file($this->fixture->dir . '/config.json'));
    }

    public function testAlreadyAnsweredAndUnknownQuestionsSaveNothing(): void
    {
        [$a, $sessionId] = $this->turnWithAClient();
        $ask = $this->fixture->backend->ask('c1', 'Bash', ['command' => 'ls']);
        $this->fixture->run(0.08);

        $a->call('permission.respond', ['sessionId' => $sessionId, 'askId' => $ask->askId, 'reply' => 'once']);

        $late = $a->request('permission.respond', [
            'sessionId' => $sessionId,
            'askId' => $ask->askId,
            'reply' => 'always',
            'remember' => 'user',
        ]);
        self::assertSame('already_resolved', $late['error']['data']['kind']);

        $unknown = $a->request('permission.respond', [
            'sessionId' => $sessionId,
            'askId' => \str_repeat('0', 16),
            'reply' => 'always',
            'remember' => 'user',
        ]);
        self::assertSame('ask_not_found', $unknown['error']['data']['kind']);
        self::assertFalse(\is_file($this->fixture->dir . '/config.json'), 'a refused remember never reached the door');
    }

    public function testRememberUserOnARelayedQuestionIsRefusedAndUnwritten(): void
    {
        $relayRoot = $this->fixture->dir . '/relay';
        \mkdir($relayRoot, 0o700);
        $this->extraDirs[] = $relayRoot;
        $supervisor = new BackgroundSupervisor(tempRoot: $relayRoot);
        $this->fixture->tearDown();
        $this->fixture = ProtocolFixture::new(supervisor: $supervisor);

        $a = $this->fixture->client('a');
        $late = $a->request('permission.respond', [
            'sessionId' => 'sess_20261017000000_bbbb0002',
            'askId' => \str_repeat('f', 16),
            'reply' => 'always',
            'remember' => 'user',
        ]);

        self::assertSame(ErrorCode::UnsupportedInServer->value, $late['error']['code']);
        self::assertSame('remember_user_unavailable', $late['error']['data']['kind'], 'the relayed refusal keeps its historical kind and code');
        self::assertFalse(\is_file($this->fixture->dir . '/config.json'));
    }

    public function testRememberUserOnAnUnknownSessionWithoutASupervisorStaysAskNotFound(): void
    {
        $a = $this->fixture->client('a');

        $late = $a->request('permission.respond', [
            'sessionId' => 'sess_20261017000000_cccc0003',
            'askId' => \str_repeat('a', 16),
            'reply' => 'always',
            'remember' => 'user',
        ]);

        self::assertSame('ask_not_found', $late['error']['data']['kind'], 'the not-found answer comes before any remember verdict talk');
    }

    public function testRulesTiersSavedAllowRowsAndNumbersThemLikeForgetReadsThem(): void
    {
        $fixture = $this->fixtureWithGate([
            new PermissionRule('Bash(ls)', PermissionAction::Allow),
            new PermissionRule('Bash(ls *)', PermissionAction::Allow),
            new PermissionRule('Read(secrets.env)', PermissionAction::Allow),
            new PermissionRule('Edit(a.php)', PermissionAction::Deny),
        ]);
        $this->seedSaved([
            ['pattern' => 'Read(secrets.env)', 'action' => 'deny'],
            ['pattern' => 'Bash(ls)', 'action' => 'allow'],
            ['pattern' => 'Bash(ls *)', 'action' => 'allow'],
        ]);
        $a = $fixture->client('a');

        $view = $a->call('permission.rules');
        $rows = \array_column($view['rules'], null, 'pattern');

        self::assertSame('user', $rows['Bash(ls)']['tier']);
        self::assertSame(2, $rows['Bash(ls)']['savedIndex'], 'the number is the row place in the saved list — what `/permissions forget` and permission.forget both address by');
        self::assertSame('user', $rows['Bash(ls *)']['tier']);
        self::assertSame(3, $rows['Bash(ls *)']['savedIndex']);
        self::assertSame('config', $rows['Read(secrets.env)']['tier'], 'a saved DENY row does not make the ALLOW row of the gate look saved — the remembered grant was an allow');
        self::assertNull($rows['Read(secrets.env)']['savedIndex']);
        self::assertSame('config', $rows['Edit(a.php)']['tier']);
        self::assertNull($rows['Edit(a.php)']['savedIndex']);

        $gone = $a->call('permission.forget', ['index' => 3]);
        self::assertSame([['pattern' => 'Bash(ls *)', 'action' => 'allow']], $gone['removed'], 'the savedIndex the view shows is exactly the index the removal takes');
    }

    public function testRulesDegradeToConfigTierWhenTheSavedFileIsUnreadable(): void
    {
        $fixture = $this->fixtureWithGate([new PermissionRule('Bash(ls)', PermissionAction::Allow)]);
        \file_put_contents($fixture->dir . '/config.json', '{ not json');
        $a = $fixture->client('a');

        $view = $a->call('permission.rules');

        self::assertSame('default', $view['mode']);
        self::assertSame([['pattern' => 'Bash(ls)', 'action' => 'allow', 'tier' => 'config', 'savedIndex' => null]], $view['rules'], 'a broken saved file degrades the label, never sinks the audit view');
    }

    public function testForgetByPatternRemovesExactlyThatRow(): void
    {
        $this->seedSaved([
            ['pattern' => 'Bash(ls)', 'action' => 'allow'],
            ['pattern' => 'Bash(ls *)', 'action' => 'allow'],
        ]);

        $a = $this->fixture->client('a');
        $gone = $a->call('permission.forget', ['pattern' => 'Bash(ls)']);

        self::assertSame([['pattern' => 'Bash(ls)', 'action' => 'allow']], $gone['removed']);
        self::assertStringEndsWith('/config.json', (string) $gone['path']);
        self::assertSame([['pattern' => 'Bash(ls *)', 'action' => 'allow']], $this->savedRows(), 'the twin pattern the file still needs for `ls -a` survives');
    }

    public function testForgetANumberNobodyMatchesWritesNothing(): void
    {
        $this->seedSaved([['pattern' => 'Bash(ls)', 'action' => 'allow']]);
        $path = $this->fixture->dir . '/config.json';
        $before = (string) \file_get_contents($path);

        $a = $this->fixture->client('a');
        $gone = $a->call('permission.forget', ['pattern' => 'Bash(nope)']);

        self::assertSame([], $gone['removed'], 'an honest empty: nothing matched, nothing was claimed');
        self::assertSame($before, (string) \file_get_contents($path), 'and the file is byte-for-byte what it was');
    }

    public function testForgetTakesExactlyOneOfPatternOrIndex(): void
    {
        $a = $this->fixture->client('a');

        foreach ([
            ['pattern' => 'Bash(ls)', 'index' => 1],
            [],
        ] as $params) {
            $late = $a->request('permission.forget', $params);
            self::assertSame(ErrorCode::InvalidParams->value, $late['error']['code']);
            self::assertSame('forget_target', $late['error']['data']['kind'], 'two targets is a coin flip, zero is a shrug; neither is a removal');
        }
    }

    public function testForgetRemovesDenyRowsTooSoTheLedgerStaysHonest(): void
    {
        $this->seedSaved([
            ['pattern' => 'Read(secrets.env)', 'action' => 'deny'],
            ['pattern' => 'Bash(ls)', 'action' => 'allow'],
        ]);

        $a = $this->fixture->client('a');
        $gone = $a->call('permission.forget', ['index' => 1]);

        self::assertSame([['pattern' => 'Read(secrets.env)', 'action' => 'deny']], $gone['removed'], 'a saved DENY is as removable as a saved allow — forget is not an allow-only door');
        self::assertSame([['pattern' => 'Bash(ls)', 'action' => 'allow']], $this->savedRows());
    }

    /**
     * A fixture whose workspace carries $rules as its gate, so
     * `permission.rules` has rule rows to tier.
     *
     * @param list<PermissionRule> $rules
     */
    private function fixtureWithGate(array $rules): ProtocolFixture
    {
        $this->fixture->tearDown();
        $this->fixture = ProtocolFixture::new(permissionGate: new PermissionGate(PermissionMode::Default, $rules));

        return $this->fixture;
    }

    /**
     * @param list<array{pattern: string, action: string}> $rows
     */
    private function seedSaved(array $rows): void
    {
        \file_put_contents(
            $this->fixture->dir . '/config.json',
            (string) json_encode(['permissionRules' => $rows]),
        );
    }

    /**
     * @return list<array<string, string>>
     */
    private function savedRows(): array
    {
        $decoded = json_decode((string) \file_get_contents($this->fixture->dir . '/config.json'), true);

        return \is_array($decoded['permissionRules'] ?? null) ? $decoded['permissionRules'] : [];
    }

    /**
     * One client following one session whose turn is running.
     *
     * @return array{0: WireClient, 1: string}
     */
    private function turnWithAClient(): array
    {
        $a = $this->fixture->client('a');
        $sessionId = $this->fixture->session($a);
        $a->call('session.subscribe', ['sessionId' => $sessionId]);
        $this->fixture->run();
        $a->call('session.send', ['sessionId' => $sessionId, 'text' => 'tidy up']);

        return [$a, $sessionId];
    }
}
