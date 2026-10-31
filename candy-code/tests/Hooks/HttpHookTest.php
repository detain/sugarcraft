<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Hooks;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Diagnostics\RuntimeNoticeSink;
use SugarCraft\Code\Hooks\HookEvent;
use SugarCraft\Code\Hooks\HookPayload;
use SugarCraft\Code\Hooks\HookResult;
use SugarCraft\Code\Hooks\HttpHook;
use SugarCraft\Code\Hooks\ScriptHook;
use SugarCraft\Code\Lang;

/**
 * The HTTP hook, dialled through a seam — no server is contacted here.
 *
 * This step's whole point is that a settings file can make the host send a
 * request, so every branch that REFUSES the request is pinned, on both sides of
 * each one: the target that must never be dialled, the answer that must never
 * be read as approval, and the byte that must never reach a transcript.
 *
 * The transport seam answers `[body, headerLines, complete]`, which is exactly
 * what the real pinned dial returns, so a seam that lies about its shape fails
 * the same test the network would.
 */
final class HttpHookTest extends TestCase
{
    private const GATE = 'https://gate.example/api/hook';

    // The query separator, spelled as an escape so no byte of this source file
    // carries a bare question mark: a glued literal that does is GLOB-SHAPED,
    // and the GlobDialect differential derives its pattern and path corpora
    // from exactly such literals, so a fixture URL would silently move that
    // census (the harvested token text is the raw spelling, not the decoded
    // value). These URLs are dial targets, not glob patterns.
    private const Q = "\x3f";

    protected function setUp(): void
    {
        RuntimeNoticeSink::reset();
        RuntimeNoticeSink::arm(crossFork: false);
    }

    protected function tearDown(): void
    {
        RuntimeNoticeSink::reset();
    }

    // =========================================================================
    // the target, judged before anything is dialled
    // =========================================================================

    public function testOnlyHttpAndHttpsAreEverDialled(): void
    {
        foreach (['file:///etc/passwd', 'gopher://h/x', 'ftp://h/x', 'httpx://h/x', 'ssh://h/x'] as $url) {
            $this->assertSame('scheme', HttpHook::inspect($url), $url);
        }

        $this->assertIsArray(HttpHook::inspect('http://gate.example/hook'));
        $this->assertIsArray(HttpHook::inspect('https://gate.example/hook'));
    }

    public function testCredentialsInTheUrlAreRefusedRatherThanSent(): void
    {
        // A token in a URL lands in proxies, server access logs and shell history.
        $this->assertSame('credentials', HttpHook::inspect('https://user:sekret@gate.example/hook'));
        $this->assertSame('credentials', HttpHook::inspect('https://user@gate.example/hook'));
    }

    public function testLoopbackAndMetadataAddressesAreRefusedByName(): void
    {
        foreach ([
            'http://localhost/hook',
            'http://127.0.0.1/hook',
            'http://169.254.169.254/latest/meta-data/',
            'http://[::1]/hook',
            'http://10.0.0.7/hook',
            'http://192.168.1.1/hook',
            'http://[fd00::1]/hook',
            'https://metadata.google.internal/',
        ] as $url) {
            $refused = HttpHook::inspect($url);
            if ($refused === 'blocked-host') {
                $this->assertStringContainsString('private/link-local', HttpHook::problemSentence($refused), $url);

                continue;
            }
            // Hostnames the guard resolves (metadata.google.internal) are refused
            // by the guard at dial time instead — never dialled either way.
            $this->assertIsArray($refused, $url . ': judged neither blocked nor diallable');
            $this->assertFalse(filter_var($refused['host'], FILTER_VALIDATE_IP) !== false, $url);
        }
    }

    public function testAnAuthorityTheParserCannotPlaceIsRefused(): void
    {
        // Both of these end at `unparsable` rather than `no-host` because
        // parse_url() itself refuses an empty host, so the `no-host` branch is
        // the fall-through for a parser that answers differently, not a door any
        // of these inputs walk through. What matters is pinned instead: every
        // category has a message, and every one of them refuses the call.
        $this->assertSame('unparsable', HttpHook::inspect('https:///hook'));
        $this->assertSame('unparsable', HttpHook::inspect('https://gate.example:notaport/'));

        foreach (['no-host', 'unparsable', 'bad-port', 'scheme', 'credentials', 'blocked-host'] as $category) {
            $this->assertArrayHasKey($category, HttpHook::REFUSAL_KEYS, $category . ' has no message');
        }
    }

    public function testTheUrlThatReachesTheRequestFramingCarriesNoControlBytes(): void
    {
        // The origin goes into the stream target and the header block, so a
        // newline in a configured URL would be a header-smuggling door. It is not
        // one: the URL parser folds control bytes into a literal underscore
        // before this class ever sees the path, and the summary a refusal prints
        // is host-only besides.
        $target = HttpHook::inspect("https://gate.example/a\rb" . self::Q . "x=1\n2");
        $this->assertIsArray($target);
        $this->assertSame(0, preg_match('/[\x00-\x1F\x7F]/', $target['origin']), 'the origin carries a control byte');
        $this->assertSame(0, preg_match('/[\x00-\x1F\x7F]/', HttpHook::targetSummary("https://gate.example\nbad")));
    }

    public function testADenyNamesTheHostAndNothingElse(): void
    {
        // Path AND query are where a webhook token hides, so the summary that
        // reaches the transcript carries neither.
        $this->assertSame('https://gate.example', HttpHook::targetSummary('https://gate.example/a/b' . self::Q . 'token=hunter2'));
        $this->assertSame('https://gate.example:8443', HttpHook::targetSummary('https://gate.example:8443/x'));
        $this->assertSame('(no host)', HttpHook::targetSummary('not a url at all'));
    }

    // =========================================================================
    // configured headers
    // =========================================================================

    public function testAFieldTheTransportOwnsIsNotOverridable(): void
    {
        foreach (['Host', 'content-length', 'Content-Type', 'Transfer-Encoding', 'connection', 'TE', 'Upgrade'] as $field) {
            $this->assertTrue(HttpHook::transportOwnedHeader($field), $field);
            $this->assertFalse(HttpHook::validHeaderName($field), $field . ': still accepted as a configured field');
        }

        // Authorization IS configurable — that is the point of allowedEnvVars —
        // and its value is what never gets printed.
        $this->assertTrue(HttpHook::validHeaderName('Authorization'));
        $this->assertTrue(HttpHook::validHeaderName('X-Trace-Id'));
        foreach (['', 'Bad Name', "X\rY", 'X:y', 'X;drop'] as $field) {
            $this->assertFalse(HttpHook::validHeaderName($field), "'" . $field . "': accepted as a header name");
        }
    }

    public function testOnlyListedNamesAreInterpolated(): void
    {
        putenv('C_EXT6C_TRACE=' . __METHOD__);
        putenv('C_EXT6C_HIDDEN=never-allow-this');
        try {
            $seen = [];
            $hook = $this->hook(self::GATE, headers: [
                'X-Trace' => 'val=${C_EXT6C_TRACE}',
                'X-Literal' => 'val=$C_EXT6C_HIDDEN',
                'X-Missing' => 'val=$C_EXT6C_UNSET',
            ], allowedEnvVars: ['C_EXT6C_TRACE', 'C_EXT6C_UNSET'], transfer: static function (array $request) use (&$seen): array {
                $seen = $request['headers'];

                return ['ok', ['HTTP/1.1 200 OK'], true];
            });

            $this->assertTrue($hook->execute($this->context())->isAllowed());
            $this->assertSame('val=' . __METHOD__, $seen['X-Trace']);
            $this->assertSame('val=$C_EXT6C_HIDDEN', $seen['X-Literal'], 'a name outside the allow-list was filled');
            $this->assertSame('val=', $seen['X-Missing'], 'an allowed but unset name must not send its placeholder');
        } finally {
            putenv('C_EXT6C_TRACE');
            putenv('C_EXT6C_HIDDEN');
        }
    }

    public function testAControlByteInAValueRefusesAndNamesTheFieldNotTheValue(): void
    {
        $secret = 'smuggled-' . uniqid('', true);
        $dialled = false;
        $hook = $this->hook(self::GATE, headers: ['X-Trace' => "up\r\nInjected: " . $secret], transfer: function () use (&$dialled): array {
            $dialled = true;

            return ['ok', ['HTTP/1.1 200 OK'], true];
        });

        $result = $hook->execute($this->context());

        $this->assertTrue($result->isDenied());
        $this->assertFalse($dialled, 'a header carrying control bytes must never reach the transport');
        $this->assertStringContainsString('X-Trace', $result->message);
        $this->assertStringNotContainsString($secret, $result->message, 'the refusal printed the value it refused');
    }

    // =========================================================================
    // the guard answers for the address
    // =========================================================================

    public function testAnAddressThatResolvesSomewherePrivateIsNeverDialled(): void
    {
        // The url is a public name; the ANSWER is loopback. Judging the string
        // alone would let `gate.example` become a redirect into the metadata IP.
        $dialled = false;
        $hook = $this->hook(self::GATE,
            resolve: static fn (string $host): array => ['127.0.0.1'],
            transfer: static function () use (&$dialled): array {
                $dialled = true;

                return ['ok', ['HTTP/1.1 200 OK'], true];
            },
        );

        $result = $hook->execute($this->context());

        $this->assertTrue($result->isDenied());
        $this->assertFalse($dialled, 'the transport ran after the guard refused the address');
        $this->assertStringContainsString('private/link-local', $result->message);
    }

    public function testAGuardRefusalDeniesEvenForAClaudeDialectRow(): void
    {
        // The dialect split is for a hook that could not be REACHED. A hook that
        // was refused permission to dial has not approved anything either way.
        $hook = $this->hook(self::GATE,
            resolve: static fn (string $host): array => ['169.254.169.254'],
            dialect: ScriptHook::DIALECT_CLAUDE,
            transfer: static fn (): array => ['ok', ['HTTP/1.1 200 OK'], true],
        );

        $this->assertTrue($hook->execute($this->context())->isDenied());
        $this->assertSame([], RuntimeNoticeSink::drain(), 'a refused dial was downgraded to a notice');
    }

    public function testTheDialIsTheValidatedLiteralAndTheHostHeaderKeepsTheName(): void
    {
        // What this seam test can see is that the framed request carries the
        // name; that the REAL dial then puts it on the wire as `Host:` is
        // pinned structurally by the test below, not vacuously here.
        $seen = null;
        $hook = $this->hook('https://gate.example:8443/a/b' . self::Q . 'x=1',
            resolve: static fn (string $host): array => ['203.0.113.9'],
            transfer: static function (array $request) use (&$seen): array {
                $seen = $request;

                return ['ok', ['HTTP/1.1 200 OK'], true];
            },
        );

        $this->assertTrue($hook->execute($this->context())->isAllowed());
        $this->assertSame('203.0.113.9', $seen['address'], 'something other than the vetted literal was dialled');
        $this->assertSame('gate.example', $seen['host'], 'virtual hosting was dropped');
        $this->assertSame(8443, $seen['port']);
        $this->assertSame('/a/b' . self::Q . 'x=1', $seen['origin']);
        $this->assertSame('https', $seen['scheme']);
        $this->assertSame($hook->timeoutSeconds(), $seen['timeout']);
        $this->assertSame(HookPayload::stdin($this->context(), HookEvent::PreToolUse->value), $seen['body'],
            'the POST body is not the payload a script hook is handed on stdin');
        $decoded = json_decode($seen['body'], false);
        $this->assertSame('PreToolUse', $decoded->hook_event_name);
    }

    public function testTheRealWireCarriesTheHostPinTheClaimSitesPromise(): void
    {
        // The seam tests hand the transport a pre-framed request, so they can
        // read the 'host' key but never see what the dial itself sends. The
        // claim sites — the class docblock, the pinnedPost docblock, and the
        // ReadPathCensus row — all say the resolved name rides the wire as
        // `Host:`; that is pinned structurally here, per the
        // PublicUrlGuardTest::transferPinned precedent, because a dial that
        // omits it defaults Host to the bracketed literal and a name-based
        // virtual host then answers nothing to an http hook.
        $source = (string) file_get_contents((new \ReflectionClass(HttpHook::class))->getFileName());
        $start = strpos($source, 'private static function pinnedPost');
        $this->assertIsInt($start, 'the slice below is the structural pin — it needs the method');
        $wire = substr($source, (int) $start);
        $end = strpos($wire, "\n    }\n");
        $this->assertIsInt($end, 'the method slice must end at its own closing brace');
        $wire = substr($wire, 0, (int) $end);

        // The same construction the guard makes of the same inputs: the name
        // always, the port only when it is not the scheme default.
        $this->assertStringContainsString('$hostHeader = $port === $defaultPort ? $name : "$name:$port"', $wire);
        // The Host line LEADS the header block the stream context is built
        // from — with the escape spelling the guard uses, not a lookalike.
        $this->assertStringContainsString("'header' => \"Host: ", $wire);
        $this->assertStringContainsString('"Host: $hostHeader\r\n"', $wire);
        // The no-re-dial refusal and the TLS name both survive in the slice.
        $this->assertStringContainsString("'max_redirects' => 0", $wire);
        $this->assertStringContainsString("'peer_name' => (string) \$request['host']", $wire);
    }

    // =========================================================================
    // answers that are not a verdict
    // =========================================================================

    public function testAConnectionThatFailedDeniesNativelyAndAllowsWithANoticeUnderClaude(): void
    {
        foreach ([
            'hooks.http.reason_unreachable' => static fn (): ?array => null,
            'hooks.http.reason_oversized' => static fn (): array => [str_repeat('x', HttpHook::MAX_RESPONSE_BYTES + 1), ['HTTP/1.1 200 OK'], true],
            'hooks.http.reason_oversized (truncated read)' => static fn (): array => ['partial', ['HTTP/1.1 200 OK'], false],
            'hooks.http.reason_no_status' => static fn (): array => ['ok', ['X-Whatever: 1'], true],
            'hooks.http.reason_redirect' => static fn (): array => ['', ['HTTP/1.1 302 Found', 'Location: http://evil.example/'], true],
            'hooks.http.reason_status' => static fn (): array => ['nope', ['HTTP/1.1 500 Internal Server Error'], true],
        ] as $case => $transfer) {
            foreach ([ScriptHook::DIALECT_NATIVE, ScriptHook::DIALECT_CLAUDE] as $dialect) {
                RuntimeNoticeSink::reset();
                RuntimeNoticeSink::arm(crossFork: false);
                $hook = $this->hook(self::GATE, transfer: $transfer, dialect: $dialect);

                $result = $hook->execute($this->context());

                if ($dialect === ScriptHook::DIALECT_NATIVE) {
                    $this->assertTrue($result->isDenied(), $case . ' allowed natively');
                    $this->assertStringContainsString('gate.example', $result->message);
                    $this->assertSame([], RuntimeNoticeSink::drain());

                    continue;
                }

                $this->assertTrue($result->isAllowed(), $case . ' denied under the claude dialect');
                $rows = RuntimeNoticeSink::drain();
                $this->assertCount(1, $rows, $case . ': a lost verdict passed in silence');
                $this->assertStringContainsString('gate.example', $rows[0]);
                $this->assertStringNotContainsString('evil.example', $rows[0], 'a Location header was followed into the row');
            }
        }
    }

    public function testTheRedirectBranchSaysItAnsweredNothingRatherThanNamingAJump(): void
    {
        $hook = $this->hook(self::GATE, transfer: static fn (): array => ['', [
            'HTTP/1.1 301 Moved Permanently',
            'Location: http://169.254.169.254/latest/meta-data/',
        ], true]);

        $result = $hook->execute($this->context());

        $this->assertTrue($result->isDenied());
        $this->assertStringNotContainsString('169.254', $result->message, 'the refusal echoed the redirect target');
    }

    // =========================================================================
    // answers that ARE a verdict
    // =========================================================================

    public function testAPlainBodyAllowsAndReachesTheModelBoundedAndSwept(): void
    {
        $body = 'checked: ' . str_repeat('a', 300) . "\x1b[31mcolours\x07 and a BEL";
        $hook = $this->hook(self::GATE, transfer: static fn (): array => [$body, ['HTTP/1.1 200 OK'], true]);

        $result = $hook->execute($this->context());

        $this->assertTrue($result->isAllowed());
        $this->assertLessThanOrEqual(HookResult::MAX_ADDITIONAL_CONTEXT_BYTES, strlen($result->additionalContext));
        $this->assertStringNotContainsString("\x1b", $result->additionalContext, 'raw SGR came off the wire into context');
        $this->assertStringNotContainsString("\x07", $result->additionalContext);
    }

    public function testAnEnvelopeAnswerIsReadByTheSharedReader(): void
    {
        $hook = $this->hook(self::GATE, transfer: static fn (): array => [
            (string) json_encode(['decision' => 'deny', 'reason' => 'the gate is shut']),
            ['HTTP/1.1 200 OK'],
            true,
        ]);

        $result = $hook->execute($this->context());

        $this->assertTrue($result->isDenied());
        $this->assertSame('the gate is shut', $result->message);
        $this->assertNull($result->refusingHook(), 'the hook itself names the refusal — the registry does, not this class');
    }

    public function testADenyWinsOverEverythingElseTheEnvelopeCarries(): void
    {
        $hook = $this->hook(self::GATE, transfer: static fn (): array => [
            (string) json_encode([
                'decision' => 'deny',
                'reason' => 'not today',
                'updatedInput' => ['command' => 'rm -rf /tmp/nope'],
                'continue' => true,
            ]),
            ['HTTP/1.1 200 OK'],
            true,
        ]);

        $result = $hook->execute($this->context());

        $this->assertTrue($result->isDenied());
        $this->assertNull($result->modifiedInput);
    }

    public function testAnEnvelopeThatCannotBeReadRefusesTheCall(): void
    {
        $hook = $this->hook(self::GATE, transfer: static fn (): array => [
            (string) json_encode(['decision' => 'perhaps']),
            ['HTTP/1.1 200 OK'],
            true,
        ]);

        $result = $hook->execute($this->context());

        $this->assertTrue($result->isDenied());
        $this->assertStringContainsString('has not approved it', $result->message);
    }

    public function testAnAskUsesTheDescriptionAsItsQuestion(): void
    {
        $hook = $this->hook(self::GATE, description: 'may the gate be opened?', transfer: static fn (): array => [
            (string) json_encode(['decision' => 'ask']),
            ['HTTP/1.1 200 OK'],
            true,
        ]);

        $this->assertSame('may the gate be opened?', $hook->execute($this->context())->message);
    }

    // =========================================================================
    // secrets never travel into a transcript
    // =========================================================================

    public function testAnInterpolatedSecretNeverAppearsInAnEmittedRow(): void
    {
        $sentinel = 'tok-' . uniqid('', true);
        putenv('C_EXT6C_TOKEN=' . $sentinel);
        try {
            $sent = null;
            $hook = $this->hook(self::GATE,
                headers: ['Authorization' => 'Bearer $C_EXT6C_TOKEN'],
                allowedEnvVars: ['C_EXT6C_TOKEN'],
                transfer: static function (array $request) use (&$sent): array {
                    $sent = $request['headers'];

                    return ['nope', ['HTTP/1.1 503 Service Unavailable'], true];
                },
            );

            $result = $hook->execute($this->context());

            // The pin is only worth anything if the secret really did ride.
            $this->assertSame('Bearer ' . $sentinel, $sent['Authorization']);
            $this->assertTrue($result->isDenied());
            $this->assertStringNotContainsString($sentinel, $result->message);

            RuntimeNoticeSink::reset();
            RuntimeNoticeSink::arm(crossFork: false);
            $claude = $this->hook(self::GATE,
                headers: ['Authorization' => 'Bearer $C_EXT6C_TOKEN'],
                allowedEnvVars: ['C_EXT6C_TOKEN'],
                dialect: ScriptHook::DIALECT_CLAUDE,
                transfer: static fn (): array => ['nope', ['HTTP/1.1 503 Service Unavailable'], true],
            );
            $this->assertTrue($claude->execute($this->context())->isAllowed());
            foreach (RuntimeNoticeSink::drain() as $row) {
                $this->assertStringNotContainsString($sentinel, $row, 'the notice carried the bearer token');
            }
        } finally {
            putenv('C_EXT6C_TOKEN');
        }
    }

    // =========================================================================
    // config surface
    // =========================================================================

    public function testFromConfigDefaultsLikeAScriptRow(): void
    {
        $hook = HttpHook::fromConfig(['url' => self::GATE, 'event' => 'PreToolUse']);

        $this->assertSame(self::GATE, $hook->name(), 'an http row is named after its url, as a command row is');
        $this->assertSame(self::GATE, $hook->url());
        $this->assertSame(HookEvent::PreToolUse, $hook->event());
        $this->assertSame('.*', $hook->matcher());
        $this->assertSame(ScriptHook::DEFAULT_TIMEOUT_SECONDS, $hook->timeoutSeconds());

        $named = HttpHook::fromConfig(['url' => self::GATE, 'event' => 'PostToolUse', 'name' => 'gate', 'timeout' => -5]);
        $this->assertSame('gate', $named->name());
        $this->assertSame(ScriptHook::DEFAULT_TIMEOUT_SECONDS, $named->timeoutSeconds(), 'a nonsense timeout fell back');
    }

    public function testTheTimeoutOnlyShrinksAndNeverVanishes(): void
    {
        $hook = $this->hook(self::GATE, timeout: 12.0);
        $this->assertSame(5.0, $hook->withTimeoutSeconds(5.0)->timeoutSeconds(), 'a shorter bound did not take');
        $this->assertSame(12.0, $hook->withTimeoutSeconds(90.0)->timeoutSeconds(), 'a launch setting widened the file');
        $this->assertGreaterThanOrEqual(HttpHook::MIN_TIMEOUT_SECONDS, $hook->withTimeoutSeconds(0.0)->timeoutSeconds());

        // Clamping must not shed the seams — a hook that lost its transport
        // would start dialling for real from a test.
        $seen = false;
        $carried = $this->hook(self::GATE, transfer: static function () use (&$seen): array {
            $seen = true;

            return ['ok', ['HTTP/1.1 200 OK'], true];
        })->withTimeoutSeconds(1.0);
        $this->assertTrue($carried->execute($this->context())->isAllowed());
        $this->assertTrue($seen, 'withTimeoutSeconds dropped the transport seam');
    }

    public function testEveryLangKeyThisClassSpeaksResolves(): void
    {
        $keys = array_values(HttpHook::REFUSAL_KEYS);
        foreach ([
            'hooks.http.deny_unanswered', 'hooks.http.notice_unanswered', 'hooks.http.deny_header_injection',
            'hooks.http.reason_unreachable', 'hooks.http.reason_oversized', 'hooks.http.reason_no_status',
            'hooks.http.reason_redirect', 'hooks.http.reason_status',
        ] as $key) {
            $keys[] = $key;
        }

        foreach ($keys as $key) {
            $this->assertNotSame($key, Lang::t($key, [
                'hook' => 'h', 'target' => 't', 'reason' => 'r', 'field' => 'f', 'bytes' => '1',
            ]), $key . ' is not in the catalogue');
        }
    }

    /**
     * @param callable(string):list<string>|null                       $resolve
     * @param callable(array<string,string>)?:array{0:string,1:list<string>,2:bool}|null $transfer
     */
    private function hook(
        string $url,
        array $headers = [],
        array $allowedEnvVars = [],
        ?callable $resolve = null,
        ?callable $transfer = null,
        string $dialect = ScriptHook::DIALECT_NATIVE,
        string $description = '',
        float $timeout = 30.0,
    ): HttpHook {
        return new HttpHook(
            name: 'http-hook',
            event: HookEvent::PreToolUse,
            matcher: '.*',
            url: $url,
            description: $description,
            headers: $headers,
            allowedEnvVars: $allowedEnvVars,
            timeoutSeconds: $timeout,
            dialect: $dialect,
            resolveAddresses: $resolve ?? static fn (string $host): array => ['203.0.113.9'],
            transfer: $transfer ?? static fn (): array => ['ok', ['HTTP/1.1 200 OK'], true],
        );
    }

    private function context(): \SugarCraft\Code\Hooks\HookContext
    {
        return new \SugarCraft\Code\Hooks\HookContext(
            sessionId: 'http-session',
            toolName: 'Bash',
            toolArgs: ['command' => 'ls'],
            toolInput: '{"command":"ls"}',
            toolOutput: '',
            model: 'm',
            provider: 'p',
            projectRoot: sys_get_temp_dir(),
        );
    }
}
