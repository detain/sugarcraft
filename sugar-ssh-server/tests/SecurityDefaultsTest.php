<?php

declare(strict_types=1);

namespace SugarCraft\SshServer\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\SshServer\Context;
use SugarCraft\SshServer\Middleware;
use SugarCraft\SshServer\Middleware\Auth;
use SugarCraft\SshServer\Middleware\Auth\KeyboardInteractive;
use SugarCraft\SshServer\Middleware\RateLimit;
use SugarCraft\SshServer\Server;
use SugarCraft\SshServer\Session;

/**
 * Four shipped defaults admit traffic: an `Auth` with no lists, a
 * `KeyboardInteractive` with no validator, a `RateLimit` in its default
 * fail-open posture, and a `Server` that runs whatever stack it is given.
 *
 * Each one is a deliberate choice -- the alternative is a middleware that
 * locks every user out the moment someone forgets an argument -- but the
 * audit (critical K5) found them stated only in long docblocks and in the
 * admin guide, and nowhere on the surface a reader actually skims. The
 * README now tabulates them; these tests hold the tabulation to the code
 * it describes. If a default ever flips, the table has to flip in the same
 * commit and one of these five goes red first.
 */
final class SecurityDefaultsTest extends TestCase
{
    /** @var list<string> */
    private array $planted = [];

    protected function tearDown(): void
    {
        foreach ($this->planted as $path) {
            @unlink($path);
        }
        $this->planted = [];
    }

    public function testAuthWithNoListsAdmitsTheSession(): void
    {
        $reached = false;
        (new Auth())->handle(
            Context::background(),
            $this->someone('anyone', '198.51.100.7'),
            function () use (&$reached): void {
                $reached = true;
            }
        );

        $this->assertTrue($reached, 'an Auth with neither user list nor key list must let the chain through');
    }

    public function testKeyboardInteractiveWithoutAValidatorAdmitsWhateverWasTyped(): void
    {
        $stdin = $this->pipeInput("hunter2\nanything\n");
        $stdout = $this->pipeInput('');
        $reached = false;

        (new KeyboardInteractive(
            [['prompt' => 'Password?', 'echo' => false], ['prompt' => 'Token?']],
            null,
            stdout: $stdout,
            stdin: $stdin
        ))->handle(
            Context::background(),
            $this->someone('anyone', '198.51.100.7'),
            function () use (&$reached): void {
                $reached = true;
            }
        );

        $this->assertTrue($reached, 'with no validator the exchange is a form, not a check');
    }

    public function testRateLimitLetsTrafficThroughWhenItsStateIsUnreadable(): void
    {
        // A path whose parent is a regular file: ENOTDIR for every privilege
        // level, so this stays the fail-open shape on a root runner too.
        $blocker = \sys_get_temp_dir() . '/wish-security-defaults-blocked-' . \uniqid('', true);
        $this->assertNotFalse(\file_put_contents($blocker, ''));
        $this->planted[] = $blocker;

        $notice = \fopen('php://memory', 'w+');
        $this->assertNotFalse($notice);
        $this->plantedStreams[] = $notice;

        $allowed = 0;
        (new RateLimit($blocker . '/nested/state.json', stderr: $notice))
            ->handle(
                Context::background(),
                $this->someone('anyone', '198.51.100.7'),
                function () use (&$allowed): void {
                    $allowed++;
                }
            );

        $this->assertSame(1, $allowed, 'failClosed defaults to false, so a broken state file must not deny');

        // The other half of the same default: the prober is told. Under
        // ForceCommand the stream this writes to is the connecting client's
        // extended-data channel, which is exactly why the notice carries no
        // path -- see the Logger sink note in the README.
        \rewind($notice);
        $this->assertStringContainsString('fail-open', (string) \stream_get_contents($notice));
    }

    public function testServerRunsOnlyTheStackItIsGivenAndAnEmptyStackReturnsZero(): void
    {
        // Nothing gates on your behalf: an empty stack is not a deny, and a
        // stack without an Auth middleware is not a deny either. Both answer
        // 0, which is how a `ssh host backup.sh` reports success for a server
        // that authenticated nobody. What `0` no longer covers is a refusal:
        // since audit MAJOR 9 a gate that says no marks the session and the
        // walk answers `Server::EXIT_DENIED` instead -- see
        // tests/ExitStatusTest.php for that half of the contract.
        // 076 made the constructor private; `::new()` is the door here too.
        $this->assertSame(0, Server::new()->serve(), 'an empty stack reports success -- it is reporting that nothing ran');

        $seen = (object) ['ran' => false];
        $marker = new class($seen) implements Middleware {
            public function __construct(private readonly \stdClass $seen)
            {
            }

            public function handle(Context $ctx, Session $session, callable $next)
            {
                $this->seen->ran = true;
                $next($ctx, $session);
            }
        };

        $this->assertSame(0, Server::new()->use($marker)->serve());
        $this->assertTrue($seen->ran, 'the stack you give Server is the stack that runs');
    }

    public function testTheReadmeTabulatesAllFourOpenDefaults(): void
    {
        $readme = (string) \file_get_contents(__DIR__ . '/../README.md');
        // Markdown wraps mid-phrase, so compare on a whitespace-flattened copy.
        $flat = (string) \preg_replace('/\s+/', ' ', $readme);

        $this->assertStringContainsString(
            '## Defaults that are open until you close them',
            $readme,
            'The open defaults need a heading a skimmer meets, not only a docblock.'
        );
        foreach ([
            'new Auth()` has no user list and no fingerprint list',
            'No `$validate` callable means',
            '`failClosed` is `false`',
            'no auth is inserted for you',
        ] as $claim) {
            $this->assertStringContainsString(
                $claim,
                $flat,
                'README table must state the default this file proves: ' . $claim
            );
        }
    }

    private function someone(string $user, string $clientHost): Session
    {
        return new Session(
            user: $user, clientHost: $clientHost, clientPort: 4141, serverHost: '192.0.2.1',
            serverPort: 22, term: 'xterm-256color', cols: 80, rows: 24, tty: null,
            command: null, lang: 'C.UTF-8',
        );
    }

    /** @param resource $handle */
    private function pipeInput(string $contents)
    {
        $handle = \fopen('php://memory', 'w+');
        $this->assertNotFalse($handle);
        \fwrite($handle, $contents);
        \rewind($handle);
        $this->plantedStreams[] = $handle;

        return $handle;
    }

    /** @var list<resource> */
    private array $plantedStreams = [];
}
