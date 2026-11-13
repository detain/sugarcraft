<?php

declare(strict_types=1);

namespace SugarCraft\Mail\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Mail\MailgunTransport;
use SugarCraft\Mail\ResendTransport;
use SugarCraft\Mail\SmtpTransport;

/**
 * CM-11 CLI seam: the transport ladder (Resend > Mailgun > SMTP), the
 * `--transport=mailgun` override, its credential refusals, and `tui` outside
 * a terminal. In-process via buildTransport(), plus one child process for
 * the non-tty tui refusal.
 */
final class MailgunCliTest extends TestCase
{
    private const NAMES = [
        'RESEND_API_KEY', 'CANDY_MAIL_MAILGUN_API_KEY', 'CANDY_MAIL_MAILGUN_DOMAIN',
        'CANDY_MAIL_MAILGUN_REGION', 'CANDY_MAIL_SMTP_HOST',
    ];

    /** @var array<string, string|false> */
    private array $saved = [];

    protected function setUp(): void
    {
        require_once __DIR__ . '/../bin/candy-mail';
        foreach (self::NAMES as $n) {
            $this->saved[$n] = \getenv($n, true);
            unset($_ENV[$n]);
            \putenv($n);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $n => $v) {
            unset($_ENV[$n]);
            \putenv($n . ($v === false ? '' : '=' . $v));
        }
    }

    private function env(string $n, string $v): void
    {
        $_ENV[$n] = $v;
        \putenv("$n=$v");
    }

    public function testMailgunKeyAndDomainSelectTheTransportWhenResendIsAbsent(): void
    {
        $this->env('CANDY_MAIL_MAILGUN_API_KEY', 'key-1');
        $this->env('CANDY_MAIL_MAILGUN_DOMAIN', 'mg.example.com');
        $this->env('CANDY_MAIL_SMTP_HOST', 'smtp.invalid.example');

        $t = \SugarCraft\Mail\buildTransport();
        $this->assertInstanceOf(MailgunTransport::class, $t);
        $this->assertSame('https://api.mailgun.net/v3/mg.example.com/messages', $t->endpoint());
    }

    public function testRegionEnvSelectsTheEuHost(): void
    {
        $this->env('CANDY_MAIL_MAILGUN_API_KEY', 'key-1');
        $this->env('CANDY_MAIL_MAILGUN_DOMAIN', 'mg.example.com');
        $this->env('CANDY_MAIL_MAILGUN_REGION', 'eu');

        $t = \SugarCraft\Mail\buildTransport(['transport' => 'mailgun']);
        $this->assertSame('https://api.eu.mailgun.net/v3/mg.example.com/messages', $t->endpoint());
    }

    public function testResendStillWinsTheLadderAndOverridesPickEither(): void
    {
        $this->env('RESEND_API_KEY', 're_key');
        $this->env('CANDY_MAIL_MAILGUN_API_KEY', 'key-1');
        $this->env('CANDY_MAIL_MAILGUN_DOMAIN', 'mg.example.com');
        $this->env('CANDY_MAIL_SMTP_HOST', 'smtp.invalid.example');

        $this->assertInstanceOf(ResendTransport::class, \SugarCraft\Mail\buildTransport());
        $this->assertInstanceOf(MailgunTransport::class, \SugarCraft\Mail\buildTransport(['transport' => 'mailgun']));
        $this->assertInstanceOf(SmtpTransport::class, \SugarCraft\Mail\buildTransport(['transport' => 'smtp']));
    }

    public function testForcedMailgunWithoutCredentialsRefusesNamingTheVariable(): void
    {
        try {
            \SugarCraft\Mail\buildTransport(['transport' => 'mailgun']);
            $this->fail('no key must refuse');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('CANDY_MAIL_MAILGUN_API_KEY', $e->getMessage());
        }
        $this->env('CANDY_MAIL_MAILGUN_API_KEY', 'key-1');
        try {
            \SugarCraft\Mail\buildTransport(['transport' => 'mailgun']);
            $this->fail('no domain must refuse');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('CANDY_MAIL_MAILGUN_DOMAIN', $e->getMessage());
        }
    }

    public function testBadRegionEnvRefuses(): void
    {
        $this->env('CANDY_MAIL_MAILGUN_API_KEY', 'key-1');
        $this->env('CANDY_MAIL_MAILGUN_DOMAIN', 'mg.example.com');
        $this->env('CANDY_MAIL_MAILGUN_REGION', 'mars');
        $this->expectException(\InvalidArgumentException::class);
        \SugarCraft\Mail\buildTransport();
    }

    public function testHelpNamesTheMailgunKnobs(): void
    {
        $help = \SugarCraft\Mail\help('candy-mail');
        foreach (['CANDY_MAIL_MAILGUN_API_KEY', 'CANDY_MAIL_MAILGUN_DOMAIN', 'CANDY_MAIL_MAILGUN_REGION', 'smtp|resend|mailgun', 'tui'] as $needle) {
            $this->assertStringContainsString($needle, $help);
        }
    }

    public function testTuiOutsideATerminalRefusesWithoutHanging(): void
    {
        $bin  = \realpath(__DIR__ . '/../bin/candy-mail');
        $proc = \proc_open([\PHP_BINARY, $bin, 'tui'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($proc);
        \fclose($pipes[0]);
        $out    = \stream_get_contents($pipes[1]);
        $err    = \stream_get_contents($pipes[2]);
        $status = \proc_close($proc);

        $this->assertSame(1, $status);
        $this->assertSame('', $out);
        $this->assertStringContainsString('interactive terminal', $err);
    }
}
