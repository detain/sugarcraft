<?php

declare(strict_types=1);

namespace SugarCraft\Mail\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Mail\Email;
use SugarCraft\Mail\Attachment;
use SugarCraft\Mail\SmtpTransport;

/**
 * Regression tests for the SMTP transport TLS hardening:
 *  - implicit TLS (SMTPS/465) connects encrypted BEFORE any EHLO;
 *  - TLS is required by DEFAULT: a missing/failed STARTTLS upgrade aborts the
 *    send instead of degrading to plaintext;
 *  - AUTH (LOGIN or PLAIN) is refused on any cleartext channel, even with
 *    TLS waived;
 *  - the AUTH advertisement list is honoured: SASL PLAIN preferred, LOGIN
 *    fallback, unusable lists refused rather than guessed;
 *  - withCaFile() threads a custom CA bundle into the ssl context;
 *  - the SMTP password is redacted from __debugInfo().
 *
 * The dialogue tests fork a tiny loopback fake-SMTP server bound to an
 * ephemeral 127.0.0.1 port with short timeouts, so they cannot hang CI.
 */
final class SmtpSecurityTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Fix 1 — implicit TLS on 465 (connect encrypted before EHLO)
    // -------------------------------------------------------------------------

    public function testImplicitTlsPort465SelectsTlsTargetBeforeEhlo(): void
    {
        $transport = new SmtpTransport('smtp.example.com', 465);

        // The connect target uses the tls:// scheme, so stream_socket_client()
        // completes the TLS handshake at connect — no plaintext EHLO can precede
        // crypto on port 465.
        $this->assertSame('tls://smtp.example.com:465', $this->invokePrivate($transport, 'connectTarget'));
        $this->assertTrue($this->readProperty($transport, 'implicitTls'));
    }

    public function testNon465SelectsPlaintextTarget(): void
    {
        $transport = new SmtpTransport('smtp.example.com', 587);

        $this->assertSame('tcp://smtp.example.com:587', $this->invokePrivate($transport, 'connectTarget'));
        $this->assertFalse($this->readProperty($transport, 'implicitTls'));
    }

    public function testWithImplicitTlsUpgradesTargetOnNonSmtpsPort(): void
    {
        $transport = (new SmtpTransport('smtp.example.com', 2525))->withImplicitTls();

        $this->assertSame('tls://smtp.example.com:2525', $this->invokePrivate($transport, 'connectTarget'));
        $this->assertTrue($this->readProperty($transport, 'implicitTls'));
    }

    public function testImplicitTlsContextVerifiesPeer(): void
    {
        $transport = new SmtpTransport('smtp.example.com', 465);

        $options = $this->invokePrivate($transport, 'streamContextOptions');

        // Peer verification must stay ON — verify_peer is never blanket-disabled.
        $this->assertTrue($options['ssl']['verify_peer']);
        $this->assertTrue($options['ssl']['verify_peer_name']);
        $this->assertSame('smtp.example.com', $options['ssl']['peer_name']);

        // CM-05 protocol floor: the implicit connect negotiates TLS 1.2|1.3
        // only — SSLv3/TLS 1.0/1.1 cannot settle on this channel.
        $this->assertSame(
            \STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT,
            $options['ssl']['min_proto_version'],
            'implicit-TLS context must floor the protocol at TLS 1.2'
        );
        $this->assertSame(
            \STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | \STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT,
            $options['ssl']['crypto_method'],
            'implicit-TLS context must offer exactly the 1.2|1.3 bitmask'
        );
        // The same floor legs feed the pre-STARTTLS option block (CM-05):
        // one source, both paths.
        $this->assertSame(
            ['min_proto_version' => \STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT,
             'crypto_method'     => \STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | \STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT],
            $this->invokePrivate($transport, 'sslFloorOptions'),
        );
    }

    public function testPlaintextContextHasNoSslBlock(): void
    {
        $transport = new SmtpTransport('smtp.example.com', 587);

        $options = $this->invokePrivate($transport, 'streamContextOptions');

        $this->assertArrayNotHasKey('ssl', $options);
    }

    // -------------------------------------------------------------------------
    // Fix 2 — STARTTLS only when advertised; requireTls guards downgrade
    // -------------------------------------------------------------------------

    public function testRequireTlsThrowsWhenStarttlsNotOffered(): void
    {
        $this->requireFork();

        [$port, $pid, $log] = $this->forkFakeServer(advertiseStartTls: false);

        try {
            $transport = (new SmtpTransport('127.0.0.1', $port, '', '', 2))->withRequireTls();

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('TLS is required');
            $transport->send($this->sampleEmail());
        } finally {
            $this->reap($pid);
            @\unlink($log);
        }
    }

    public function testStarttlsSkippedWhenNotAdvertised(): void
    {
        $this->requireFork();

        [$port, $pid, $log] = $this->forkFakeServer(advertiseStartTls: false);

        try {
            // Opt-out (requireTls=false after docs-fix-1023 S-1 made TLS the
            // default) and no credentials: the send must complete in plaintext
            // without ever issuing STARTTLS.
            $transport = (new SmtpTransport('127.0.0.1', $port, '', '', 2))->withRequireTls(false);
            $transport->send($this->sampleEmail());

            $this->reap($pid);
            $dialogue = (string) \file_get_contents($log);

            $this->assertStringContainsString('MAIL FROM', $dialogue);
            $this->assertStringNotContainsString('STARTTLS', $dialogue);
        } finally {
            $this->reap($pid);
            @\unlink($log);
        }
    }

    // -------------------------------------------------------------------------
    // Fix 2b — TLS required by default + AUTH never rides plaintext (S-1)
    // -------------------------------------------------------------------------

    public function testRequireTlsIsTheConstructorDefault(): void
    {
        foreach ([25, 587] as $port) {
            $transport = new SmtpTransport('smtp.example.com', $port);
            $this->assertTrue($this->readProperty($transport, 'requireTls'), "port {$port}");
        }
        $this->assertTrue($this->readProperty(new SmtpTransport('smtp.example.com', 465), 'requireTls'));
    }

    public function testSendRefusesStrippedStarttlsByDefault(): void
    {
        $this->requireFork();

        [$port, $pid, $log] = $this->forkFakeServer(advertiseStartTls: false);

        try {
            // No withRequireTls() call at all: the default must refuse the
            // downgrade that a STARTTLS-stripping middlebox would achieve.
            $transport = new SmtpTransport('127.0.0.1', $port, '', '', 2);

            try {
                $transport->send($this->sampleEmail());
                $this->fail('expected the stripped-STARTTLS downgrade to be refused');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('TLS is required', $e->getMessage());
            }

            $this->reap($pid);
            $dialogue = (string) \file_get_contents($log);

            // The envelope never reached the wire.
            $this->assertStringNotContainsString('MAIL FROM', $dialogue);
            $this->assertStringNotContainsString('DATA', $dialogue);
        } finally {
            $this->reap($pid);
            @\unlink($log);
        }
    }

    public function testAuthLoginRefusedOnCleartextChannel(): void
    {
        $this->requireFork();

        $server = $this->forkFakeServer(advertiseStartTls: false, advertiseAuth: true);
        [$port, $pid, $log] = $server;

        try {
            // Opted out of TLS but supplied credentials: AUTH LOGIN carries
            // base64 secrets and must be refused outright (fail-loud).
            $transport = (new SmtpTransport('127.0.0.1', $port, 'user@example.com', 'topSecretPassw0rd', 2))
                ->withRequireTls(false);

            try {
                $transport->send($this->sampleEmail());
                $this->fail('expected AUTH on a cleartext channel to be refused');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('insecure', $e->getMessage());
            }

            $this->reap($pid);
            $dialogue = (string) \file_get_contents($log);

            $this->assertStringNotContainsString('AUTH LOGIN', $dialogue);
            $this->assertStringNotContainsString(\base64_encode('user@example.com'), $dialogue);
            $this->assertStringNotContainsString(\base64_encode('topSecretPassw0rd'), $dialogue);
            $this->assertStringNotContainsString('MAIL FROM', $dialogue);
        } finally {
            $this->reap($pid);
            @\unlink($log);
        }
    }

    public function testAuthLoginProceedsWhenChannelIsEncrypted(): void
    {
        // Positive arm so the refusal guard cannot rot into an unconditional
        // throw: with encrypted=true (as after a successful upgrade) the AUTH
        // LOGIN dialogue runs unchanged over a socketpair.
        [$client, $server] = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        $this->assertIsNotBool($client);
        $this->assertIsNotBool($server);

        try {
            $transport = new SmtpTransport('smtp.example.com', 587, 'user@example.com', 's3cret');

            $socketProp = new \ReflectionProperty($transport, 'socket');
            $socketProp->setAccessible(true);
            $socketProp->setValue($transport, $client);

            $encryptedProp = new \ReflectionProperty($transport, 'encrypted');
            $encryptedProp->setAccessible(true);
            $encryptedProp->setValue($transport, true);

            $lastProp = new \ReflectionProperty($transport, 'lastResponse');
            $lastProp->setAccessible(true);
            $lastProp->setValue($transport, "250-AUTH LOGIN\r\n250 HELP"); // LOGIN-only: the fallback

            // Pre-load the server side of the pair with the 334/334/235 prompts.
            \fwrite($server, "334 VXNlcm5hbWU6\r\n334 UGFzc3dvcmQ6\r\n235 ok\r\n");

            $this->invokePrivate($transport, 'authenticateIfNeeded');

            $seen = '';
            for ($i = 0; $i < 3; $i++) {
                $line = \fgets($server);
                if ($line === false) {
                    break;
                }
                $seen .= $line;
            }
            $this->assertStringContainsString("AUTH LOGIN\r\n", $seen);
            $this->assertStringContainsString(\base64_encode('user@example.com') . "\r\n", $seen);
            $this->assertStringContainsString(\base64_encode('s3cret') . "\r\n", $seen);
        } finally {
            @\fclose($client);
            @\fclose($server);
        }
    }

    public function testAuthMechanismListParsingHonoursBothAdvertisementShapes(): void
    {
        // CM-20 (closes 021): the advertisement is a LIST, and both wire
        // shapes (AUTH <sp> and AUTH=) carry it. A list with no mechanism
        // this client speaks — including a bare AUTH keyword — parses to the
        // empty refusal, never a guess.
        $transport = new SmtpTransport('smtp.example.com');
        $parse = fn(string $reply): array => $this->invokePrivate($transport, 'authMechanisms', [$reply]);

        $this->assertSame(['CRAM-MD5', 'PLAIN'], $parse("250-AUTH=CRAM-MD5 PLAIN\r\n250 HELP"));
        $this->assertSame(['LOGIN', 'PLAIN'], $parse("250 AUTH LOGIN PLAIN"));
        $this->assertSame([], $parse("250-AUTH\r\n250 HELP"), 'bare AUTH advertises nothing usable');
        $this->assertSame([], $parse("250-STARTTLS\r\n250 HELP"));
        $this->assertSame([], $parse("250-AUTHX FAKED\r\n250 HELP"), 'an AUTH-prefixed keyword is not AUTH');
    }

    public function testCustomHeadersRideTheWireFoldedAndOrdered(): void
    {
        $this->requireFork();
        [$port, $pid, $log] = $this->forkFakeServer(false);
        $long = 'a@b.example';
        $filler = str_repeat('ref ', 260); // enough words to push the value past the 998-octet line budget
        $email = new Email(
            from:    ['me@sender.test'],
            to:      ['you@rcpt.test'],
            subject: 'Custom',
            body:    'plain body',
            headers: [
                'List-Unsubscribe' => '<https://lists.example.com/unsub?m=adc>',
                'X-Priority'       => '3 (normal)',
                'In-Reply-To'      => '<' . $long . '> ' . $filler . 'tail@refs.test',
            ],
        );

        try {
            $transport = (new SmtpTransport('127.0.0.1', $port))->withRequireTls(false);
            $transport->send($email);
        } finally {
            $this->reap($pid);
        }

        $wire = (string) file_get_contents($log);
        @unlink($log);

        $this->assertMatchesRegularExpression('/^List-Unsubscribe: <https:\/\/lists\.example\.com\/unsub\?m=adc>\r?$/mi', $wire);
        $this->assertMatchesRegularExpression('/^X-Priority: 3 \(normal\)\r?$/mi', $wire);
        $this->assertMatchesRegularExpression('/^In-Reply-To: <[^\r>]+>/mi', $wire);
        // Insertion order holds on the wire: List-Unsubscribe precedes X-Priority,
        // which precedes In-Reply-To, and all ride AFTER the fixed set.
        $lu  = (int) preg_match('/^List-Unsubscribe:.*$/mi', $wire);
        $pos = strpos($wire, 'List-Unsubscribe:');
        $xpo = strpos($wire, 'X-Priority:');
        $irp = strpos($wire, 'In-Reply-To:');
        $ctp = strpos($wire, "Content-Type: multipart");
        $this->assertGreaterThan($ctp, $pos, 'custom headers ride after the fixed set');
        $this->assertLessThan($xpo, $pos);
        $this->assertLessThan($irp, $xpo);
        // The over-long In-Reply-To value was folded, no wire line exceeds 998 octets:
        foreach (explode("\r\n", substr($wire, 0, $ctp)) as $line) {
            $this->assertLessThanOrEqual(998, strlen($line), 'header line over the 998-octet budget: ' . substr($line, 0, 40));
        }
        $this->assertMatchesRegularExpression("/^\s+ref /m", $wire); // continuation lines present
    }

    public function testEnvelopeWireContractDedupeNoBccAndDotStuffing(): void
    {
        // CM-10 (closes 010): the envelope semantics the transport promises —
        // one RCPT per unique recipient (case-duplicate cc collapses onto the
        // first-spelled to), the blind copy never surfaces as a header, and a
        // body line opening with a dot leaves dot-stuffed — pinned on the
        // bytes the fake server actually received, not on projections.
        $this->requireFork();
        [$port, $pid, $log] = $this->forkFakeServer(false);

        try {
            $email = new Email(
                from:    ['me@sender.test'],
                to:      ['Ada@Example.com', 'second@x.test'],
                subject: 'Wire contract',
                body:    "Hide\n.dot line\nEnd",
                cc:      ['Ada@Example.com'],
                bcc:     ['Hidden@Pool.example'],
            );
            $transport = (new SmtpTransport('127.0.0.1', $port))->withRequireTls(false);
            $transport->send($email);
        } finally {
            $this->reap($pid);
        }

        $wire = (string) \file_get_contents($log);
        @\unlink($log);

        $rcpts = [];
        \preg_match_all('/^RCPT TO:<([^>]*)>/mi', $wire, $m);
        $rcpts = $m[1];
        $this->assertSame(
            ['Ada@Example.com', 'second@x.test', 'Hidden@Pool.example'],
            $rcpts,
            'exact deduped RCPT set, first spelling kept, bcc rides the envelope',
        );
        $this->assertStringNotContainsString('Bcc:', $wire, 'the blind copy must never reach the wire as a header');
        $this->assertStringContainsString("\r\n..dot line\r\n", $wire, 'a leading-dot body line must leave dot-stuffed');
    }

    public function testRcptForwardingCode251CompletesTheSend(): void
    {
        // RFC 5321 4.1.2.5: a forwarding relay answers RCPT with 251; the
        // send must ride it to completion, not read it as a protocol break.
        $this->requireFork();
        [$port, $pid, $log] = $this->forkFakeServer(false, false, 251);
        try {
            $transport = (new SmtpTransport('127.0.0.1', $port))->withRequireTls(false);
            $transport->send($this->sampleEmail());
        } finally {
            $this->reap($pid);
        }
        $wire = (string) \file_get_contents($log);
        @\unlink($log);

        $this->assertMatchesRegularExpression('/^RCPT TO:<to\@example\.com>/mi', $wire);
        $this->assertStringContainsString('DATA', $wire);
    }

    public function testRcptCode252IsStillRefused(): void
    {
        // 252 (not implemented) is NOT an accepted outcome: the widening
        // covers exactly the 251 forwarding code.
        $this->requireFork();
        [$port, $pid, $log] = $this->forkFakeServer(false, false, 252);
        try {
            $transport = (new SmtpTransport('127.0.0.1', $port))->withRequireTls(false);
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Unexpected SMTP response');
            $transport->send($this->sampleEmail());
        } finally {
            $this->reap($pid);
            @\unlink($log);
        }
    }

    public function testCaFileIsImmutableAndFeedsTheSslContext(): void
    {
        $base = new SmtpTransport('smtp.example.com', 465);
        $with = $base->withCaFile('/etc/ssl/private/mail-ca.pem');

        $this->assertNotSame($base, $with);
        $this->assertNull($this->readProperty($base, 'caFile'));
        $this->assertSame('/etc/ssl/private/mail-ca.pem', $this->readProperty($with, 'caFile'));

        $options = $this->invokePrivate($with, 'streamContextOptions');
        $this->assertSame('/etc/ssl/private/mail-ca.pem', $options['ssl']['cafile']);
        $this->assertTrue($options['ssl']['verify_peer']);

        // Default context carries no cafile: the system trust store stands.
        $defaultOptions = $this->invokePrivate($base, 'streamContextOptions');
        $this->assertArrayNotHasKey('cafile', $defaultOptions['ssl']);

        // Empty path clears the override again.
        $this->assertNull($this->readProperty($with->withCaFile(''), 'caFile'));
    }

    // -------------------------------------------------------------------------
    // Fix 3 — password redaction
    // -------------------------------------------------------------------------

    public function testDebugInfoMasksPassword(): void
    {
        $transport = new SmtpTransport('smtp.example.com', 587, 'user@example.com', 'topSecretPassw0rd');

        $info = $transport->__debugInfo();
        $this->assertSame('****', $info['password']);

        // The secret must not appear anywhere in a dump of the object.
        $dump = \var_export($info, true) . \print_r($info, true);
        $this->assertStringNotContainsString('topSecretPassw0rd', $dump);

        // ...but the real value stays usable internally.
        $this->assertSame('topSecretPassw0rd', $this->readProperty($transport, 'password'));
    }

    public function testDebugInfoLeavesEmptyPasswordEmpty(): void
    {
        $transport = new SmtpTransport('smtp.example.com', 587);

        $this->assertSame('', $transport->__debugInfo()['password']);
    }

    // -------------------------------------------------------------------------
    // Fix 4 — attachment header injection (docs-fix-1023 C-2)
    // -------------------------------------------------------------------------

    public function testAttachmentFilenameCarryingCrlfIsRefused(): void
    {
        $forged = "evil.txt\r\nX-Injected: YES\r\n";

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('CRLF injection detected in attachment filename header');

        \SugarCraft\Mail\Attachment::fromContent('payload', $forged);
    }

    public function testAttachmentFromPathForgedDisplayNameIsRefused(): void
    {
        $tmp = \tempnam(\sys_get_temp_dir(), 'sp-sec-att-');
        \file_put_contents($tmp, 'x');
        try {
            $this->expectException(\InvalidArgumentException::class);
            \SugarCraft\Mail\Attachment::fromPath($tmp, "ok.txt\r\nX-Injected: 1");
        } finally {
            @\unlink($tmp);
        }
    }

    public function testAttachmentMimeTypeCarryingCrlfIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('CRLF injection detected in attachment MIME type header');

        \SugarCraft\Mail\Attachment::fromContent('x', 'a.txt', "text/plain\r\nX-Injected2: MIME");
    }

    public function testAttachmentMimeTypeParameterSuffixIsRefused(): void
    {
        // Step 36 defense-in-depth: parameter smuggling needs no CRLF. A
        // crafted 'text/plain; charset=x' would ride the part Content-Type
        // verbatim onto the same wire line, so the type must match the bare
        // RFC 9110 token/token grammar the library itself ever emits.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid attachment MIME type');

        \SugarCraft\Mail\Attachment::fromContent('x', 'a.txt', 'text/plain; charset=x');
    }

    public function testLongVendorMimeTypesStayAccepted(): void
    {
        // Grammar must not reject what the internal guesser/default produce.
        $doc = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
        $this->assertNotNull(\SugarCraft\Mail\Attachment::fromContent('x', 'a.docx', $doc));
        $this->assertNotNull(\SugarCraft\Mail\Attachment::fromContent('x', 'a.bin', 'application/octet-stream'));
    }

    public function testInlineAttachmentForgedFilenameIsRefused(): void
    {
        $tmp = \tempnam(\sys_get_temp_dir(), 'sp-sec-inline-');
        \file_put_contents($tmp, "\x89PNG");
        try {
            $this->expectException(\InvalidArgumentException::class);
            \SugarCraft\Mail\Attachment::inline($tmp, 'cid-1', "a.png\r\nX-Foo: bar");
        } finally {
            @\unlink($tmp);
        }
    }

    public function testQuoteAndBackslashInFilenameAreEscapedInPartHeaders(): void
    {
        $email = new Email(
            from:  ['from@example.com'],
            to:    ['to@example.com'],
            body:  'x',
            attachments: [\SugarCraft\Mail\Attachment::fromContent('data', 'a"b\\c.txt', 'text/plain')],
        );
        $mime = (new SmtpTransport('smtp.example.com', 587))->buildMimeMessage($email);

        // RFC 2045 quoted-string: every interior " and \ rides escaped, so the
        // parameter cannot terminate early and forge a header tail.
        $this->assertStringContainsString('name="a\\"b\\\\c.txt"', $mime);
        $this->assertStringContainsString('filename="a\\"b\\\\c.txt"', $mime);
        $this->assertStringNotContainsString('name="a"b', $mime);
    }

    // -------------------------------------------------------------------------
    // lib-reviews-1102 B4a — protocol hardening regressions (M-A/M-D/M-E/M-H)
    // -------------------------------------------------------------------------

    public function testReconnectClearsTheStaleEncryptionFlag(): void
    {
        // M-A: a transport that previously believed itself encrypted must not
        // skip STARTTLS on its next plaintext connection. With the sticky flag
        // (pre-fix) startTlsIfNeeded() early-returned and the send continued
        // naked; post-fix connect() derives the flag from the fresh socket.
        $this->requireFork();
        [$port, $pid, $log] = $this->forkFakeServer(advertiseStartTls: true);
        try {
            $transport = new SmtpTransport('127.0.0.1', $port);
            $encrypted = new \ReflectionProperty($transport, 'encrypted');
            $encrypted->setAccessible(true);
            $encrypted->setValue($transport, true); // stale belief from a prior send

            $this->invokePrivate($transport, 'connect');
            $this->assertFalse($encrypted->getValue($transport), 'a plaintext connect must not inherit encrypted=true');

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Unexpected SMTP response');
            // The fake server answers STARTTLS with its generic "250 OK", so the
            // 220 expectation fails — but only BECAUSE the client actually sent
            // STARTTLS, which is the point of the pin.
            $this->invokePrivate($transport, 'startTlsIfNeeded');
        } finally {
            $this->reap($pid);
            @\unlink($log);
        }
    }

    public function testAuthenticateRefusedWhenServerOmitsAuthAdvertisement(): void
    {
        // M-H: encrypted channel + configured credentials + an EHLO without
        // AUTH used to fall through to an ANONYMOUS send. The caller thinks it
        // authenticated; the relay never checked. Fail loud.
        $transport = new SmtpTransport('smtp.example.com', 587, 'user@example.com', 's3cret');

        $encrypted = new \ReflectionProperty($transport, 'encrypted');
        $encrypted->setAccessible(true);
        $encrypted->setValue($transport, true);

        $last = new \ReflectionProperty($transport, 'lastResponse');
        $last->setAccessible(true);
        $last->setValue($transport, "250-fake greets you\r\n250 HELP");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('did not advertise AUTH');
        $this->invokePrivate($transport, 'authenticateIfNeeded');
    }

    public function testQuitSurvivesASilentServerFarewell(): void
    {
        // M-D: after DATA was accepted (250 queued) the message is delivered
        // from the client's perspective. A server that swallows QUIT without
        // answering must not latch a false send_failed. The peer side of the
        // pair stays open but silent; the 1s stream timeout makes the read
        // return empty (empty_response) — pre-fix that exception escaped quit()
        // and send() would have reported failure for a queued message.
        $pair = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        $this->assertIsArray($pair);
        [$client, $server] = $pair;

        try {
            \stream_set_timeout($client, 1);
            $transport = new SmtpTransport('smtp.example.com', 587);

            $socketProp = new \ReflectionProperty($transport, 'socket');
            $socketProp->setAccessible(true);
            $socketProp->setValue($transport, $client);

            $this->invokePrivate($transport, 'quit'); // must NOT throw

            $this->assertNull($socketProp->getValue($transport), 'quit() must tear the socket down');
            $seen = \fgets($server);
            $this->assertSame("QUIT\r\n", $seen, 'quit() must still bid farewell before giving up');
        } finally {
            // quit() already fclose()d the client handle it owned.
            if (\is_resource($client)) {
                @\fclose($client);
            }
            @\fclose($server);
        }
    }

    public function testOversizedResponseLineIsRefused(): void
    {
        // M-E: one reply line may not exceed the cap; a hostile server that
        // streams a giant unterminated "line" is stopped at 4096 bytes.
        $transport = new SmtpTransport('smtp.example.com', 587);
        $sock      = \fopen('php://memory', 'r+');
        \fwrite($sock, '250-' . \str_repeat('A', 5000) . "\r\n");
        \rewind($sock);

        $socketProp = new \ReflectionProperty($transport, 'socket');
        $socketProp->setAccessible(true);
        $socketProp->setValue($transport, $sock);

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('size limit');
            $this->invokePrivate($transport, 'readResponse', [250]);
        } finally {
            @\fclose($sock);
        }
    }

    public function testDrippedContinuationBurstIsRefused(): void
    {
        // M-E: 150 continuation lines never trip a per-read timeout but would
        // grow memory unboundedly under the old loop. The reply is capped.
        $transport = new SmtpTransport('smtp.example.com', 587);
        $sock      = \fopen('php://memory', 'r+');
        \fwrite($sock, \str_repeat("250-drip\r\n", 150));
        \rewind($sock);

        $socketProp = new \ReflectionProperty($transport, 'socket');
        $socketProp->setAccessible(true);
        $socketProp->setValue($transport, $sock);

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('size limit');
            $this->invokePrivate($transport, 'readResponse', [250]);
        } finally {
            @\fclose($sock);
        }
    }

    public function testResponseDeadlineBreachesLoudlyEvenWithValidBytes(): void
    {
        // M-E: the whole reply is bounded by the configured timeout, not just
        // each read. A zero budget makes the deadline already-passed, so even
        // a perfectly-formed greeting must abort instead of lulling the
        // client. The constructor now floors timeout at 1, so the past
        // deadline is staged through reflection on purpose: this test pins
        // the deadline math, not a construction shape.
        $transport = new SmtpTransport('smtp.example.com', 587);
        $timeoutProp = new \ReflectionProperty($transport, 'timeout');
        $timeoutProp->setAccessible(true);
        $timeoutProp->setValue($transport, 0);
        $sock      = \fopen('php://memory', 'r+');
        \fwrite($sock, "220 fake ESMTP ready\r\n");
        \rewind($sock);

        $socketProp = new \ReflectionProperty($transport, 'socket');
        $socketProp->setAccessible(true);
        $socketProp->setValue($transport, $sock);

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('time budget');
            $this->invokePrivate($transport, 'readResponse', [220]);
        } finally {
            @\fclose($sock);
        }
    }

    // -------------------------------------------------------------------------
    // Legacy EHLO refusal (B8, RFC 5321 section 4.1.4)
    // -------------------------------------------------------------------------

    public function testLegacyServerThatRejectsEhloIsGreetedWithHelo(): void
    {
        if (!\function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl_fork unavailable');
        }

        $server = @\stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertIsResource($server, "listen failed: $errstr");
        $name = \stream_socket_get_name($server, false);
        $port = (int) \substr((string) \strrchr((string) $name, ':'), 1);
        $log = \tempnam(\sys_get_temp_dir(), 'helo-dialogue-');

        $pid = \pcntl_fork();
        $this->assertGreaterThanOrEqual(0, $pid);
        if ($pid === 0) {
            // The pre-EHLO server: 5xx every EHLO (a PERMANENT rejection is
            // the only class HELO may answer for, RFC 5321 4.1.4), 250 HELO.
            $conn = \stream_socket_accept($server, 10.0);
            $captured = '';
            if (\is_resource($conn)) {
                \stream_set_timeout($conn, 5);
                \fwrite($conn, "220 legacy.example\r\n");
                while (($line = (string) \fgets($conn)) !== '') {
                    $captured .= $line;
                    if (\str_starts_with($line, 'EHLO')) {
                        \fwrite($conn, "500 5.5.1 Unrecognized command\r\n");
                    } elseif (\str_starts_with($line, 'HELO')) {
                        \fwrite($conn, "250 legacy.example\r\n");
                    } elseif (\str_starts_with($line, 'QUIT')) {
                        \fwrite($conn, "221 bye\r\n");
                        break;
                    }
                }
                \fclose($conn);
            }
            \file_put_contents($log, $captured);
            if (\function_exists('posix_kill')) {
                \posix_kill(\getmypid(), \SIGKILL);
            }
            exit(0); // never run parent shutdown hooks
        }

        \fclose($server);
        $transport = (new SmtpTransport('127.0.0.1', $port, timeout: 5))->withRequireTls(false);
        try {
            $this->invokePrivate($transport, 'connect');
            $dialogue = '';
            for ($i = 0; $i < 250 && $dialogue === ''; $i++) {
                \usleep(20000);
                $dialogue = (string) \file_get_contents($log);
            }
            $this->assertStringContainsString('EHLO', $dialogue);
            $this->assertStringContainsString('HELO', $dialogue);
            // A HELO session advertises no extensions (RFC 5321 4.1.4): the
            // 8BITMIME snapshot must read false, never a stale EHLO answer.
            $this->assertFalse($this->readProperty($transport, 'serverSupports8Bit'));
            $this->invokePrivate($transport, 'disconnect');
        } finally {
            $this->reap($pid);
            @\unlink($log);
        }
    }

    public function testTransientEhloFailureNeverFallsBackToHelo(): void
    {
        // 4xx means "try again" (RFC 5321 4.2.1); answering it with HELO
        // would mask a real outage as a legacy server and silently lose
        // STARTTLS/AUTH capability discovery mid-incident. 5xx only.
        if (!\function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl_fork unavailable');
        }

        $server = @\stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertIsResource($server, "listen failed: $errstr");
        $name = \stream_socket_get_name($server, false);
        $port = (int) \substr((string) \strrchr((string) $name, ':'), 1);
        $log = \tempnam(\sys_get_temp_dir(), 'transient-dialogue-');

        $pid = \pcntl_fork();
        $this->assertGreaterThanOrEqual(0, $pid);
        if ($pid === 0) {
            $conn = \stream_socket_accept($server, 10.0);
            $captured = '';
            if (\is_resource($conn)) {
                \stream_set_timeout($conn, 5);
                \fwrite($conn, "220 legacy.example\r\n");
                while (($line = (string) \fgets($conn)) !== '') {
                    $captured .= $line;
                    if (\str_starts_with($line, 'EHLO')) {
                        \fwrite($conn, "421 4.3.0 Temporarily unavailable\r\n");
                    }
                    // No HELO answer on purpose: if the client wrongly falls
                    // back after a transient refusal, the dialogue records it.
                }
                \fclose($conn);
            }
            \file_put_contents($log, $captured);
            if (\function_exists('posix_kill')) {
                \posix_kill(\getmypid(), \SIGKILL);
            }
            exit(0);
        }

        \fclose($server);
        $transport = (new SmtpTransport('127.0.0.1', $port, timeout: 5))->withRequireTls(false);
        try {
            $thrown = null;
            try {
                $this->invokePrivate($transport, 'connect');
            } catch (\RuntimeException $e) {
                $thrown = $e;
            }
            // connect() does not self-close on throw; release the socket so the
            // child sees EOF, writes its dialogue log, and exits.
            $this->invokePrivate($transport, 'disconnect');
            $this->assertNotNull($thrown, 'a transient EHLO refusal must abort connect()');
            $dialogue = '';
            for ($i = 0; $i < 250 && $dialogue === ''; $i++) {
                \usleep(20000);
                $dialogue = (string) \file_get_contents($log);
            }
            $dialogue = (string) \file_get_contents($log);
            $this->assertStringContainsString('EHLO', $dialogue);
            $this->assertStringNotContainsString('HELO', $dialogue);
        } finally {
            $this->reap($pid);
            @\unlink($log);
        }
    }

    public function testAttachmentDebugInfoHidesThePayloadBytes(): void
    {
        // C4 doctrine: payloads are not credentials, but a 25 MiB blob echoed
        // into a var_dump or backtrace is its own hazard. __debugInfo covers
        // var_dump/print_r; serialize stays legal (payload, not secret).
        $att = Attachment::fromContent('TOPSECRET-BLOB-DO-NOT-ECHO', 'x.txt');

        \ob_start();
        \var_dump($att);
        $dump = (string) \ob_get_clean();

        $this->assertStringNotContainsString('TOPSECRET-BLOB', $dump);
        $this->assertStringContainsString('bytes hidden', $dump);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function sampleEmail(): Email
    {
        return Email::new('from@example.com', 'to@example.com', 'Subject', 'Body');
    }

    private function requireFork(): void
    {
        if (!\function_exists('pcntl_fork') || !\function_exists('pcntl_waitpid')) {
            $this->markTestSkipped('pcntl not available');
        }
    }

    /**
     * Fork a minimal fake SMTP server on an ephemeral loopback port.
     *
     * The child records every line the client sends to $log, then hard-exits so
     * it never re-enters PHPUnit's shutdown handlers.
     *
     * @param bool $advertiseStartTls include 250-STARTTLS in the EHLO list
     * @param bool $advertiseAuth     include 250-AUTH LOGIN PLAIN in the list
     *
     * @return array{0:int,1:int,2:string} [port, childPid, dialogueLogPath]
     */
    private function forkFakeServer(bool $advertiseStartTls, bool $advertiseAuth = false, int $rcptCode = 250): array
    {
        $errno  = 0;
        $errstr = '';
        $server = @\stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($server === false) {
            $this->markTestSkipped("cannot bind loopback server: {$errstr}");
        }
        $name = \stream_socket_get_name($server, false);
        $port = (int) \substr((string) $name, \strrpos((string) $name, ':') + 1);
        $log  = (string) \tempnam(\sys_get_temp_dir(), 'sp-smtp-dialogue-');

        $pid = \pcntl_fork();
        if ($pid === -1) {
            @\fclose($server);
            @\unlink($log);
            $this->markTestSkipped('pcntl_fork failed');
        }

        if ($pid === 0) {
            $captured = '';
            $conn = @\stream_socket_accept($server, 3);
            if ($conn !== false) {
                \stream_set_timeout($conn, 2);
                @\fwrite($conn, "220 fake ESMTP ready\r\n");
                for ($i = 0; $i < 200; $i++) {
                    $line = \fgets($conn);
                    if ($line === false || $line === '') {
                        break;
                    }
                    $captured .= $line;
                    $cmd = \strtoupper(\substr(\ltrim($line), 0, 4));
                    if ($cmd === 'EHLO' || $cmd === 'HELO') {
                        $resp = "250-fake greets you\r\n";
                        if ($advertiseStartTls) {
                            $resp .= "250-STARTTLS\r\n";
                        }
                        if ($advertiseAuth) {
                            $resp .= "250-AUTH LOGIN PLAIN\r\n";
                        }
                        $resp .= "250 HELP\r\n";
                        @\fwrite($conn, $resp);
                    } elseif ($cmd === 'MAIL') {
                        @\fwrite($conn, "250 OK\r\n");
                    } elseif ($cmd === 'RCPT') {
                        @\fwrite($conn, \sprintf("%d OK\r\n", $rcptCode));
                    } elseif ($cmd === 'DATA') {
                        @\fwrite($conn, "354 go\r\n");
                        while (($d = \fgets($conn)) !== false && $d !== '') {
                            $captured .= $d;
                            if (\rtrim($d, "\r\n") === '.') {
                                break;
                            }
                        }
                        @\fwrite($conn, "250 queued\r\n");
                    } elseif ($cmd === 'QUIT') {
                        @\fwrite($conn, "221 bye\r\n");
                        break;
                    } else {
                        @\fwrite($conn, "250 OK\r\n");
                    }
                }
                @\fclose($conn);
            }
            @\fclose($server);
            @\file_put_contents($log, $captured);
            // Hard-exit: skip inherited PHPUnit shutdown handlers to avoid
            // duplicated test output from the forked child.
            if (\function_exists('posix_kill')) {
                \posix_kill(\getmypid(), \SIGKILL);
            }
            exit(0);
        }

        @\fclose($server); // parent drives the client only; child owns accept()
        return [$port, $pid, $log];
    }

    private function reap(int $pid): void
    {
        if ($pid <= 0) {
            return;
        }
        $status = 0;
        $deadline = \microtime(true) + 5.0;
        while (\microtime(true) < $deadline) {
            $res = \pcntl_waitpid($pid, $status, \WNOHANG);
            if ($res === $pid || $res === -1) {
                return;
            }
            \usleep(20000);
        }
        if (\function_exists('posix_kill')) {
            @\posix_kill($pid, \SIGKILL);
        }
        \pcntl_waitpid($pid, $status);
    }

    private function invokePrivate(object $obj, string $method, array $args = []): mixed
    {
        $ref = new \ReflectionMethod($obj, $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs($obj, $args);
    }

    private function readProperty(object $obj, string $property): mixed
    {
        $ref = new \ReflectionProperty($obj, $property);
        $ref->setAccessible(true);
        return $ref->getValue($obj);
    }
}
