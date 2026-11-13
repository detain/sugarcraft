<?php

declare(strict_types=1);

namespace SugarCraft\Mail\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionAttribute;
use ReflectionMethod;
use SugarCraft\Mail\Attachment;
use SugarCraft\Mail\ResendTransport;
use SugarCraft\Mail\SmtpTransport;

/**
 * Adversarial pins for the lib-reviews-1102 credential/protocol CRITICALs:
 *  - C2  Attachment::withCid() must pass the same RFC 2392 gate as inline().
 *  - C4  transports holding credentials must refuse serialize()/resurrection
 *        and must carry #[\SensitiveParameter] on the secret constructor
 *        argument.
 */
final class SecretHygieneTest extends TestCase
{
    private const SMTP_SECRET  = 'sup3rs3cr3t-pw';
    private const RESEND_SECRET = 're_secretTOKEN';

    // ---- C2: Content-ID is gated on every assignment path ----------------

    public function testWithCidRefusesEveryForgedContentIdShape(): void
    {
        $attachment = Attachment::fromContent('attachment bytes', 'note.txt');

        foreach ([
            "a>b\r\nX-Forged: yes",   // early angle-close + forged header line
            'spaced cid',             // Content-Disposition style param smuggling
            '>',                      // bare early close
            "cid:ok host@x",          // space inside the addr-spec
            "trail\0null",            // control byte
        ] as $hostile) {
            try {
                $attachment->withCid($hostile);
                $this->fail('forged cid accepted via withCid(): ' . \addcslashes($hostile, "\0..\37"));
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('Invalid Content-ID format', $e->getMessage());
            }
        }

        // The legal grammar still passes, and the value survives.
        $ok = $attachment->withCid('logo-1');
        $this->assertSame('logo-1', $ok->cid);
        $ok2 = $attachment->withCid('cid:user@mail.example.com');
        $this->assertSame('cid:user@mail.example.com', $ok2->cid);
    }

    public function testConstructorPathFromContentCarriesNoUnguardedCidDoor(): void
    {
        // fromContent mints with cid null; the only mutator is withCid, which
        // is now gated (previous probe: withCid was the open door inline()
        // did not have).
        $attachment = Attachment::fromContent('x', 'a.bin');
        $this->assertNull($attachment->cid);
    }

    // ---- C4: serialize/resurrection refusals ------------------------------

    public function testSmtpTransportRefusesSerializationEntirely(): void
    {
        $transport = new SmtpTransport('smtp.example.com', 587, 'user', self::SMTP_SECRET);
        try {
            $blob = \serialize($transport);
            $this->fail('serialize() must refuse a credential-holding transport, produced: ' . $blob);
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('cannot be serialised', $e->getMessage());
        }
    }

    public function testResendTransportRefusesSerializationEntirely(): void
    {
        $transport = new ResendTransport(self::RESEND_SECRET);
        try {
            \serialize($transport);
            $this->fail('serialize() must refuse a credential-holding transport');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('cannot be serialised', $e->getMessage());
        }
    }

    public function testVarExportResurrectionThrowsInsteadOfMintingALiveCredential(): void
    {
        $transport = new SmtpTransport('smtp.example.com', 587, 'user', self::SMTP_SECRET);
        $export    = \var_export($transport, true);

        // Disclosed honest remainder (pinned so a future change cannot claim
        // silence): the TEXT of a var_export of a live object is not hookable
        // while the plaintext lives in a property. Treat it as a memory dump.
        $this->assertStringContainsString(self::SMTP_SECRET, $export);

        // But the only path from that text back to a LIVE transport is
        // __set_state, and it refuses:
        try {
            eval('$resurrected = ' . $export . ';');
            $this->fail('var_export() resurrection must throw');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('cannot be serialised', $e->getMessage());
        }
    }

    public function testVarDumpAndPrintRStayMasked(): void
    {
        $transport = new SmtpTransport('smtp.example.com', 587, 'user', self::SMTP_SECRET);

        \ob_start();
        \var_dump($transport);
        $dumped = (string) \ob_get_clean();
        $this->assertStringNotContainsString(self::SMTP_SECRET, $dumped);
        $this->assertStringContainsString('****', $dumped);

        \ob_start();
        \print_r($transport);
        $printed = (string) \ob_get_clean();
        $this->assertStringNotContainsString(self::SMTP_SECRET, $printed);

        $resend = new ResendTransport(self::RESEND_SECRET);
        \ob_start();
        \var_dump($resend);
        $resendDumped = (string) \ob_get_clean();
        $this->assertStringNotContainsString(self::RESEND_SECRET, $resendDumped);
    }

    public function testTheSecretConstructorArgumentsCarryTheSensitiveAttribute(): void
    {
        // Structural pin: redaction of trace arguments is the engine's job —
        // it only happens while the attribute is on the right parameter
        // (mutation: move/remove it and this goes red).
        $smtpParams = (new ReflectionMethod(SmtpTransport::class, '__construct'))->getParameters();
        $this->assertSame('password', $smtpParams[3]->getName());
        $this->assertContainsSensitiveParameter($smtpParams[3]);

        $resendParams = (new ReflectionMethod(ResendTransport::class, '__construct'))->getParameters();
        $this->assertSame('apiKey', $resendParams[0]->getName());
        $this->assertContainsSensitiveParameter($resendParams[0]);
    }

    private function assertContainsSensitiveParameter(\ReflectionParameter $parameter): void
    {
        $names = \array_map(
            static fn (ReflectionAttribute $a): string => $a->getName(),
            $parameter->getAttributes()
        );
        $this->assertContains(\SensitiveParameter::class, $names, $parameter->getName() . ' lost its trace redaction');
    }
}
