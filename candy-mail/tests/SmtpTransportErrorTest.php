<?php

declare(strict_types=1);

namespace SugarCraft\Mail\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Mail\Email;
use SugarCraft\Mail\Lang;
use SugarCraft\Mail\SmtpTransport;

/**
 * Edge case tests for SmtpTransport error paths.
 */
final class SmtpTransportErrorTest extends TestCase
{
    public function testSendRawThrowsWhenNotConnected(): void
    {
        $transport = new SmtpTransport('smtp.example.com', 587);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Not connected');

        $this->invokePrivate($transport, 'sendRaw', ["TEST\r\n"]);
    }

    public function testReadResponseThrowsWhenNotConnected(): void
    {
        $transport = new SmtpTransport('smtp.example.com', 587);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Not connected');

        $this->invokePrivate($transport, 'readResponse', [220]);
    }

    public function testQuitDoesNotThrowWhenNotConnected(): void
    {
        $transport = new SmtpTransport('smtp.example.com', 587);

        // quit() should be safe to call when not connected
        $this->invokePrivate($transport, 'quit');

        // Reaching here is the assertion; pin it honestly: no socket was
        // conjured up by the early-return path (m11: was assertTrue(true)).
        $socket = new \ReflectionProperty($transport, 'socket');
        $socket->setAccessible(true);
        $this->assertNull($socket->getValue($transport));
    }

    public function testQuitSendsQuitAndDisconnects(): void
    {
        // Connected-path quit() over a socketpair pre-loaded with the server's
        // 221 farewell: the goodbye is written, the reply is consumed, and the
        // socket is released. (m11: was assertTrue(true) on a not-connected
        // duplicate of the test above.)
        $pair = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        $this->assertIsArray($pair);
        [$client, $server] = $pair;

        try {
            \fwrite($server, "221 bye\r\n");
            $transport = new SmtpTransport('smtp.example.com', 587);

            $socketProp = new \ReflectionProperty($transport, 'socket');
            $socketProp->setAccessible(true);
            $socketProp->setValue($transport, $client);

            $this->invokePrivate($transport, 'quit');

            $this->assertNull($socketProp->getValue($transport), 'quit() must release the socket');
            $this->assertSame("QUIT\r\n", \fgets($server), 'quit() must bid farewell over the wire');
        } finally {
            // quit() already fclose()d the client handle.
            if (\is_resource($client)) {
                @\fclose($client);
            }
            @\fclose($server);
        }
    }

    public function testDisconnectIsIdempotent(): void
    {
        $transport = new SmtpTransport('smtp.example.com', 587);
        $sock      = \fopen('php://memory', 'r+');

        $socketProp = new \ReflectionProperty($transport, 'socket');
        $socketProp->setAccessible(true);
        $socketProp->setValue($transport, $sock);

        // Calling disconnect twice should not throw — and the second call sees
        // the nulled socket (belt-braces: encryption belief dies with it).
        $this->invokePrivate($transport, 'disconnect');
        $this->assertNull($socketProp->getValue($transport));
        $this->invokePrivate($transport, 'disconnect');

        $encrypted = new \ReflectionProperty($transport, 'encrypted');
        $encrypted->setAccessible(true);
        $this->assertFalse($encrypted->getValue($transport));
    }

    public function testConnectTargetWithImplicitTls(): void
    {
        $transport = new SmtpTransport('smtp.example.com', 465);

        $target = $this->invokePrivate($transport, 'connectTarget');
        $this->assertSame('tls://smtp.example.com:465', $target);
    }

    public function testConnectTargetWithoutImplicitTls(): void
    {
        $transport = new SmtpTransport('smtp.example.com', 587);

        $target = $this->invokePrivate($transport, 'connectTarget');
        $this->assertSame('tcp://smtp.example.com:587', $target);
    }

    public function testConnectTargetWithImplicitTlsOverride(): void
    {
        $transport = (new SmtpTransport('smtp.example.com', 587))->withImplicitTls();

        $target = $this->invokePrivate($transport, 'connectTarget');
        $this->assertSame('tls://smtp.example.com:587', $target);
    }

    public function testAuthenticateIfNeededSkipsWhenNoCredentials(): void
    {
        $transport = new SmtpTransport('smtp.example.com', 587, '', '');

        // Should not throw, just return — and prove it touched no wire: any
        // fall-through would have hit sendRaw with a null socket and thrown
        // (m11: was assertTrue(true)).
        $this->invokePrivate($transport, 'authenticateIfNeeded');

        $socket = new \ReflectionProperty($transport, 'socket');
        $socket->setAccessible(true);
        $this->assertNull($socket->getValue($transport));
    }

    public function testConstructorRefusesPasswordWithoutUsername(): void
    {
        // A half-set pair used to no-op authenticateIfNeeded() in silence —
        // the mail would depart unauthenticated while the operator believed
        // AUTH would run. Construction now refuses it, named, up front.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(Lang::t('smtp.incomplete_credentials'));

        new SmtpTransport('smtp.example.com', 587, '', 'password');
    }

    public function testConstructorRefusesUsernameWithoutPassword(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(Lang::t('smtp.incomplete_credentials'));

        new SmtpTransport('smtp.example.com', 587, 'username', '');
    }

    public function testStartTlsIfNeededSkipsWhenAlreadyEncrypted(): void
    {
        // Create transport with implicit TLS (already encrypted at connect time)
        $transport = new SmtpTransport('smtp.example.com', 465);

        // Set encrypted flag manually via reflection
        $prop = new \ReflectionProperty($transport, 'encrypted');
        $prop->setAccessible(true);
        $prop->setValue($transport, true);

        // Should return early without trying STARTTLS: the flag must survive
        // untouched and no sendRaw may fire (a socketless fall-through would
        // have thrown). (m11: was assertTrue(true).)
        $this->invokePrivate($transport, 'startTlsIfNeeded');

        $this->assertTrue($prop->getValue($transport));
        $socket = new \ReflectionProperty($transport, 'socket');
        $socket->setAccessible(true);
        $this->assertNull($socket->getValue($transport));
    }

    public function testStartTlsIfNeededSkipsWhenStarttlsNotAvailable(): void
    {
        $transport = (new SmtpTransport('smtp.example.com', 587))->withRequireTls(false);

        // Set lastResponse to indicate no STARTTLS
        $prop = new \ReflectionProperty($transport, 'lastResponse');
        $prop->setAccessible(true);
        $prop->setValue($transport, "220 server ready\r\n250-foo\r\n250 HELP\r\n");

        // TLS was explicitly waived (default is requireTls=true since S-1),
        // so the missing STARTTLS is a no-op, not a throw — and the channel
        // stays plaintext (no encrypted=true lie). (m11: was assertTrue(true).)
        $this->invokePrivate($transport, 'startTlsIfNeeded');

        $encrypted = new \ReflectionProperty($transport, 'encrypted');
        $encrypted->setAccessible(true);
        $this->assertFalse($encrypted->getValue($transport));
        $socket = new \ReflectionProperty($transport, 'socket');
        $socket->setAccessible(true);
        $this->assertNull($socket->getValue($transport));
    }

    public function testStartTlsIfNeededThrowsWhenTlsRequiredButStarttlsNotAvailable(): void
    {
        $transport = (new SmtpTransport('smtp.example.com', 587))->withRequireTls();

        // Set lastResponse to indicate no STARTTLS
        $prop = new \ReflectionProperty($transport, 'lastResponse');
        $prop->setAccessible(true);
        $prop->setValue($transport, "220 server ready\r\n250-foo\r\n250 HELP\r\n");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('TLS is required');

        $this->invokePrivate($transport, 'startTlsIfNeeded');
    }

    private function invokePrivate(object $obj, string $method, array $args = []): mixed
    {
        $ref = new \ReflectionMethod($obj, $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs($obj, $args);
    }

    public function testSendRefusesAnEmptySenderBeforeDialling(): void
    {
        $transport = new SmtpTransport('smtp.invalid.example', 587);

        // Validation, not connectivity: an InvalidArgumentException (empty
        // envelope) must win over the RuntimeException the doomed dial would
        // raise, proving the guard runs before connect().
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('from address');

        $transport->send(new Email(from: [], to: ['a@x.com']));
    }

    public function testSendRefusesAnEmptyRecipientListBeforeDialling(): void
    {
        $transport = new SmtpTransport('smtp.invalid.example', 587);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('recipient');

        $transport->send(new Email(from: ['a@x.com'], to: []));
    }

    /**
     * CM-44: a stalled read returns false (on this platform) or '' (on other
     * builds) with timed_out set — a hung firewall, not an empty reply line.
     * The classification (and therefore the operator's diagnosis) must follow
     * the socket's confession. Against the old bytes this pins RED: the stall
     * threw no_response, indistinguishable from a closed peer.
     */
    public function testStalledReadIsClassifiedAsTimeoutNotEmptyResponse(): void
    {
        $pair = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        $this->assertIsArray($pair);
        [$client, $server] = $pair;

        try {
            $transport = new SmtpTransport('smtp.example.com', 587, timeout: 1);
            $socketProp = new \ReflectionProperty($transport, 'socket');
            $socketProp->setAccessible(true);
            $socketProp->setValue($transport, $client);

            $start = \microtime(true);
            try {
                $this->invokePrivate($transport, 'readResponseAny', [[250]]);
                $this->fail('a silent peer must abort the read');
            } catch (\RuntimeException $e) {
                $this->assertSame(Lang::t('smtp.response_timeout'), $e->getMessage(), 'stall must be reported as a timeout, never as an empty response');
            }
            $this->assertLessThan(3.5, \microtime(true) - $start, 'the 1s budget must be honoured, not stretched');
        } finally {
            @\fclose($client);
            @\fclose($server);
        }
    }

    /** CM-44 guard arm: a bare newline that really arrived stays empty_response. */
    public function testEmptyLineReplyStillClassifiesAsEmptyResponse(): void
    {
        $pair = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        $this->assertIsArray($pair);
        [$client, $server] = $pair;

        try {
            \fwrite($server, "\n");
            $transport = new SmtpTransport('smtp.example.com', 587, timeout: 5);
            $socketProp = new \ReflectionProperty($transport, 'socket');
            $socketProp->setAccessible(true);
            $socketProp->setValue($transport, $client);

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage(Lang::t('smtp.empty_response'));
            $this->invokePrivate($transport, 'readResponseAny', [[250]]);
        } finally {
            @\fclose($client);
            @\fclose($server);
        }
    }

    /**
     * CM-44: every read re-arms the remaining budget. The socket arrives
     * pre-armed with a gross 30s timeout (as a stale connect() arm or an
     * inherited default would be); the transport's 1s budget must still win:
     * the read aborts within a couple of seconds, named as a timeout. The old
     * code armed once and trusted forever — against those bytes this read
     * blocks the full 30s and the elapsed bound fails (and the stale no-
     * timed_out classification reddens the message assertion).
     */
    public function testStalledReadRespectsRemainingBudgetNotStaleArm(): void
    {
        $pair = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        $this->assertIsArray($pair);
        [$client, $server] = $pair;

        try {
            \stream_set_timeout($client, 30);

            $transport = new SmtpTransport('smtp.example.com', 587, timeout: 1);
            $socketProp = new \ReflectionProperty($transport, 'socket');
            $socketProp->setAccessible(true);
            $socketProp->setValue($transport, $client);

            $start = \microtime(true);
            try {
                $this->invokePrivate($transport, 'readResponseAny', [[250]]);
                $this->fail('a silent peer must abort the read');
            } catch (\RuntimeException $e) {
                $this->assertSame(Lang::t('smtp.response_timeout'), $e->getMessage());
            }
            $this->assertLessThan(4.0, \microtime(true) - $start, 'the re-armed per-read budget must cut the stale 30s arm down to size');
        } finally {
            @\fclose($client);
            @\fclose($server);
        }
    }
}
