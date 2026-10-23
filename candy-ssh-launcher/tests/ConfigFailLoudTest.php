<?php

declare(strict_types=1);

namespace SugarCraft\SshLauncher\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SugarCraft\SshLauncher\Config;

/**
 * Fail-loud pins for the silent-swallow paths closed by docs-fix-1023
 * (rows C1/M5 unreadable files, C5 bool coercion, C6 inline flow, and the
 * scalar-list shorthand). Each test names the refusal it requires — a
 * neutered guard must turn exactly these red, never a pass-through default.
 */
final class ConfigFailLoudTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            @chmod($path, 0600);
            @unlink($path);
        }
        $this->tempFiles = [];
    }

    private function writeTemp(string $contents, string $suffix = '.yml'): string
    {
        $path = tempnam(sys_get_temp_dir(), 'sshlaunch-cfg-') . $suffix;
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return $path;
    }

    private function requireNonRoot(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('a chmod-000 file is readable for root; refusing to fake the pin');
        }
    }

    public function testLoadRefusesAnUnreadableFileInsteadOfEmptyingIt(): void
    {
        $this->requireNonRoot();
        $path = $this->writeTemp("- name: prod\n  host: prod.example.com\n");
        chmod($path, 0000);

        try {
            Config::load($path);
            self::fail('expected a loud refusal for the unreadable file');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('could not be read', $e->getMessage());
            self::assertStringContainsString($path, $e->getMessage());
        } finally {
            chmod($path, 0600);
        }
    }

    public function testImportFromSshConfigRefusesAnUnreadableFile(): void
    {
        $this->requireNonRoot();
        $path = $this->writeTemp("Host alpha\n  User alice\n", '');
        chmod($path, 0000);

        try {
            Config::importFromSshConfig($path);
            self::fail('expected a loud refusal for the unreadable file');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('could not be read', $e->getMessage());
        } finally {
            chmod($path, 0600);
        }
    }

    public function testYamlInlineFlowOptionsIsRefusedWithTheFixNamed(): void
    {
        $raw = "- name: prod\n  host: prod.example.com\n  options: [ServerAliveInterval=30, BatchMode=yes]\n";

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('inline flow collection not supported');

        Config::parse($raw, 'wishlist.yml');
    }

    public function testYamlInlineFlowMappingIsRefused(): void
    {
        $raw = "- name: prod\n  host: prod.example.com\n  identityFiles: {a: b}\n";

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('inline flow collection not supported');

        Config::parse($raw, 'wishlist.yml');
    }

    public function testQuotedBracketScalarIsAnOrdinaryString(): void
    {
        // The equals/quote boundary: a QUOTED "[x]" is a literal scalar,
        // never a flow collection.
        $raw = "- name: \"[box]\"\n  host: prod.example.com\n";
        $endpoints = Config::parse($raw, 'wishlist.yml');

        self::assertSame('[box]', $endpoints[0]->name);
    }

    public function testBooleanNameIsRefusedNotStringifiedToOne(): void
    {
        // C5: `name: yes` maps to bool via the YAML subset; the old code
        // accepted it (is_scalar) and ssh received user/host strings
        // polluted with the literal "1".
        $raw = "- name: yes\n  host: prod.example.com\n";

        try {
            Config::parse($raw, 'wishlist.yml');
            self::fail('expected the bool field to be refused');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('name', $e->getMessage());
            self::assertStringContainsString('bool', $e->getMessage());
        }
    }

    public function testBooleanHostAndUserAreRefusedToo(): void
    {
        foreach (['host' => "- name: prod\n  host: no\n", 'user' => "- name: prod\n  host: p.test\n  user: no\n"] as $field => $raw) {
            try {
                Config::parse($raw, 'wishlist.yml');
                self::fail("expected {$field} to be refused");
            } catch (InvalidArgumentException $e) {
                self::assertStringContainsString($field, $e->getMessage());
            }
        }
    }

    public function testScalarIdentityFilesShorthandBecomesSingleItemList(): void
    {
        $raw = "- name: prod\n  host: prod.example.com\n  identityFiles: /home/me/id_ed25519\n";
        $endpoints = Config::parse($raw, 'wishlist.yml');

        self::assertSame(['/home/me/id_ed25519'], $endpoints[0]->identityFiles);
    }

    public function testScalarOptionsAndNestedListAgree(): void
    {
        $shorthand = Config::parse("- name: p\n  host: p.test\n  options: BatchMode=yes\n", 'wishlist.yml');
        $nested = Config::parse("- name: p\n  host: p.test\n  options:\n    - BatchMode=yes\n", 'wishlist.yml');

        self::assertSame($nested[0]->options, $shorthand[0]->options);
        self::assertSame(['BatchMode=yes'], $shorthand[0]->options);
    }

    public function testValueLessOptionsKeyStillMeansEmptyList(): void
    {
        // `options:` with nothing under it stays an empty list — the
        // shorthand covers scalars only, and an explicit empty is a choice.
        $raw = "- name: prod\n  host: prod.example.com\n  options:\n";
        $endpoints = Config::parse($raw, 'wishlist.yml');

        self::assertSame([], $endpoints[0]->options);
    }
}
