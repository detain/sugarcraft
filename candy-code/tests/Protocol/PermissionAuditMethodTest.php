<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Protocol;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Hooks\BuiltIn\AuditHook;
use SugarCraft\Code\Hooks\BuiltIn\AuditLogReader;
use SugarCraft\Code\Hooks\HookContext;
use SugarCraft\Code\Permissions\DenialKind;
use SugarCraft\Code\Protocol\Dispatcher;
use SugarCraft\Code\Protocol\ErrorCode;
use SugarCraft\Code\Protocol\Schema\MethodSchemas;
use SugarCraft\Code\Protocol\Schema\ProtocolSchema;
use SugarCraft\Code\Protocol\Scope;
use SugarCraft\Code\Tests\Server\Support\ProtocolFixture;

/**
 * C-SEC-6.d: `permission.audit` over the wire — the web view of the recent
 * tool-call decisions the TUI's own audit trail has already written. The rows
 * come from {@see AuditLogReader}'s bounded tail of the per-user log, and the
 * method is pinned at `Scope::Approve` on purpose: the log spans every
 * session and root, so the policy voice is exactly the voice allowed to read
 * it (the class docblock on {@see \SugarCraft\Code\Protocol\Methods\PermissionMethods}
 * carries the full argument).
 *
 * The scope itself is pinned through the registry — {@see Dispatcher} hands
 * every live connection `Scope::owner()`, so a wire-level refusal of an
 * Approve method by a lesser token is unreachable in this build (the same
 * shape as `permission.respond` and `permission.forget`, which likewise ride
 * their advertised scope rather than a per-call check the socket can exercise).
 * What the socket CAN exercise, and what these tests pin, is the cap door:
 * `limit` outside `1..AuditLogReader::MAX_ROWS` is refused with
 * `invalid_params` before anything is read, and the result always validates
 * against the method's advertised schema.
 */
final class PermissionAuditMethodTest extends TestCase
{
    private ProtocolFixture $fixture;

    private string $auditDir;

    private ?string $priorPin;

    protected function setUp(): void
    {
        $this->fixture = ProtocolFixture::new();
        $this->priorPin = AuditHook::defaultLogDirectoryPin();

        $dir = \sys_get_temp_dir() . '/crush-audit-method-' . \bin2hex(\random_bytes(6));
        \mkdir($dir, 0700);
        $this->auditDir = $dir;
        AuditHook::pinDefaultLogDirectory($dir);
    }

    protected function tearDown(): void
    {
        $this->fixture->tearDown();
        AuditHook::pinDefaultLogDirectory($this->priorPin);
        ProtocolFixture::removeTree($this->auditDir);
    }

    public function testTheAuditMethodIsAdvertisedAtTheApproveScopeWithNoSideEffect(): void
    {
        $spec = Dispatcher::methods()->get('permission.audit');

        self::assertNotNull($spec, 'permission.audit is on the roster');
        self::assertSame(Scope::Approve, $spec->scope, 'the log spans every session, so only the policy voice reads it');
        self::assertFalse($spec->sideEffects, 'reading a trail decides nothing');
    }

    public function testAuditAnswersNewestRowsAcrossSessionsAndAnEmptyLogIsQuietlyEmpty(): void
    {
        $a = $this->fixture->client('a');
        $sessionIdA = $this->fixture->session($a);
        $b = $this->fixture->client('b');
        $sessionIdB = $this->fixture->session($b);

        $result = $a->call('permission.audit', []);
        self::assertSame([], $result['rows'], 'no tool call has been audited yet');
        self::assertFalse($result['unreadable']);

        $hook = new AuditHook();
        $hook->execute($this->context($sessionIdA, 'Read'));
        $hook->recordDenial($this->context($sessionIdB, 'Bash'), DenialKind::Refused, 'editing outside the project');
        $hook->recordWithheld($this->context($sessionIdA, 'Write'), 'a formatting hook declined the file');
        $hook->execute($this->context($sessionIdB, 'Edit'));

        $result = $a->call('permission.audit', []);
        self::assertSame([], self::errors($result, 'permission.audit'), 'the answer is its advertised shape');
        self::assertSame([$sessionIdA, $sessionIdB, $sessionIdA, $sessionIdB], \array_column($result['rows'], 'session'), 'oldest first, both sessions, until asked to narrow');
        self::assertSame(['allow', 'deny', 'withheld', 'allow'], \array_column($result['rows'], 'verdict'));
        self::assertNull($result['rows'][0]['kind'], 'an allowed call carries no denial kind');
        self::assertSame('refused', $result['rows'][1]['kind']);
        self::assertNull($result['rows'][2]['kind'], 'a withheld answer with no named hook carries no kind');
        self::assertFalse($result['unreadable']);
    }

    public function testSessionIdNarrowsTheTailToThatSessionAlone(): void
    {
        $a = $this->fixture->client('a');
        $sessionIdA = $this->fixture->session($a);
        $b = $this->fixture->client('b');
        $sessionIdB = $this->fixture->session($b);

        $hook = new AuditHook();
        $hook->execute($this->context($sessionIdA, 'Read'));
        $hook->recordDenial($this->context($sessionIdB, 'Bash'), DenialKind::Hook, 'a pre-tool hook refused');
        $hook->execute($this->context($sessionIdA, 'Write'));

        $mine = $a->call('permission.audit', ['sessionId' => $sessionIdA]);
        self::assertSame([$sessionIdA, $sessionIdA], \array_column($mine['rows'], 'session'));
        self::assertSame(['Read', 'Write'], \array_column($mine['rows'], 'tool'));

        $theirs = $b->call('permission.audit', ['sessionId' => $sessionIdB]);
        self::assertSame([$sessionIdB], \array_column($theirs['rows'], 'session'));
        self::assertSame('deny', $theirs['rows'][0]['verdict']);
        self::assertSame('hook', $theirs['rows'][0]['kind']);
    }

    public function testALimitPastTheReadersCapIsRefusedBeforeAnythingIsRead(): void
    {
        $hook = new AuditHook();
        $hook->execute($this->context('s-1', 'Read'));

        $a = $this->fixture->client('a');

        $tooBig = $a->request('permission.audit', ['limit' => AuditLogReader::MAX_ROWS + 1]);
        self::assertSame(ErrorCode::InvalidParams->value, $tooBig['error']['code']);
        self::assertSame('invalid_params', $tooBig['error']['data']['kind']);

        $tooSmall = $a->request('permission.audit', ['limit' => 0]);
        self::assertSame(ErrorCode::InvalidParams->value, $tooSmall['error']['code']);

        $atCap = $a->call('permission.audit', ['limit' => AuditLogReader::MAX_ROWS]);
        self::assertCount(1, $atCap['rows']);

        $bounds = (MethodSchemas::params('permission.audit'))->toArray()['properties']['limit'];
        self::assertSame([1, AuditLogReader::MAX_ROWS], [$bounds['minimum'], $bounds['maximum']], 'the advertised bounds are the cap door itself');
    }

    public function testAnUnreadableLogSaysSoInsteadOfThrowing(): void
    {
        \mkdir($this->auditDir . '/audit.log');

        $a = $this->fixture->client('a');
        $result = $a->call('permission.audit', []);

        self::assertSame([], $result['rows'], 'a refused read answers empty, as if nothing was audited');
        self::assertTrue($result['unreadable'], 'but it says it could not read, which is not the same sentence');
    }

    public function testTheVerdictRosterAndTheSchemaAgree(): void
    {
        $verdict = (MethodSchemas::result('permission.audit'))->toArray()['properties']['rows']['items']['properties']['verdict'];

        self::assertSame(['allow', 'deny', 'withheld'], $verdict['enum']);
        self::assertSame($verdict['enum'], AuditLogReader::verdicts(), 'the advertised roster is the reader\'s own — drift means one edit');
    }

    private function context(string $sessionId, string $toolName): HookContext
    {
        return new HookContext(
            sessionId: $sessionId,
            toolName: $toolName,
            toolArgs: [],
            toolInput: 'edit notes/todo.md',
            toolOutput: 'looks fine',
            model: 'test-model',
            provider: 'test-provider',
            projectRoot: $this->fixture->root,
        );
    }

    /** @param array<string,mixed> $result @return list<string> */
    private static function errors(array $result, string $method): array
    {
        $schema = MethodSchemas::result($method);
        self::assertNotNull($schema, $method . ' advertises a result shape');

        return ProtocolSchema::errors($result, $schema, '$');
    }
}
