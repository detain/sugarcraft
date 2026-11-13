<?php

declare(strict_types=1);

namespace SugarCraft\SshServer\Tests\Middleware;

use SugarCraft\SshServer\Context;
use SugarCraft\SshServer\Middleware\Auth;
use SugarCraft\SshServer\Session;
use PHPUnit\Framework\TestCase;

final class AuthTest extends TestCase
{
    /** @var array<string,mixed> */
    private array $serverBackup = [];

    protected function setUp(): void
    {
        $this->serverBackup = $_SERVER;
        unset($_SERVER['SSH_USER_KEY_FINGERPRINT'], $_SERVER['SSH_USER_AUTH'], $_SERVER['KEY_FINGERPRINT']);
    }

    protected function tearDown(): void
    {
        // Restore the exact $_SERVER state from before setUp(), then
        // explicitly ensure the env vars we test are absent.
        $_SERVER = $this->serverBackup;
        unset($_SERVER['SSH_USER_KEY_FINGERPRINT'], $_SERVER['SSH_USER_AUTH'], $_SERVER['KEY_FINGERPRINT']);
    }

    private function session(string $user): Session
    {
        return new Session(
            user: $user, clientHost: '127.0.0.1', clientPort: 1, serverHost: '127.0.0.1',
            serverPort: 22, term: 'xterm', cols: 80, rows: 24, tty: null,
            command: null, lang: 'C.UTF-8',
        );
    }

    private function stderr(): array
    {
        $r = fopen('php://memory', 'w+');
        $this->assertNotFalse($r);
        return [$r, fn() => $this->readAll($r)];
    }

    private function readAll($r): string
    {
        rewind($r);
        return (string) stream_get_contents($r);
    }

    public function testAllowsAnyUserWhenListEmpty(): void
    {
        [$err] = $this->stderr();
        $a = new Auth([], [], $err);
        $reached = false;
        $a->handle(Context::background(), $this->session('anybody'), function () use (&$reached): void {
            $reached = true;
        });
        $this->assertTrue($reached);
    }

    public function testRejectsUnknownUser(): void
    {
        [$err, $read] = $this->stderr();
        $a = new Auth(['alice', 'bob'], [], $err);
        $reached = false;
        $a->handle(Context::background(), $this->session('eve'), function () use (&$reached): void {
            $reached = true;
        });
        $this->assertFalse($reached);
        $this->assertStringContainsString('Unauthorized', $read());
        $this->assertStringContainsString('eve',          $read());
    }

    public function testAllowsKnownUser(): void
    {
        [$err] = $this->stderr();
        $a = new Auth(['alice'], [], $err);
        $reached = false;
        $a->handle(Context::background(), $this->session('alice'), function () use (&$reached): void {
            $reached = true;
        });
        $this->assertTrue($reached);
    }

    public function testRejectsWhenFingerprintRequiredButMissing(): void
    {
        [$err, $read] = $this->stderr();
        $a = new Auth([], ['SHA256:abc'], $err);
        $reached = false;
        $a->handle(Context::background(), $this->session('alice'), function () use (&$reached): void {
            $reached = true;
        });
        $this->assertFalse($reached);
        $this->assertStringContainsString('Unauthorized', $read());
    }

    public function testAllowsKnownFingerprint(): void
    {
        $_SERVER['SSH_USER_KEY_FINGERPRINT'] = 'SHA256:goodfinger';
        [$err] = $this->stderr();
        $a = new Auth([], ['SHA256:goodfinger'], $err);
        $reached = false;
        $a->handle(Context::background(), $this->session('alice'), function () use (&$reached): void {
            $reached = true;
        });
        $this->assertTrue($reached);
    }

    public function testAllowsFingerprintFromSshUserAuthBlob(): void
    {
        // SSH_USER_AUTH is an OpenSSH ExposeAuthInfo blob containing the
        // full auth line; fingerprint token must be extracted from it.
        $_SERVER['SSH_USER_AUTH'] = 'publickey ssh-ed25519 SHA256:abcdefghijklmnopqrstuvwxyz1234567890ABCDEFGHIJKLMNOPQRSTU';
        unset($_SERVER['SSH_USER_KEY_FINGERPRINT'], $_SERVER['KEY_FINGERPRINT']);
        [$err] = $this->stderr();
        $a = new Auth([], ['SHA256:abcdefghijklmnopqrstuvwxyz1234567890ABCDEFGHIJKLMNOPQRSTU'], $err);
        $reached = false;
        $a->handle(Context::background(), $this->session('alice'), function () use (&$reached): void {
            $reached = true;
        });
        $this->assertTrue($reached);
    }

    // sugar-ssh-server-001: a real OpenSSH SHA256 fingerprint ends in base64
    // `=` padding. The old regex closed the token with `\b`, and `=` is a
    // non-word char — so the extraction silently dropped the final `=` and
    // hash_equals could never match the operator's stored value. Every
    // legitimate padded key was denied via SSH_USER_AUTH (fails closed, but
    // denies). The fix keeps `=` in the token and uses a negative look-ahead
    // to stop at the token edge without re-introducing truncation.
    public function testAllowsPaddedSha256FingerprintFromSshUserAuthBlob(): void
    {
        $fp = 'SHA256:abcdefghijklmnopqrstuvwxyz1234567890ABCDEFGHIJKLMNOPQRSTUMbXc=';
        $_SERVER['SSH_USER_AUTH'] = 'publickey ssh-ed25519 ' . $fp;
        unset($_SERVER['SSH_USER_KEY_FINGERPRINT'], $_SERVER['KEY_FINGERPRINT']);
        [$err] = $this->stderr();
        $a = new Auth([], [$fp], $err);
        $reached = false;
        $a->handle(Context::background(), $this->session('alice'), function () use (&$reached): void {
            $reached = true;
        });
        $this->assertTrue($reached);
    }

    // The padded token rides inside a multi-line ExposeAuthInfo blob: the
    // extraction must stop at the token edge (`=` followed by the newline,
    // then the next line must not be absorbed into the fingerprint).
    public function testPaddedFingerprintStopsAtTokenEdgeInMultilineBlob(): void
    {
        $fp = 'SHA256:abcdef/Mb+ghij=';
        $_SERVER['SSH_USER_AUTH'] = "publickey ssh-ed25519 {$fp}\nfrom 10.0.0.1 port 2222 tun device";
        unset($_SERVER['SSH_USER_KEY_FINGERPRINT'], $_SERVER['KEY_FINGERPRINT']);
        [$err] = $this->stderr();
        $a = new Auth([], [$fp], $err);
        $reached = false;
        $a->handle(Context::background(), $this->session('alice'), function () use (&$reached): void {
            $reached = true;
        });
        $this->assertTrue($reached);
    }

    // Over-match guard: a blob carrying a LONGER token than the allowlist
    // entry must not extract the shorter prefix as a match (the look-ahead
    // refuses an [A-Za-z0-9+/] continuation).
    public function testLongerPaddedTokenInBlobDoesNotMatchShorterAllowlistPrefix(): void
    {
        $_SERVER['SSH_USER_AUTH'] = 'publickey ssh-ed25519 SHA256:abcdefghijklmnopqrstuvwxyz1234567890ABCDEFGHIJKLMNOPQRSTUvwXY=';
        unset($_SERVER['SSH_USER_KEY_FINGERPRINT'], $_SERVER['KEY_FINGERPRINT']);
        [$err, $readErr] = $this->stderr();
        $a = new Auth([], ['SHA256:abcdefghijklmnopqrstuvwxyz1234567890ABCDEFGHIJKLMNOPQRSTU'], $err);
        $reached = false;
        $a->handle(Context::background(), $this->session('alice'), function () use (&$reached): void {
            $reached = true;
        });
        $this->assertFalse($reached);
        $this->assertStringContainsString('key not allowed', $readErr());
    }

    public function testRejectsMismatchedFingerprintInBlob(): void
    {
        $_SERVER['SSH_USER_AUTH'] = 'publickey ssh-ed25519 SHA256:wrongvalue';
        unset($_SERVER['SSH_USER_KEY_FINGERPRINT'], $_SERVER['KEY_FINGERPRINT']);
        [$err, $readErr] = $this->stderr();
        $a = new Auth([], ['SHA256:expectedfinger'], $err);
        $reached = false;
        $a->handle(Context::background(), $this->session('alice'), function () use (&$reached): void {
            $reached = true;
        });
        $this->assertFalse($reached);
        $this->assertStringContainsString('Unauthorized', $readErr());
        $this->assertStringContainsString('key not allowed', $readErr());
    }

    public function testSshUserAuthBlobWithMd5Fingerprint(): void
    {
        $_SERVER['SSH_USER_AUTH'] = 'publickey ssh-rsa AAAAB3NzaC1yc2EAAAADAQABAAABAQ MD5:1a:2b:3c:4d:5e:6f:1a:2b:3c:4d:5e:6f:1a:2b:3c:4d';
        unset($_SERVER['SSH_USER_KEY_FINGERPRINT'], $_SERVER['KEY_FINGERPRINT']);
        [$err] = $this->stderr();
        $a = new Auth([], ['MD5:1a:2b:3c:4d:5e:6f:1a:2b:3c:4d:5e:6f:1a:2b:3c:4d'], $err);
        $reached = false;
        $a->handle(Context::background(), $this->session('alice'), function () use (&$reached): void {
            $reached = true;
        });
        $this->assertTrue($reached);
    }

    public function testRejectionMessageSanitizesUsernameWithAnsi(): void
    {
        [$err, $readErr] = $this->stderr();
        $a = new Auth(['alice'], [], $err);
        $reached = false;
        // Simulate a username containing ANSI color escape sequences
        $maliciousSession = new Session(
            user: "\x1b[31meve\x1b[0m",
            clientHost: '127.0.0.1', clientPort: 1, serverHost: '127.0.0.1',
            serverPort: 22, term: 'xterm', cols: 80, rows: 24, tty: null,
            command: null, lang: 'C.UTF-8',
        );
        $a->handle(Context::background(), $maliciousSession, function () use (&$reached): void {
            $reached = true;
        });
        $this->assertFalse($reached);
        $output = $readErr();
        // The ESC bytes must not appear in stderr output
        $this->assertStringNotContainsString("\x1b", $output);
        // The literal 'eve' characters should be present; the escape
        // sequences themselves are removed, not masked in place
        // (audit MINOR 1 — the hand-rolled pass replaced them with '?').
        $this->assertStringContainsString('eve', $output);
        $this->assertStringNotContainsString('?', $output);
    }

    public function testANameWithRealAccentsArrivesUnharmed(): void
    {
        [$err, $readErr] = $this->stderr();
        $a = new Auth(['alice'], [], $err);
        $reached = false;
        $a->handle(Context::background(), new Session(
            // 'O'Brien' with a typographic apostrophe (U+2019), plus a
            // plain accented name as the control that the old pass got
            // right by luck: U+00E9's bytes sit outside \x7f-\x9f.
            user: "O\u{2019}Brien caf\xc3\xa9",
            clientHost: '127.0.0.1', clientPort: 1, serverHost: '127.0.0.1',
            serverPort: 22, term: 'xterm', cols: 80, rows: 24, tty: null,
            command: null, lang: 'C.UTF-8',
        ), function () use (&$reached): void {
            $reached = true;
        });

        $this->assertFalse($reached);
        $output = $readErr();
        // The old byte-wise range deleted continuation bytes, not
        // characters: the apostrophe arrived as U+FFFD plus two '?', and
        // the result was invalid UTF-8 — enough on its own to make the
        // logger's json_encode refuse the record.
        $this->assertStringContainsString("O\u{2019}Brien caf\xc3\xa9", $output);
        $this->assertTrue(\mb_check_encoding($output, 'UTF-8'), 'the notice must stay encodable: ' . \bin2hex($output));
        $this->assertStringNotContainsString('?', $output);
    }

    public function testAnOscPayloadIsStrippedRatherThanEchoedAsText(): void
    {
        [$err, $readErr] = $this->stderr();
        $a = new Auth(['alice'], [], $err);
        $reached = false;
        // ESC ] 0 ; … BEL — the CSI-only second pass never matched this,
        // and the first pass left the payload behind as plain text.
        $a->handle(Context::background(), new Session(
            user: 'a' . "\x1b" . ']0;evil' . "\x07" . 'b',
            clientHost: '127.0.0.1', clientPort: 1, serverHost: '127.0.0.1',
            serverPort: 22, term: 'xterm', cols: 80, rows: 24, tty: null,
            command: null, lang: 'C.UTF-8',
        ), function () use (&$reached): void {
            $reached = true;
        });

        $this->assertFalse($reached);
        $output = $readErr();
        $this->assertStringNotContainsString('evil', $output);
        $this->assertStringNotContainsString("\x1b", $output);
        $this->assertStringContainsString('ab', $output);
    }

    public function testAPeerAssertedNameCannotForgeAnExtraLine(): void
    {
        [$err, $readErr] = $this->stderr();
        $a = new Auth(['alice'], [], $err);
        $reached = false;
        $a->handle(Context::background(), new Session(
            user: "mallory\nsecond line",
            clientHost: '127.0.0.1', clientPort: 1, serverHost: '127.0.0.1',
            serverPort: 22, term: 'xterm', cols: 80, rows: 24, tty: null,
            command: null, lang: 'C.UTF-8',
        ), function () use (&$reached): void {
            $reached = true;
        });

        $this->assertFalse($reached);
        $output = $readErr();
        // Sanitize::untrusted() keeps newlines on purpose (it is a display
        // sanitizer); a rejection notice may not. One trailing newline, no
        // matter what the name contained.
        $this->assertSame(1, \substr_count($output, "\n"));
        $this->assertStringContainsString('mallory second line', $output);
    }

    public function testAnOverlongNameIsBoundedInTheNotice(): void
    {
        [$err, $readErr] = $this->stderr();
        $a = new Auth(['alice'], [], $err);
        $reached = false;
        $a->handle(Context::background(), new Session(
            user: \str_repeat('x', 5000),
            clientHost: '127.0.0.1', clientPort: 1, serverHost: '127.0.0.1',
            serverPort: 22, term: 'xterm', cols: 80, rows: 24, tty: null,
            command: null, lang: 'C.UTF-8',
        ), function () use (&$reached): void {
            $reached = true;
        });

        $this->assertFalse($reached);
        $output = $readErr();
        // The name stops at the cap and the cut is marked; the wrapper
        // ('Unauthorized. (user not allowed: …)' plus a newline) is the
        // only thing past it.
        $this->assertStringContainsString(\str_repeat('x', Auth::MAX_ECHOED_BYTES - 1) . "\u{2026}", $output);
        $this->assertStringNotContainsString(\str_repeat('x', Auth::MAX_ECHOED_BYTES), $output);
        $this->assertLessThanOrEqual(
            \strlen('user not allowed: ') + Auth::MAX_ECHOED_BYTES + 32,
            \strlen($output),
            'a 5000-byte asserted name must not arrive whole in the notice',
        );
    }

    public function testRejectionMessageSanitizesFingerprintWithAnsi(): void
    {
        $_SERVER['SSH_USER_KEY_FINGERPRINT'] = "SSH-FP\x1b[31m:bad\x1b[0m";
        [$err, $readErr] = $this->stderr();
        $a = new Auth([], ['SSH-FP:bad'], $err);
        $reached = false;
        $a->handle(Context::background(), $this->session('alice'), function () use (&$reached): void {
            $reached = true;
        });
        $this->assertFalse($reached);
        $output = $readErr();
        $this->assertStringNotContainsString("\x1b", $output);
    }

    public function testValidatorRejectsFingerprintThatMatchesAllowlist(): void
    {
        // The (forgeable) fingerprint matches the allowlist, so the
        // allowlist alone would admit. A wired validator that refuses
        // must still deny — this is the trust-gap closure.
        $_SERVER['SSH_USER_KEY_FINGERPRINT'] = 'SHA256:forged';
        [$err, $readErr] = $this->stderr();
        $a = new Auth([], ['SHA256:forged'], $err, fn (string $fp, Session $s): bool => false);
        $reached = false;
        $a->handle(Context::background(), $this->session('alice'), function () use (&$reached): void {
            $reached = true;
        });
        $this->assertFalse($reached, 'a validator returning false must deny even when the allowlist matches');
        $this->assertStringContainsString('key validation failed', $readErr());
    }

    public function testValidatorAcceptsWhenItVouchesForKey(): void
    {
        $_SERVER['SSH_USER_KEY_FINGERPRINT'] = 'SHA256:real';
        [$err] = $this->stderr();
        $seen = null;
        $a = new Auth([], ['SHA256:real'], $err, function (string $fp, Session $s) use (&$seen): bool {
            $seen = $fp;
            return $s->user === 'alice';
        });
        $reached = false;
        $a->handle(Context::background(), $this->session('alice'), function () use (&$reached): void {
            $reached = true;
        });
        $this->assertTrue($reached);
        $this->assertSame('SHA256:real', $seen, 'validator receives the presented fingerprint');
    }

    public function testValidatorGatesEvenWithEmptyAllowlist(): void
    {
        // No allowlist, but a validator is wired: the validator becomes
        // the sole fingerprint gate.
        $_SERVER['SSH_USER_KEY_FINGERPRINT'] = 'SHA256:whatever';
        [$err] = $this->stderr();
        $a = new Auth([], [], $err, fn (string $fp, Session $s): bool => false);
        $reached = false;
        $a->handle(Context::background(), $this->session('alice'), function () use (&$reached): void {
            $reached = true;
        });
        $this->assertFalse($reached, 'validator-only mode must deny when the validator refuses');
    }

    // ---- audit K4: the two flat lists are a cross product, userKeys pairs ----

    public function testFlatListsAdmitTheCrossProductThisIsTheAuditedShape(): void
    {
        // DOCUMENTATION-BEARING PIN (K4): with `users` and
        // `keyFingerprints` as independent flat lists, bob holding alice's
        // key IS admitted, because neither list encodes whose key it is.
        // This test exists so the shape cannot be "fixed" silently, and so
        // the pairing test below is meaningful by contrast.
        $_SERVER['SSH_USER_KEY_FINGERPRINT'] = 'SHA256:alices-key';
        [$err] = $this->stderr();
        $a = new Auth(['alice', 'bob'], ['SHA256:alices-key', 'SHA256:bobs-key'], $err);
        $reached = false;
        $a->handle(Context::background(), $this->session('bob'), function () use (&$reached): void {
            $reached = true;
        });
        $this->assertTrue($reached, 'flat lists are uncorrelated by design — see the class docblock');
    }

    public function testUserKeysRefusesOneUsersKeyForAnotherName(): void
    {
        // The cure: alice's key, presented by bob, is refused.
        $_SERVER['SSH_USER_KEY_FINGERPRINT'] = 'SHA256:alices-key';
        [$err, $readErr] = $this->stderr();
        $a = new Auth(userKeys: ['alice' => ['SHA256:alices-key'], 'bob' => ['SHA256:bobs-key']], stderr: $err);
        $reached = false;
        $a->handle(Context::background(), $this->session('bob'), function () use (&$reached): void {
            $reached = true;
        });
        $this->assertFalse($reached, 'a key paired to alice must not authenticate bob');
        $this->assertStringContainsString('key not paired with the requested user', $readErr());
    }

    public function testUserKeysAdmitsTheActualOwner(): void
    {
        $_SERVER['SSH_USER_KEY_FINGERPRINT'] = 'SHA256:alices-key';
        [$err, $readErr] = $this->stderr();
        $a = new Auth(userKeys: ['alice' => ['SHA256:alices-key'], 'bob' => ['SHA256:bobs-key']], stderr: $err);
        $reached = false;
        $a->handle(Context::background(), $this->session('alice'), function () use (&$reached): void {
            $reached = true;
        });
        $this->assertTrue($reached);
        $this->assertSame('', $readErr(), 'the admitted path writes nothing');
    }

    public function testUserKeysRefusesANameWithNoEntryEvenWithAKnownKey(): void
    {
        // carol is unknown to the map even though her key appears under
        // no one else either: an absent name is a closed door, not an open
        // one, once the pairing map is armed.
        $_SERVER['SSH_USER_KEY_FINGERPRINT'] = 'SHA256:carols-key';
        [$err, $readErr] = $this->stderr();
        $a = new Auth(userKeys: ['alice' => ['SHA256:alices-key']], stderr: $err);
        $reached = false;
        $a->handle(Context::background(), $this->session('carol'), function () use (&$reached): void {
            $reached = true;
        });
        $this->assertFalse($reached);
        $this->assertStringContainsString('carol', $readErr());
    }

    public function testUserKeysRefusesAKeyThatIsNoones(): void
    {
        $_SERVER['SSH_USER_KEY_FINGERPRINT'] = 'SHA256:strangers-key';
        [$err] = $this->stderr();
        $a = new Auth(userKeys: ['alice' => ['SHA256:alices-key']], stderr: $err);
        $reached = false;
        $a->handle(Context::background(), $this->session('alice'), function () use (&$reached): void {
            $reached = true;
        });
        $this->assertFalse($reached, 'a name listed in the map is not a blank cheque for any key');
    }

    public function testUserKeysRefusesWhenNoKeyWasPresentedAtAll(): void
    {
        unset($_SERVER['SSH_USER_KEY_FINGERPRINT'], $_SERVER['KEY_FINGERPRINT'], $_SERVER['SSH_USER_AUTH']);
        [$err, $readErr] = $this->stderr();
        $a = new Auth(userKeys: ['alice' => ['SHA256:alices-key']], stderr: $err);
        $reached = false;
        $a->handle(Context::background(), $this->session('alice'), function () use (&$reached): void {
            $reached = true;
        });
        $this->assertFalse($reached);
        $this->assertStringContainsString('<missing>', $readErr());
    }

    public function testAnyKeySentinelOpensANameToEveryKeyButOnlyThatName(): void
    {
        // Auth::ANY_KEY is the honest way to say "this deployment has no
        // key evidence for bob". It must not leak to other names.
        $_SERVER['SSH_USER_KEY_FINGERPRINT'] = 'SHA256:strangers-key';
        [$err] = $this->stderr();
        $paired = new Auth(userKeys: ['alice' => [Auth::ANY_KEY], 'bob' => ['SHA256:bobs-key']], stderr: $err);
        $reached = false;
        $paired->handle(Context::background(), $this->session('alice'), function () use (&$reached): void {
            $reached = true;
        });
        $this->assertTrue($reached, 'the sentinel admits alice with any key');

        $reachedBob = false;
        $paired->handle(Context::background(), $this->session('bob'), function () use (&$reachedBob): void {
            $reachedBob = true;
        });
        $this->assertFalse($reachedBob, 'the sentinel is scoped to its own name');
    }

    public function testUserKeysNarrowsRatherThanReplacesTheFlatLists(): void
    {
        // The flat key list passes (the key is known), the flat user list
        // passes (the name is known), the pairing refuses: combining
        // surfaces can only tighten.
        $_SERVER['SSH_USER_KEY_FINGERPRINT'] = 'SHA256:alices-key';
        [$err] = $this->stderr();
        $a = new Auth(
            users: ['alice', 'bob'],
            keyFingerprints: ['SHA256:alices-key'],
            userKeys: ['bob' => ['SHA256:alices-key']],
            stderr: $err,
        );
        $reached = false;
        $a->handle(Context::background(), $this->session('alice'), function () use (&$reached): void {
            $reached = true;
        });
        $this->assertFalse($reached, 'alice is not in the pairing map, so she is refused');

        $reachedBob = false;
        $a->handle(Context::background(), $this->session('bob'), function () use (&$reachedBob): void {
            $reachedBob = true;
        });
        $this->assertTrue($reachedBob, 'bob is paired to exactly this key');
    }

    public function testFingerprintMembershipIsComparedWithoutAnEarlyOut(): void
    {
        // Structural pin (audit M2/hygiene): the allowlist is a set of
        // authorisation secrets, so membership must go through
        // hash_equals() over every entry rather than in_array(), which
        // returns on the first match and makes position measurable.
        $source       = (string) file_get_contents(__DIR__ . '/../../src/Middleware/Auth.php');
        $this->assertNotFalse($source);
        $start = strpos($source, 'private function listContains(');
        $this->assertIsInt($start, 'the membership helper must exist');
        $body = (string) substr($source, $start, (int) strpos(substr($source, $start), "\n    }") + 6);
        $this->assertStringContainsString('hash_equals(', $body);
        $this->assertStringNotContainsString('in_array(', $body, 'no early-out membership test');
        $this->assertStringNotContainsString('return true;', $body, 'a match may not short-circuit the walk');
        $this->assertStringNotContainsString('in_array($fp', $source, 'the fingerprint lists must never be scanned with an early-out');
    }
}
