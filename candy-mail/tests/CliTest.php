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
        $this->assertInstanceOf(\SugarCraft\Mail\ResendTransport::class, \SugarCraft\Mail\buildTransport());

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

    private function readProperty(object $subject, string $name): mixed
    {
        $property = new \ReflectionProperty($subject, $name);
        $property->setAccessible(true);

        return $property->getValue($subject);
    }
}
