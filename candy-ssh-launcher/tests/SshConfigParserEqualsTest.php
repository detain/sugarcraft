<?php

declare(strict_types=1);

namespace SugarCraft\SshLauncher\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\SshLauncher\Endpoint;
use SugarCraft\SshLauncher\SshConfigParser;

/**
 * Pins for the ssh_config(5) equals-form gap (docs-review-1023 M1/C2,
 * 2026-10-23): `Key=value` is LEGAL grammar ("if the first character after
 * the keyword is an equal sign it is ignored"), `Host=web1` included. The
 * old whitespace-only regex silently DROPPED such lines — and because a
 * dropped `Host=` header failed to open a new block, every option line that
 * followed leaked into the PREVIOUS block's hosts.
 *
 * These fixtures are adversarial on purpose: the shape of each one makes
 * the leak observable through a keyword the earlier block does not already
 * define, so reverting the parser to `\s+`-only cannot hide behind
 * first-obtained-wins.
 */
final class SshConfigParserEqualsTest extends TestCase
{
    /**
     * @return array<string,Endpoint>
     */
    private static function byName(array $endpoints): array
    {
        $indexed = [];
        foreach ($endpoints as $endpoint) {
            $indexed[$endpoint->name] = $endpoint;
        }

        return $indexed;
    }

    public function testEqualsFormHostHeaderOpensItsOwnBlock(): void
    {
        $raw = implode("\n", [
            'Host alpha',
            '  User alice',
            'Host=beta',
            '  Port 2222',
        ]);

        $byName = self::byName((new SshConfigParser())->parse($raw));

        // Old parser: 'Host=beta' was not recognised as a header, so this
        // second block never existed AND its 'Port 2222' leaked into alpha.
        self::assertSame(['alpha', 'beta'], array_keys($byName));
        self::assertSame(22, $byName['alpha']->port, 'options must not leak across an equals-form block boundary');
        self::assertSame(2222, $byName['beta']->port);
        self::assertSame('alice', $byName['alpha']->user);
        self::assertNull($byName['beta']->user);
    }

    public function testEqualsFormKeywordsParse(): void
    {
        $raw = implode("\n", [
            'Host=web1',
            '  HostName=web1.example.com',
            '  User=bob',
            '  Port=2222',
            '  ProxyJump=gate',
            '  IdentityFile=/home/bob/.ssh/id_web1',
        ]);

        $byName = self::byName((new SshConfigParser())->parse($raw));
        $web1 = $byName['web1'];

        self::assertSame('web1.example.com', $web1->host);
        self::assertSame('bob', $web1->user);
        self::assertSame(2222, $web1->port);
        self::assertSame('gate', $web1->proxyJump);
        self::assertSame(['/home/bob/.ssh/id_web1'], $web1->identityFiles);
    }

    public function testEqualsFormToleratesSurroundingWhitespace(): void
    {
        $raw = implode("\n", [
            'Host = web2',
            '  User = carol',
            '  Port =2223',
            '  HostName= web2.example.com',
        ]);

        $byName = self::byName((new SshConfigParser())->parse($raw));
        $web2 = $byName['web2'];

        self::assertSame('web2.example.com', $web2->host);
        self::assertSame('carol', $web2->user);
        self::assertSame(2223, $web2->port);
    }

    public function testEqualsInsideArgumentIsPreserved(): void
    {
        // Only the FIRST separator binds; argument-internal '=' survives
        // (ssh_config(5): the equals is consumed only when it immediately
        // follows the keyword).
        $raw = "Host opt\n  User=x=y\n";

        $byName = self::byName((new SshConfigParser())->parse($raw));

        self::assertSame('x=y', $byName['opt']->user);
    }

    public function testEqualsFormBlockStillReceivesWildcardOptions(): void
    {
        $raw = implode("\n", [
            'Host=only-eq',
            '  User=dave',
            'Host *',
            '  Port 2200',
        ]);

        $byName = self::byName((new SshConfigParser())->parse($raw));

        self::assertSame('dave', $byName['only-eq']->user);
        self::assertSame(2200, $byName['only-eq']->port);
    }

    public function testBareHostEqualsWithNoPatternOpensNothing(): void
    {
        $raw = "Host =\n  User ghost\n";

        self::assertSame([], (new SshConfigParser())->parse($raw));
    }

    public function testEqualsFormOptionsBeforeAnyHostAreIgnored(): void
    {
        $raw = implode("\n", [
            'User=global-eq',
            'Host real',
        ]);

        $byName = self::byName((new SshConfigParser())->parse($raw));

        self::assertSame(['real'], array_keys($byName));
        self::assertNull($byName['real']->user);
    }

    public function testWhitespaceFormStillParsesAlongsideEqualsForm(): void
    {
        $raw = implode("\n", [
            'Host mix',
            '  User spaced',
            '  Port=2231',
        ]);

        $byName = self::byName((new SshConfigParser())->parse($raw));

        self::assertSame('spaced', $byName['mix']->user);
        self::assertSame(2231, $byName['mix']->port);
    }

    public function testRepeatedPatternOnOneHostLineMintsOneEndpoint(): void
    {
        // docs-review-1023 C4: a pattern listed twice on the header line
        // used to duplicate the endpoint (and its selection entry).
        $raw = "Host dup1 dup1 dup2 dup1\n";

        $byName = self::byName((new SshConfigParser())->parse($raw));

        self::assertSame(['dup1', 'dup2'], array_keys($byName));
    }

    public function testPatternMatchingIsLiteralNotGlobLike(): void
    {
        // Honest-subset documentation (M3/C3): OpenSSH would cross-apply a
        // `Host web*` block to web1; this parser matches patterns LITERALLY
        // (or universally for a bare `*`), so the options must NOT bleed.
        $raw = implode("\n", [
            'Host web*',
            '  User globbed',
            'Host web1',
        ]);

        $byName = self::byName((new SshConfigParser())->parse($raw));

        self::assertSame('globbed', $byName['web*']->user);
        self::assertNull($byName['web1']->user);
    }

    public function testExpandPathFallsBackToPasswdHomeWithoutHomeEnv(): void
    {
        if (!function_exists('posix_getpwuid') || !function_exists('posix_geteuid')) {
            self::markTestSkipped('ext-posix required for the passwd-home fallback');
        }

        $pw = posix_getpwuid(posix_geteuid());
        self::assertIsArray($pw, 'posix_getpwuid of the effective user must resolve here');

        $previous = getenv('HOME');
        putenv('HOME');
        try {
            $byName = self::byName((new SshConfigParser())->parse(
                "Host pw\n  IdentityFile=~/ids/id_ed25519\n"
            ));

            self::assertSame(
                rtrim(((string) $pw['dir']), '/') . '/ids/id_ed25519',
                $byName['pw']->identityFiles[0],
                'without $HOME the effective user\'s passwd dir must be used, not /root'
            );
            self::assertStringNotContainsString('/root/ids', $byName['pw']->identityFiles[0]);
        } finally {
            if ($previous === false) {
                putenv('HOME');
            } else {
                putenv('HOME=' . $previous);
            }
        }
    }

    public function testEqualsFormIdentityFileExpandsTilde(): void
    {
        $previous = getenv('HOME');
        putenv('HOME=/tmp/sshlaunch-fixture-home');
        try {
            $byName = self::byName((new SshConfigParser())->parse(
                "Host eq\n  IdentityFile=~/.ssh/id_eq\n"
            ));

            self::assertSame(['/tmp/sshlaunch-fixture-home/.ssh/id_eq'], $byName['eq']->identityFiles);
        } finally {
            if ($previous === false) {
                putenv('HOME');
            } else {
                putenv('HOME=' . $previous);
            }
        }
    }
}
