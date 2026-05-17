<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Cli\RecordCommand;
use SugarCraft\Vcr\Format\JsonlFormat;

/**
 * P6.5.2 — `--env` flag with secret-name regex filter. The plan's
 * review focus: "rather strip-too-much than leak; env capture doesn't
 * include the user's full shell environment by default (opt-in)."
 *
 * Filtering is exercised via the pure `RecordCommand::filteredHostEnv()`
 * helper so the assertion runs deterministically against
 * fixture-controlled env, and the integration check confirms `--env`
 * is the only path that lands env on the cassette.
 */
final class RecordCommandEnvFilterTest extends TestCase
{
    private function requirePtySyscalls(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('candy-pty is POSIX-only; Windows ConPTY is a separate port.');
        }
        if (!\extension_loaded('ffi')) {
            $this->markTestSkipped('ext-ffi is required to exercise the libc PTY syscalls.');
        }
        if (!\is_readable('/dev/ptmx') || !\is_writable('/dev/ptmx')) {
            $this->markTestSkipped('/dev/ptmx is unreadable/unwritable on this host.');
        }
        if (!\extension_loaded('pcntl')) {
            $this->markTestSkipped('ext-pcntl is required for controllingTerminal:true spawns.');
        }
        if (!\is_executable('/bin/echo')) {
            $this->markTestSkipped('/bin/echo is not executable on this host.');
        }
    }

    /**
     * @return iterable<string, array{0: string, 1: bool}>
     */
    public static function secretKeyCases(): iterable
    {
        yield 'plain SECRET'      => ['MY_SECRET',          true];
        yield 'plain TOKEN'       => ['GITHUB_TOKEN',       true];
        yield 'plain KEY'         => ['STRIPE_API_KEY',     true];
        yield 'plain PASSWORD'    => ['MYSQL_PASSWORD',     true];
        yield 'plain API'         => ['API_BASE_URL',       true];
        yield 'plain CRED'        => ['DB_CRED',            true];
        yield 'plain AUTH'        => ['AUTH_HEADER',        true];
        yield 'plain PRIV'        => ['SSH_PRIVATE_KEY',    true];
        yield 'mixed case secret' => ['service_secret_v2',  true];

        yield 'PATH stays'        => ['PATH',               false];
        yield 'HOME stays'        => ['HOME',               false];
        yield 'TERM stays'        => ['TERM',               false];
        yield 'PWD stays'         => ['PWD',                false];
        yield 'CI_RUN_ID stays'   => ['CI_RUN_ID',          false];
        yield 'unrelated KEYBOARD edge' => ['KEYBOARD_LAYOUT', true]; // contains "KEY" -> strip
    }

    /**
     * @dataProvider secretKeyCases
     */
    public function testSecretKeyRegexClassifiesEnvVarsConservatively(string $key, bool $shouldStrip): void
    {
        $matched = (bool) \preg_match(RecordCommand::SECRET_KEY_REGEX, $key);
        $this->assertSame(
            $shouldStrip,
            $matched,
            "expected '{$key}' " . ($shouldStrip ? 'STRIPPED' : 'KEPT') . " by SECRET_KEY_REGEX",
        );
    }

    public function testFilteredHostEnvDropsSecretsAndKeepsBenignKeys(): void
    {
        // Inject fixture vars into the process env so we can prove the
        // filter both keeps and strips. ksort confirms determinism.
        $fixtures = [
            'VCR_FIXTURE_TERM'            => 'xterm-fake',
            'VCR_FIXTURE_PATH_OVERRIDE'   => '/fake/path',
            'VCR_FIXTURE_SECRET_KEY'      => 'leaked-if-seen',
            'VCR_FIXTURE_DB_PASSWORD'     => 'leaked-if-seen',
            'VCR_FIXTURE_GH_TOKEN'        => 'leaked-if-seen',
            'VCR_FIXTURE_NOTHING_SPECIAL' => 'visible',
        ];
        $prior = [];
        foreach ($fixtures as $k => $v) {
            $prior[$k] = \getenv($k);
            \putenv("{$k}={$v}");
        }

        try {
            $kept = RecordCommand::filteredHostEnv();

            $this->assertArrayHasKey('VCR_FIXTURE_TERM', $kept);
            $this->assertSame('xterm-fake', $kept['VCR_FIXTURE_TERM']);
            $this->assertArrayHasKey('VCR_FIXTURE_PATH_OVERRIDE', $kept);
            $this->assertArrayHasKey('VCR_FIXTURE_NOTHING_SPECIAL', $kept);

            $this->assertArrayNotHasKey('VCR_FIXTURE_SECRET_KEY', $kept);
            $this->assertArrayNotHasKey('VCR_FIXTURE_DB_PASSWORD', $kept);
            $this->assertArrayNotHasKey('VCR_FIXTURE_GH_TOKEN', $kept);
        } finally {
            foreach ($prior as $k => $v) {
                if ($v === false) {
                    \putenv($k);
                } else {
                    \putenv("{$k}={$v}");
                }
            }
        }
    }

    public function testFilteredHostEnvHonoursCustomRegex(): void
    {
        // Custom regex that strips any key starting with FOO_
        $prior = \getenv('VCR_FIXTURE_FOO_API');
        \putenv('VCR_FIXTURE_FOO_API=stripme');
        \putenv('VCR_FIXTURE_KEEP=keep-me');

        try {
            $kept = RecordCommand::filteredHostEnv('/^VCR_FIXTURE_FOO_/');
            $this->assertArrayHasKey('VCR_FIXTURE_KEEP', $kept);
            $this->assertArrayNotHasKey('VCR_FIXTURE_FOO_API', $kept);
        } finally {
            \putenv('VCR_FIXTURE_KEEP');
            if ($prior === false) {
                \putenv('VCR_FIXTURE_FOO_API');
            } else {
                \putenv("VCR_FIXTURE_FOO_API={$prior}");
            }
        }
    }

    public function testEnvFlagPopulatesCassetteHeader(): void
    {
        $this->requirePtySyscalls();

        \putenv('VCR_FIXTURE_SAFE=hello');
        \putenv('VCR_FIXTURE_OAUTH_TOKEN=should-not-appear');

        $cassette = \tempnam(\sys_get_temp_dir(), 'rec-env-');
        $this->assertIsString($cassette);
        $cmd = new RecordCommand(\fopen('/dev/null', 'r'));
        $stdout = \fopen('php://memory', 'r+');
        $stderr = \fopen('php://memory', 'r+');

        try {
            $rc = $cmd->run(
                ['--env', '--output', $cassette, '--', '/bin/echo', 'env-test'],
                $stdout,
                $stderr,
            );
            $this->assertSame(0, $rc);

            $loaded = (new JsonlFormat())->read($cassette);
            $this->assertArrayHasKey('VCR_FIXTURE_SAFE', $loaded->header->env);
            $this->assertSame('hello', $loaded->header->env['VCR_FIXTURE_SAFE']);
            $this->assertArrayNotHasKey('VCR_FIXTURE_OAUTH_TOKEN', $loaded->header->env);
        } finally {
            \putenv('VCR_FIXTURE_SAFE');
            \putenv('VCR_FIXTURE_OAUTH_TOKEN');
            \fclose($stdout);
            \fclose($stderr);
            if (\file_exists($cassette)) {
                @\unlink($cassette);
            }
        }
    }

    public function testNoEnvFlagLeavesHeaderEnvEmpty(): void
    {
        $this->requirePtySyscalls();

        $cassette = \tempnam(\sys_get_temp_dir(), 'rec-noenv-');
        $this->assertIsString($cassette);
        $cmd = new RecordCommand(\fopen('/dev/null', 'r'));
        $stdout = \fopen('php://memory', 'r+');
        $stderr = \fopen('php://memory', 'r+');

        try {
            $rc = $cmd->run(
                ['--output', $cassette, '--', '/bin/echo', 'no-env'],
                $stdout,
                $stderr,
            );
            $this->assertSame(0, $rc);

            $loaded = (new JsonlFormat())->read($cassette);
            $this->assertSame([], $loaded->header->env, 'env must stay empty without --env');
        } finally {
            \fclose($stdout);
            \fclose($stderr);
            if (\file_exists($cassette)) {
                @\unlink($cassette);
            }
        }
    }

    public function testInvalidEnvRegexExitsTwo(): void
    {
        $cmd = new RecordCommand(\fopen('/dev/null', 'r'));
        $stdout = \fopen('php://memory', 'r+');
        $stderr = \fopen('php://memory', 'r+');

        try {
            $rc = $cmd->run(
                ['--env-regex=(unclosed', '--', '/bin/echo'],
                $stdout,
                $stderr,
            );
            $this->assertSame(2, $rc);
            \rewind($stderr);
            $err = (string) \stream_get_contents($stderr);
            $this->assertStringContainsString('not a valid PCRE pattern', $err);
        } finally {
            \fclose($stdout);
            \fclose($stderr);
        }
    }
}
