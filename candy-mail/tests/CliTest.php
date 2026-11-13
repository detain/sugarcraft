<?php

declare(strict_types=1);

namespace SugarCraft\Mail\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Tests for CLI argument parsing via parseArgs().
 */
final class CliTest extends TestCase
{
    /**
     * Every env var the CLI reads that these tests manipulate. Snapshotted
     * in setUp and restored in tearDown so shards never leak state.
     */
    private const ENV_NAMES = [
        'CANDY_MAIL_FROM',
        'CANDY_MAIL_SIGNATURE',
        'CANDY_MAIL_SMTP_HOST',
        'CANDY_MAIL_SMTP_PORT',
        'CANDY_MAIL_SMTP_REQUIRE_TLS',
        'CANDY_MAIL_SMTP_IMPLICIT_TLS',
        'RESEND_API_KEY',
    ];

    /** @var array<string, array{env: mixed, get: mixed}> */
    private array $envSnapshot = [];

    protected function setUp(): void
    {
        // Require bin/candy-mail with the guard so dispatch doesn't execute
        // The functions parseArgs and buildEmail are free functions in SugarCraft\Mail namespace
        require_once __DIR__ . '/../bin/candy-mail';

        foreach (self::ENV_NAMES as $name) {
            $this->envSnapshot[$name] = [
                'env'  => $_ENV[$name] ?? null,
                'get'  => \getenv($name, true),
            ];
            unset($_ENV[$name]);
            \putenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->envSnapshot as $name => $saved) {
            unset($_ENV[$name]);
            \putenv($name);
            if ($saved['env'] !== null) {
                $_ENV[$name] = $saved['env'];
            }
            if ($saved['get'] !== false) {
                \putenv($name . '=' . $saved['get']);
            }
        }
        $this->envSnapshot = [];
    }

    private function setEnv(string $name, string $value): void
    {
        $_ENV[$name] = $value;
        \putenv($name . '=' . $value);
    }

    public function testParseArgsFromFlag(): void
    {
        $result = \SugarCraft\Mail\parseArgs(['candy-mail', '--from', 'a@b', '--to', 'c@d']);

        $this->assertSame('a@b', $result['opts']['from']);
        $this->assertSame(['c@d'], $result['opts']['to']);
        $this->assertFalse($result['help']);
    }

    public function testParseArgsToFlag(): void
    {
        $result = \SugarCraft\Mail\parseArgs(['candy-mail', '--from', 'a@b', '--to', 'c@d', '--subject', 'Hi']);

        $this->assertSame('Hi', $result['opts']['subject']);
    }

    public function testParseArgsHelpFlagSurfaces(): void
    {
        $result = \SugarCraft\Mail\parseArgs(['candy-mail', '--help']);

        $this->assertTrue($result['help']);
        $this->assertTrue($result['opts']['help']);
    }

    public function testParseArgsShortHelpFlag(): void
    {
        $result = \SugarCraft\Mail\parseArgs(['candy-mail', '-h']);

        $this->assertTrue($result['help']);
        $this->assertTrue($result['opts']['help']);
    }

    public function testParseArgsMultipleTo(): void
    {
        $result = \SugarCraft\Mail\parseArgs([
            'candy-mail', '--from', 'a@b',
            '--to', 'c@d',
            '--to', 'e@f',
        ]);

        $this->assertSame(['c@d', 'e@f'], $result['opts']['to']);
    }

    public function testParseArgsCc(): void
    {
        $result = \SugarCraft\Mail\parseArgs([
            'candy-mail', '--from', 'a@b', '--to', 'c@d', '--cc', 'cc@example.com',
        ]);

        $this->assertSame(['cc@example.com'], $result['opts']['cc']);
    }

    public function testParseArgsBcc(): void
    {
        $result = \SugarCraft\Mail\parseArgs([
            'candy-mail', '--from', 'a@b', '--to', 'c@d', '--bcc', 'bcc@example.com',
        ]);

        $this->assertSame(['bcc@example.com'], $result['opts']['bcc']);
    }

    public function testParseArgsAttachments(): void
    {
        $result = \SugarCraft\Mail\parseArgs([
            'candy-mail', '--from', 'a@b', '--to', 'c@d', '--attach', 'file1.pdf', '--attach', 'file2.pdf',
        ]);

        $this->assertSame(['file1.pdf', 'file2.pdf'], $result['opts']['attachments']);
    }

    public function testParseArgsShortFromFlag(): void
    {
        $result = \SugarCraft\Mail\parseArgs(['candy-mail', '-f', 'a@b', '-t', 'c@d']);

        $this->assertSame('a@b', $result['opts']['from']);
        $this->assertSame(['c@d'], $result['opts']['to']);
    }

    public function testParseArgsShortSubjectFlag(): void
    {
        $result = \SugarCraft\Mail\parseArgs(['candy-mail', '-f', 'a@b', '-t', 'c@d', '-s', 'Hello']);

        $this->assertSame('Hello', $result['opts']['subject']);
    }

    public function testParseArgsBodyFlag(): void
    {
        $result = \SugarCraft\Mail\parseArgs([
            'candy-mail', '--from', 'a@b', '--to', 'c@d', '--body', 'Message body',
        ]);

        $this->assertSame('Message body', $result['opts']['body']);
    }

    public function testParseArgsReplyTo(): void
    {
        $result = \SugarCraft\Mail\parseArgs([
            'candy-mail', '--from', 'a@b', '--to', 'c@d', '--reply-to', 'reply@example.com',
        ]);

        $this->assertSame('reply@example.com', $result['opts']['reply_to']);
    }

    public function testParseArgsHtmlFlag(): void
    {
        $result = \SugarCraft\Mail\parseArgs([
            'candy-mail', '--from', 'a@b', '--to', 'c@d', '--html', '<p>HTML body</p>',
        ]);

        $this->assertSame('<p>HTML body</p>', $result['opts']['html']);
    }

    // -----------------------------------------------------------------
    // docs-fix-1023: `--flag=value` parity, unknown-option refusal,
    // env-aware help gate, port validation, new switches.
    // -----------------------------------------------------------------

    public function testFlagValueFormCollectsRepeatableFlagsIntoLists(): void
    {
        $result = \SugarCraft\Mail\parseArgs([
            'candy-mail',
            '--from=a@b.c', '--to=x@y.z', '--to=p@q.z',
            '--cc=c@d.e', '--bcc=e@f.g', '--attach=invoice.pdf',
            '--subject=Hi', '--body=B', '--html=H', '--reply-to=r@s.t',
        ]);

        $this->assertSame('a@b.c', $result['opts']['from']);
        $this->assertSame(['x@y.z', 'p@q.z'], $result['opts']['to'], 'the = form must append, never overwrite');
        $this->assertSame(['c@d.e'], $result['opts']['cc']);
        $this->assertSame(['e@f.g'], $result['opts']['bcc']);
        $this->assertSame(['invoice.pdf'], $result['opts']['attachments'], 'attach= must land under the key buildEmail reads');
        $this->assertSame('Hi', $result['opts']['subject']);
        $this->assertSame('B', $result['opts']['body']);
        $this->assertSame('H', $result['opts']['html']);
        $this->assertSame('r@s.t', $result['opts']['reply_to']);
    }

    public function testMixedValueFormsShareOneList(): void
    {
        $result = \SugarCraft\Mail\parseArgs(['candy-mail', '--to', 'a@b.c', '--to=d@e.f']);

        $this->assertSame(['a@b.c', 'd@e.f'], $result['opts']['to']);
    }

    public function testUnknownLongFlagIsRefusedNotIgnored(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('--frobnicate');

        \SugarCraft\Mail\parseArgs(['candy-mail', '--to', 'a@b.c', '--frobnicate']);
    }

    public function testUnknownLongFlagWithValueIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('--frobnicate=1');

        \SugarCraft\Mail\parseArgs(['candy-mail', '--to', 'a@b.c', '--frobnicate=1']);
    }

    public function testUnknownShortFlagIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('-z');

        \SugarCraft\Mail\parseArgs(['candy-mail', '--to', 'a@b.c', '-z']);
    }

    public function testParseArgsRefusesToStealASwitchAsAValue(): void
    {
        // M-G: `--subject --render` used to swallow the switch as the value.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('requires a value');

        \SugarCraft\Mail\parseArgs(['candy-mail', '--to', 'a@b.c', '--subject', '--render']);
    }

    public function testParseArgsRefusesAMissingTrailingValue(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('--attach');

        \SugarCraft\Mail\parseArgs(['candy-mail', '--to', 'a@b.c', '--attach']);
    }

    public function testUnknownOptionSuggestsTheClosestFlag(): void
    {
        // B8: naming the closest known flag turns a stall into a fix.
        try {
            \SugarCraft\Mail\parseArgs(['candy-mail', '--subjct', 'x']);
            $this->fail('expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Unknown option: --subjct', $e->getMessage());
            // The hint rides its own line (CM-12): a space-joined pair
            // wrapped unreadably in the longer locales.
            $this->assertStringContainsString("\nDid you mean --subject?", $e->getMessage());
        }
    }

    public function testDistantUnknownOptionCarriesNoGuess(): void
    {
        try {
            \SugarCraft\Mail\parseArgs(['candy-mail', '--zzzzzzzzzzz']);
            $this->fail('expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringNotContainsString('Did you mean', $e->getMessage());
            $this->assertStringNotContainsString("\n", $e->getMessage(), 'the no-hint refusal stays a single line');
        }
    }

    public function testParseArgsInlineFormCarriesDashLeadingValues(): void
    {
        // The documented escape hatch: a value that begins with a dash rides
        // the --option=value form and must survive untouched.
        $result = \SugarCraft\Mail\parseArgs(['candy-mail', '--to', 'a@b.c', '--subject=-50% off']);

        $this->assertSame('-50% off', $result['opts']['subject']);
    }

    public function testDoubleDashTerminatesOptionParsing(): void
    {
        $result = \SugarCraft\Mail\parseArgs(['candy-mail', '--', '--weird@host']);

        $this->assertSame(['--weird@host'], $result['positional']);
        $this->assertSame(['--weird@host'], $result['opts']['to'], 'post-dash tokens become recipients, never flags');
    }

    public function testVersionAndRenderSwitchesParse(): void
    {
        $this->assertTrue(\SugarCraft\Mail\parseArgs(['candy-mail', '--version'])['version']);
        $this->assertTrue(\SugarCraft\Mail\parseArgs(['candy-mail', '--render'])['render']);

        $plain = \SugarCraft\Mail\parseArgs(['candy-mail', '--to', 'a@b.c']);
        $this->assertFalse($plain['version']);
        $this->assertFalse($plain['render']);
    }

    public function testHelpGateSendsWhenEnvironmentCarriesSender(): void
    {
        // M-5 keystone: `CANDY_MAIL_FROM=… candy-mail --to x@y` used to print help.
        $this->setEnv('CANDY_MAIL_FROM', 'env-sender@example.com');
        $args = \SugarCraft\Mail\parseArgs(['candy-mail', '--to', 'you@example.com']);

        $this->assertFalse(\SugarCraft\Mail\cliWantsHelp($args));
    }

    public function testHelpGateSenderArmReadsTheEnvironment(): void
    {
        // No recipients at all: without the env the gate shows help; with an
        // env sender it proceeds (so buildEmail reports the missing --to
        // instead of silently showing a banner).
        $args = \SugarCraft\Mail\parseArgs(['candy-mail', '--subject', 'S']);
        $this->assertTrue(\SugarCraft\Mail\cliWantsHelp($args));

        $this->setEnv('CANDY_MAIL_FROM', 'env-sender@example.com');
        $this->assertFalse(\SugarCraft\Mail\cliWantsHelp($args));
    }

    public function testHelpGateSendsWhenRecipientPresentWithoutSender(): void
    {
        $args = \SugarCraft\Mail\parseArgs(['candy-mail', '--to', 'you@example.com']);

        $this->assertFalse(\SugarCraft\Mail\cliWantsHelp($args));
    }

    public function testHelpGateFallsBackToHelpOnBareInvocation(): void
    {
        $this->assertTrue(\SugarCraft\Mail\cliWantsHelp(\SugarCraft\Mail\parseArgs(['candy-mail'])));
    }

    public function testHelpGateAlwaysYieldsToHelpFlag(): void
    {
        $args = \SugarCraft\Mail\parseArgs(['candy-mail', '--from', 'a@b.c', '--to', 'c@d.e', '--help']);

        $this->assertTrue(\SugarCraft\Mail\cliWantsHelp($args));
    }

    public function testSenderFromEnvironmentReadsBothEnvCopies(): void
    {
        $this->assertSame('', \SugarCraft\Mail\senderFromEnvironment());

        $_ENV['CANDY_MAIL_FROM'] = 'via-env@example.com';
        $this->assertSame('via-env@example.com', \SugarCraft\Mail\senderFromEnvironment());
        unset($_ENV['CANDY_MAIL_FROM']);

        \putenv('CANDY_MAIL_FROM=via-putenv@example.com');
        $this->assertSame('via-putenv@example.com', \SugarCraft\Mail\senderFromEnvironment());
    }

    public function testPortDefaultsValidatesAndRefusesGarbage(): void
    {
        $this->assertSame(587, \SugarCraft\Mail\portFromEnvironment());

        $this->setEnv('CANDY_MAIL_SMTP_PORT', '2525');
        $this->assertSame(2525, \SugarCraft\Mail\portFromEnvironment());

        foreach (['0', '99999', 'abc', '-25', '25.5'] as $bad) {
            $this->setEnv('CANDY_MAIL_SMTP_PORT', $bad);
            try {
                \SugarCraft\Mail\portFromEnvironment();
                $this->fail("port '{$bad}' must be refused");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString($bad, $e->getMessage());
            }
        }
    }

    public function testEnvFlagTruthTable(): void
    {
        $this->assertTrue(\SugarCraft\Mail\envFlag('CANDY_MAIL_SMTP_REQUIRE_TLS', true));
        $this->assertFalse(\SugarCraft\Mail\envFlag('CANDY_MAIL_SMTP_IMPLICIT_TLS', false));

        $this->setEnv('CANDY_MAIL_SMTP_REQUIRE_TLS', '0');
        $this->assertFalse(\SugarCraft\Mail\envFlag('CANDY_MAIL_SMTP_REQUIRE_TLS', true));

        $this->setEnv('CANDY_MAIL_SMTP_REQUIRE_TLS', 'no');
        $this->assertFalse(\SugarCraft\Mail\envFlag('CANDY_MAIL_SMTP_REQUIRE_TLS', true));

        $this->setEnv('CANDY_MAIL_SMTP_IMPLICIT_TLS', 'on');
        $this->assertTrue(\SugarCraft\Mail\envFlag('CANDY_MAIL_SMTP_IMPLICIT_TLS', false));
    }

    public function testEnvFlagAcceptsTheWholeOffAndOnVocabulary(): void
    {
        foreach (['0', 'false', 'no', 'off', 'NO', 'Off'] as $off) {
            $this->setEnv('CANDY_MAIL_SMTP_REQUIRE_TLS', $off);
            $this->assertFalse(\SugarCraft\Mail\envFlag('CANDY_MAIL_SMTP_REQUIRE_TLS', true), "'{$off}' means off");
        }

        foreach (['1', 'true', 'yes', 'on', 'YES', 'True'] as $on) {
            $this->setEnv('CANDY_MAIL_SMTP_REQUIRE_TLS', $on);
            $this->assertTrue(\SugarCraft\Mail\envFlag('CANDY_MAIL_SMTP_REQUIRE_TLS', false), "'{$on}' means on");
        }
    }

    public function testEnvFlagRefusesWordsItCannotInterpret(): void
    {
        // M-B: a typo in a security switch ('ture') used to silently disable
        // REQUIRE_TLS. Unrecognised values must fail loudly instead.
        foreach (['ture', '2', 'maybe', 'y', 'nope', 'x'] as $garbage) {
            $this->setEnv('CANDY_MAIL_SMTP_REQUIRE_TLS', $garbage);
            try {
                \SugarCraft\Mail\envFlag('CANDY_MAIL_SMTP_REQUIRE_TLS', true);
                $this->fail("flag '{$garbage}' must be refused");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('invalid flag value', $e->getMessage());
                $this->assertStringContainsString($garbage, $e->getMessage());
            }
        }
    }

    public function testCliVersionResolvesFromComposerMetadata(): void
    {
        $version = \SugarCraft\Mail\cliVersion();

        $this->assertNotSame('', $version);
        $this->assertMatchesRegularExpression('/^(dev|.*[0-9]+)/', $version);
    }

    public function testBuildEmailConsumesParsedListShapes(): void
    {
        $email = \SugarCraft\Mail\buildEmail([
            'from'        => 'me@example.com',
            'to'          => ['a@b.c', 'd@e.f'],
            'cc'          => ['c@g.h'],
            'subject'     => 'S',
            'body'        => 'B',
        ], '');

        $this->assertSame(['me@example.com'], $email->from);
        $this->assertSame(['a@b.c', 'd@e.f'], $email->to);
        $this->assertSame(['c@g.h'], $email->cc);
        $this->assertSame('S', $email->subject);
    }

    public function testBuildEmailSignatureEnvAppendsBody(): void
    {
        $this->setEnv('CANDY_MAIL_SIGNATURE', '-- sent from the cli');
        $email = \SugarCraft\Mail\buildEmail(['from' => 'me@example.com', 'to' => ['a@b.c'], 'body' => 'Hi'], '');

        $this->assertStringContainsString('-- sent from the cli', (string) $email->bodyWithSignature());
    }

    public function testBuildEmailRefusesEmptyRecipients(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        \SugarCraft\Mail\buildEmail(['from' => 'me@example.com'], '');
    }

    public function testBuildEmailRefusesAFabricatedSender(): void
    {
        // M-C: the missing --from case used to invent unknown@localhost.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('No sender address');

        \SugarCraft\Mail\buildEmail(['to' => ['a@b.c'], 'body' => 'Hi'], '');
    }

    public function testBuildEmailLeavesSubjectNullWhenAbsent(): void
    {
        // m6: no --subject must produce NO header, not a fabricated '(no subject)'.
        $email = \SugarCraft\Mail\buildEmail(['from' => 'me@example.com', 'to' => ['a@b.c'], 'body' => 'Hi'], '');

        $this->assertNull($email->subject);
    }

    public function testHelpGateYieldsToCcAndBccRecipients(): void
    {
        // qa m1: cc/bcc-only invocations mean to send — the gate must let
        // them through to the loud missing---to refusal.
        $this->assertFalse(\SugarCraft\Mail\cliWantsHelp(\SugarCraft\Mail\parseArgs(['candy-mail', '--cc', 'x@y.z'])));
        $this->assertFalse(\SugarCraft\Mail\cliWantsHelp(\SugarCraft\Mail\parseArgs(['candy-mail', '--bcc', 'x@y.z'])));
    }

    public function testTimeoutSecondsDefaultsBoundsAndRefusals(): void
    {
        $this->assertSame(30, \SugarCraft\Mail\timeoutSeconds(''));
        $this->assertSame(30, \SugarCraft\Mail\timeoutSeconds(null));
        $this->assertSame(12, \SugarCraft\Mail\timeoutSeconds('12'));

        foreach (['0', '3601', 'abc', '-5', '2.5'] as $bad) {
            try {
                \SugarCraft\Mail\timeoutSeconds($bad);
                $this->fail("timeout '{$bad}' must be refused");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('Timeout', $e->getMessage());
            }
        }
    }

    public function testEnvStringReadsBothEnvCopies(): void
    {
        $this->assertSame('', \SugarCraft\Mail\envString('CANDY_MAIL_SMTP_HELO'));

        $_ENV['CANDY_MAIL_SMTP_HELO'] = 'via-env.example.org';
        $this->assertSame('via-env.example.org', \SugarCraft\Mail\envString('CANDY_MAIL_SMTP_HELO'));
        unset($_ENV['CANDY_MAIL_SMTP_HELO']);

        \putenv('CANDY_MAIL_SMTP_HELO=via-putenv.example.org');
        $this->assertSame('via-putenv.example.org', \SugarCraft\Mail\envString('CANDY_MAIL_SMTP_HELO'));
    }

    public function testBuildTransportConsumesTheFlagSeams(): void
    {
        // M-Q/M-AA: flags win over env; both reach the typed transport.
        $this->setEnv('CANDY_MAIL_SMTP_HOST', 'smtp.invalid.example');
        $this->setEnv('CANDY_MAIL_SMTP_HELO', 'env.example.org');

        $flagged = \SugarCraft\Mail\buildTransport(['helo' => 'flag.example.org', 'timeout' => '45']);
        $this->assertSame('flag.example.org', $this->readProperty($flagged, 'heloHost'), 'flags win over env');
        $this->assertSame(45, $this->readProperty($flagged, 'timeout'));

        $fromEnv = \SugarCraft\Mail\buildTransport();
        $this->assertSame('env.example.org', $this->readProperty($fromEnv, 'heloHost'));
    }

    public function testBuildTransportCarriesCaFileFromBothInputShapes(): void
    {
        // CM-09 arm (d) (closes 080): the CA plumbing the docs sell must be
        // provably wired — a readable PEM via the flag AND via the env row
        // lands on the transport, and an unusable path is refused at
        // configuration time, never discovered mid-handshake.
        $pem = (string) \tempnam(\sys_get_temp_dir(), 'clitls-ca-');
        \file_put_contents($pem, "-----BEGIN CERTIFICATE-----\ntest\n-----END CERTIFICATE-----\n");
        $this->setEnv('CANDY_MAIL_SMTP_HOST', 'smtp.invalid.example');
        try {
            $flagged = \SugarCraft\Mail\buildTransport(['ca_file' => $pem]);
            $this->assertSame($pem, $this->readProperty($flagged, 'caFile'), '--ca-file reaches the transport');

            $this->setEnv('CANDY_MAIL_SMTP_CA_FILE', $pem);
            $fromEnv = \SugarCraft\Mail\buildTransport();
            $this->assertSame($pem, $this->readProperty($fromEnv, 'caFile'), 'CANDY_MAIL_SMTP_CA_FILE reaches the transport');

            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('not a readable regular file');
            \SugarCraft\Mail\buildTransport(['ca_file' => $pem . '.nope']);
        } finally {
            // The neighbour idiom is per-test env hygiene (this class has no
            // auto-restore): the deleted PEM must not ride the process env
            // into the next test's buildTransport() as a phantom caFile.
            unset($_ENV['CANDY_MAIL_SMTP_HOST']);
            \putenv('CANDY_MAIL_SMTP_HOST');
            unset($_ENV['CANDY_MAIL_SMTP_CA_FILE']);
            \putenv('CANDY_MAIL_SMTP_CA_FILE');
            \unlink($pem);
        }
    }

    public function testBuildEmailSurvivesUnsetSignatureEnv(): void
    {
        // getenv() answers false, not '' — an unguarded pass would fatal
        // every send on machines without CANDY_MAIL_SIGNATURE exported.
        $email = \SugarCraft\Mail\buildEmail(['from' => 'me@example.com', 'to' => ['a@b.c'], 'body' => 'Hi'], '');

        $this->assertSame('Hi', $email->body);
        $this->assertNull($email->signature);
    }

    public function testBuildTransportPrefersResendThenSmtpAndNone(): void
    {
        $this->setEnv('RESEND_API_KEY', 're_test_key');
        // CM-24: asserting on the bare call expression lets the recorded
        // type guard leak into the next buildTransport() line; binding to a
        // variable keeps each arm's narrowing where it belongs.
        $resend = \SugarCraft\Mail\buildTransport();
        $this->assertInstanceOf(\SugarCraft\Mail\ResendTransport::class, $resend);

        unset($_ENV['RESEND_API_KEY']);
        \putenv('RESEND_API_KEY');

        $this->setEnv('CANDY_MAIL_SMTP_HOST', 'smtp.invalid.example');
        $smtp = \SugarCraft\Mail\buildTransport();
        $this->assertInstanceOf(\SugarCraft\Mail\SmtpTransport::class, $smtp);
        $this->assertTrue($this->readProperty($smtp, 'requireTls'), 'the CLI must keep the safe TLS default');
        $this->assertFalse($this->readProperty($smtp, 'implicitTls'));
        $this->assertSame(587, $this->readProperty($smtp, 'port'));

        $this->setEnv('CANDY_MAIL_SMTP_REQUIRE_TLS', '0');
        $this->setEnv('CANDY_MAIL_SMTP_IMPLICIT_TLS', '1');
        $this->setEnv('CANDY_MAIL_SMTP_PORT', '465');
        $opted = \SugarCraft\Mail\buildTransport();
        $this->assertFalse($this->readProperty($opted, 'requireTls'));
        $this->assertTrue($this->readProperty($opted, 'implicitTls'));
        $this->assertSame(465, $this->readProperty($opted, 'port'));

        unset($_ENV['CANDY_MAIL_SMTP_HOST']);
        \putenv('CANDY_MAIL_SMTP_HOST');
        $this->expectException(\RuntimeException::class);
        \SugarCraft\Mail\buildTransport();
    }

    /**
     * CM-31: an unset CANDY_MAIL_SMTP_IMPLICIT_TLS must not cancel the
     * constructor's port-465 auto-detect. buildTransport() used to call
     * ->withImplicitTls(envFlag(..., false)) unconditionally, so the absent
     * env var overrode the inferred posture and the README TLS table's
     * "auto-detected on 465" never applied through the CLI. Tri-state now:
     * unset leaves the constructor in charge, set words (both directions)
     * still override.
     */
    public function testImplicitTlsAutoDetectSurvivesUnsetEnv(): void
    {
        $this->setEnv('CANDY_MAIL_SMTP_HOST', 'smtp.invalid.example');
        $this->setEnv('CANDY_MAIL_SMTP_PORT', '465');

        $auto = \SugarCraft\Mail\buildTransport();
        $this->assertTrue(
            $this->readProperty($auto, 'implicitTls'),
            'port 465 with the env var unset must keep the implicit-TLS auto-detect',
        );

        $this->setEnv('CANDY_MAIL_SMTP_IMPLICIT_TLS', '0');
        $forcedOff = \SugarCraft\Mail\buildTransport();
        $this->assertFalse(
            $this->readProperty($forcedOff, 'implicitTls'),
            'an explicit 0 must still override the auto-detect (opt-out words stand)',
        );

        unset($_ENV['CANDY_MAIL_SMTP_PORT'], $_ENV['CANDY_MAIL_SMTP_IMPLICIT_TLS']);
        \putenv('CANDY_MAIL_SMTP_PORT');
        \putenv('CANDY_MAIL_SMTP_IMPLICIT_TLS');
    }

    /**
     * CM-07: the missing-value hint must name the inline form the caller can
     * actually run. The template supplies the dashes of its hint slot, so the
     * old raw-token substitution forged 'use ----to=VALUE', and a short -f
     * has to be advised as --from=VALUE — the glued '=' form is long-option
     * only, so repeating the alias would promise a syntax that refuses.
     */
    public function testMissingValueHintNamesTheCanonicalInlineForm(): void
    {
        foreach ([['--to', '--to=VALUE'], ['-f', '--from=VALUE'], ['--reply-to', '--reply-to=VALUE']] as [$flag, $want]) {
            try {
                \SugarCraft\Mail\parseArgs(['candy-mail', $flag]);
                $this->fail("expected a missing-value refusal for {$flag}");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('use ' . $want, $e->getMessage(), "{$flag} hint must carry the canonical inline form");
                $this->assertStringNotContainsString('----', $e->getMessage(), "{$flag} must not forge extra dashes");
            }
        }
    }

    /**
     * The helper itself: dashes stripped, alias resolved, bare name returned
     * for the template's own `--{option}=VALUE` slot.
     */
    public function testCanonicalFlagNameResolvesAliasesAndStripsDashes(): void
    {
        $this->assertSame('to', \SugarCraft\Mail\canonicalFlagName('--to'));
        $this->assertSame('from', \SugarCraft\Mail\canonicalFlagName('-f'));
        $this->assertSame('attach', \SugarCraft\Mail\canonicalFlagName('-a'));
        $this->assertSame('subject', \SugarCraft\Mail\canonicalFlagName('--subject'));
    }

    public function testParseTimeTimeoutValidationAppliesOnEveryPath(): void
    {
        // CM-15: a malformed --timeout is refused at PARSE time — before any
        // transport decision — even when the shape that once swallowed it
        // (a configured Resend key, no SMTP host) is in play.
        $this->setEnv('RESEND_API_KEY', 're_parse_probe');
        try {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('Timeout 99999');
            \SugarCraft\Mail\parseArgs(['candy-mail', '--to', 'a@b.c', '--timeout=99999']);
        } finally {
            unset($_ENV['RESEND_API_KEY']);
            \putenv('RESEND_API_KEY');
        }
    }

    public function testParseTimeTimeoutKeepsABoundedValue(): void
    {
        $args = \SugarCraft\Mail\parseArgs(['candy-mail', '--to', 'a@b.c', '--timeout=60']);
        $this->assertSame('60', $args['opts']['timeout']);
    }

    public function testResendSelectionIgnoresNothingSilently(): void
    {
        // CM-15: the ignored-inputs note names each SMTP-scoped flag the
        // Resend leg silently drops. (The fwrite itself is proven by the
        // BinDispatch subprocess pin; here the list-composition contract
        // stays exercised without stderr capture.)
        $this->setEnv('RESEND_API_KEY', 're_note_probe');
        try {
            $transport = \SugarCraft\Mail\buildTransport(['timeout' => '60', 'helo' => 'h.invalid']);
            $this->assertInstanceOf(\SugarCraft\Mail\ResendTransport::class, $transport);
        } finally {
            unset($_ENV['RESEND_API_KEY']);
            \putenv('RESEND_API_KEY');
        }
    }

    /**
     * CM-16: --transport forces the choice. 'smtp' must ignore a present
     * RESEND_API_KEY (the ladder silently spending the wrong domain's
     * SPF/DKIM identity is the expensive surprise the override exists to
     * stop); 'resend' must refuse without a key instead of falling through;
     * any other value refuses naming the allowed set. Without the flag the
     * ladder stands unchanged.
     */
    public function testTransportOverrideForcesTheLadderChoice(): void
    {
        $this->setEnv('RESEND_API_KEY', 're_test_key');
        $this->setEnv('CANDY_MAIL_SMTP_HOST', 'smtp.invalid.example');
        try {
            $forced = \SugarCraft\Mail\buildTransport(['transport' => 'smtp']);
            $this->assertInstanceOf(\SugarCraft\Mail\SmtpTransport::class, $forced, '--transport=smtp ignores a present key');
            $this->assertSame('smtp.invalid.example', $this->readProperty($forced, 'host'));

            $ladder = \SugarCraft\Mail\buildTransport();
            $this->assertInstanceOf(\SugarCraft\Mail\ResendTransport::class, $ladder, 'without the override the key still wins');

            $keyed = \SugarCraft\Mail\buildTransport(['transport' => 'resend']);
            $this->assertInstanceOf(\SugarCraft\Mail\ResendTransport::class, $keyed);

            unset($_ENV['RESEND_API_KEY']);
            \putenv('RESEND_API_KEY');
            try {
                \SugarCraft\Mail\buildTransport(['transport' => 'resend']);
                $this->fail('--transport=resend without a key must refuse');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('RESEND_API_KEY', $e->getMessage());
            }

            try {
                \SugarCraft\Mail\buildTransport(['transport' => 'mailgun']);
                $this->fail('an unknown transport value must refuse');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('smtp', $e->getMessage());
                $this->assertStringContainsString('resend', $e->getMessage());
            }
        } finally {
            unset($_ENV['RESEND_API_KEY'], $_ENV['CANDY_MAIL_SMTP_HOST']);
            \putenv('RESEND_API_KEY');
            \putenv('CANDY_MAIL_SMTP_HOST');
        }
    }

    public function testHelpStatesTheExitCodeContract(): void
    {
        // CM-14: the help tail must carry the exit-code contract itself, not
        // only the README — an author piping the bin into a cron guard reads
        // `--help`, and 0 = success (help/version/render included) / 1 = every
        // refusal is what makes `|| alert` writable from the terminal alone.
        $help = \SugarCraft\Mail\help('candy-mail');
        $this->assertStringContainsString('Exit codes: 0 success', $help);
        $this->assertStringContainsString('1 every', $help);
        $this->assertStringContainsString('Exit codes table in README.md', $help);
    }

    private function readProperty(object $subject, string $name): mixed
    {
        $property = new \ReflectionProperty($subject, $name);
        $property->setAccessible(true);

        return $property->getValue($subject);
    }
}
