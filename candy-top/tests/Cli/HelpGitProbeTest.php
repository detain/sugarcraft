<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\I18n\T;
use SugarCraft\Top\Cli\Help;

/**
 * Finding 143: the checkout-version probe resolves git from a fixed set of
 * absolute candidates and spawns that resolved path under a fixed PATH —
 * the launcher's PATH can no longer steer the probe at a shadowed binary.
 * Both arms are pinned with a scripted stand-in: the spawn record shows
 * which argv the probe chose and which PATH framed it.
 */
final class HelpGitProbeTest extends TestCase
{
    private string $dir;
    private string $script;
    private string $capture;

    protected function setUp(): void
    {
        T::reset();
        $this->dir = \sys_get_temp_dir() . '/candy-top-gitprobe-' . \bin2hex(\random_bytes(6));
        $this->capture = $this->dir . '/spawn.log';
        $this->script = $this->dir . '/fake-git';
        $this->assertNotFalse(\mkdir($this->dir, 0700, true));
        $body = "#!/bin/sh\necho \"argv0=\$0 PATH=\$PATH\" >> " . $this->capture . "\n"
            . "case \"\$1\" in\n"
            . "  rev-parse) printf 'abcdef1\\n' ;;\n"
            . "  status) printf '' ;;\n"
            . "esac\nexit 0\n";
        $this->assertNotFalse(\file_put_contents($this->script, $body));
        $this->assertNotFalse(\chmod($this->script, 0755));
    }

    protected function tearDown(): void
    {
        Help::pretendGitBinaryForTesting(null);
        foreach ([$this->capture, $this->script, $this->dir] as $path) {
            if (\is_file($path)) {
                @\unlink($path);
            } elseif (\is_dir($path)) {
                @\rmdir($path);
            }
        }
    }

    public function testTheProbeSpawnsTheResolvedBinaryUnderAFixedPath(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('the scripted stand-in is a POSIX shell script');
        }

        Help::pretendGitBinaryForTesting($this->script);
        $version = Help::versionString();

        $log = (string) \file_get_contents($this->capture);
        $this->assertStringContainsString('argv0=' . $this->script, $log, 'the probe spawns the resolved absolute candidate, never a bare name');
        $this->assertStringContainsString('PATH=/usr/bin:/bin', $log, 'the spawn env carries the fixed PATH, not the launcher ambient one');
        $this->assertStringContainsString('abcdef1', $version, 'the candidate answer reaches the version surface');
    }

    public function testAnUnresolvableGitRefusesQuietlyToTheComposerReference(): void
    {
        Help::pretendGitBinaryForTesting('');
        $version = Help::versionString();

        $this->assertStringNotContainsString('abcdef1', $version);
        $this->assertFileDoesNotExist($this->capture, 'no resolution means no spawn at all');
        $this->assertNotSame('unknown', $version, 'the Composer reference still answers --version');
    }

    public function testTheCandidateListIsAbsolutePathsOnly(): void
    {
        // The rule behind the fix: nothing in the resolution set can be
        // shadowed by PATH. A reflection-free pin: every candidate must
        // read as an absolute path on its own platform.
        $candidates = (new \ReflectionClass(Help::class))->getConstant('GIT_CANDIDATES');
        $this->assertIsArray($candidates);
        $this->assertEveryCandidateIsAbsolute($candidates);
    }

    /** @param list<string> $candidates */
    private function assertEveryCandidateIsAbsolute(array $candidates): void
    {
        foreach ($candidates as $candidate) {
            $this->assertMatchesRegularExpression(
                '/^([A-Za-z]:[\\\\\/]|\/)/',
                $candidate,
                'a bare-name candidate would reintroduce the PATH-steering hole',
            );
        }
    }
}
