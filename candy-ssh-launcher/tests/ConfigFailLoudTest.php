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

    public function testYamlBlockMappingUnderListFieldIsRefused(): void
    {
        // F-19 (Step 17): 'Key: value' lines indented under an options: list
        // used to flatten into silently-ignored unknown top-level keys — the
        // field never reached ssh and nothing was reported. Now refused by
        // name, field and line.
        $raw = implode("\n", [
            '- name: prod',
            '  host: p.test',
            '  options:',
            '    Command: "echo hi"',
        ]) . "\n";
        try {
            Config::parse($raw, 'wishlist.yml');
            self::fail('expected a named refusal');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('options', $e->getMessage());
            self::assertStringContainsString('Command: "echo hi"', $e->getMessage());
            // -011: the number slot carries the physical line number now,
            // not a copy of the content — a neutered slot-swap goes red here.
            self::assertStringContainsString('line 4: ', $e->getMessage());
        }
    }

    public function testUnparseableYamlReportsLineNumberAndContentSeparately(): void
    {
        // -011: `{line}` is the number, `{found}` the offending content —
        // de/ja/it locales read "alla riga 7: <content>", never a word in
        // the number slot. The expected message is built from the en file
        // so a wording edit re-gates this pin deliberately.
        /** @var array<string,string> $en */
        $en = require \dirname(__DIR__) . '/lang/en.php';
        $raw = implode("\n", [
            '- name: prod',
            '!!!garbage!!!',
        ]) . "\n";

        try {
            Config::parse($raw, 'wishlist.yml');
            self::fail('expected the garbage line to be refused');
        } catch (\RuntimeException $e) {
            $expected = str_replace(['{line}', '{found}'], ['2', '!!!garbage!!!'], $en['config.yaml_unparseable']);
            self::assertSame($expected, $e->getMessage());
        }
    }

    public function testHostileYamlBytesNeverReachTheExceptionMessage(): void
    {
        // -026: the fuzz corpus produced messages with NUL, C1, and
        // invalid UTF-8. Config::parse() is the advertised programmatic
        // API — it must hand every caller a printable string, no matter
        // what bin/cli does downstream.
        $path = $this->writeTemp("- name: prod\n\x00\x01\x1b[31mbe-\xC2\x80st\xFF\xFE\r\n");
        $hostile = "\x00\x01\x1b[31mbe-\xC2\x80st\xFF\xFE\r";

        try {
            Config::load($path);
            self::fail('expected the hostile line to be refused');
        } catch (\RuntimeException $e) {
            self::assertStringNotContainsString("\x00", $e->getMessage());
            self::assertStringNotContainsString("\x1b", $e->getMessage());
            self::assertStringNotContainsString($hostile, $e->getMessage());
            // /u makes this fail-closed: a broken UTF-8 message cannot match.
            self::assertSame(1, preg_match('/^[\x09\P{C}]*$/u', $e->getMessage()), 'message: ' . addcslashes($e->getMessage(), "\x00-\x1f\x7f-\x9f"));
            self::assertStringContainsString('be-', $e->getMessage());
        }
    }

    public function testMidLineCarriageReturnIsNeutralisedInDiagnostics(): void
    {
        // -026: CR is a cursor motion, not a character — a mid-line CR in
        // the echoed content could overwrite the earlier part of the same
        // log line. It is mapped to a space, never spliced away.
        $path = $this->writeTemp("- name: prod\nbad\rfoo bar\r\n");

        try {
            Config::load($path);
            self::fail('expected the mid-line CR to be refused');
        } catch (\RuntimeException $e) {
            self::assertStringNotContainsString("\r", $e->getMessage());
            self::assertStringContainsString('foo bar', $e->getMessage());
            self::assertSame(1, preg_match('/^[\x09\P{C}]*$/u', $e->getMessage()));
        }
    }

    /**
     * Run a callable with Config's load warnings captured.
     *
     * @return array{0:mixed,1:string} the return value and the warning text
     */
    private function withWarningCapture(callable $fn): array
    {
        $stream = fopen('php://memory', 'r+');
        Config::setWarningStream($stream);
        try {
            $result = $fn();
        } finally {
            Config::setWarningStream(null);
            rewind($stream);
            $text = (string) stream_get_contents($stream);
            fclose($stream);
        }

        return [$result, $text];
    }

    public function testTypoEntryFieldWarnsInsteadOfVanishingSilently(): void
    {
        // -004: `prot: 2222` used to connect on 22 with zero signal.
        $raw = "- name: prod\n  host: p.test\n  prot: 2222\n";
        [$endpoints, $warnings] = $this->withWarningCapture(
            static fn () => Config::parse($raw, 'wishlist.yml')
        );
        self::assertSame(22, $endpoints[0]->port, 'an unknown key must still not bind');
        self::assertStringContainsString('prot', $warnings);
        self::assertStringContainsString('unknown field', $warnings);
    }

    public function testEntryMissingFieldNamesTheEntryPosition(): void
    {
        // -052: hand-bisecting a multi-entry file is the failure being closed.
        $json = '[{"name":"a","host":"b"},{"name":"x"}]';
        try {
            Config::parse($json, 'wishlist.json');
            self::fail('expected a named refusal');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('entry #2', $e->getMessage());
            self::assertStringContainsString('host', $e->getMessage());
        }
    }

    public function testFoldedNearMissKeyNamesTheCanonicalField(): void
    {
        // -004: `proxyjump` case-folds onto proxyJump — it must be REPORTED
        // as a likely typo, not silently accepted (documented spellings only).
        $raw = "- name: prod\n  host: p.test\n  proxyjump: jump.test\n";
        [$endpoints, $warnings] = $this->withWarningCapture(
            static fn () => Config::parse($raw, 'wishlist.yml')
        );
        self::assertNull($endpoints[0]->proxyJump);
        self::assertStringContainsString('proxyJump', $warnings, 'the near-miss must name the canonical field');
    }

    public function testUpstreamArraySpellingsResolveAsAliases(): void
    {
        // -004 (CSL-PARITY-01): proxy_jump/identity_files are the upstream
        // spellings; they used to no-op in silence — now they bind.
        $raw = "- name: prod\n  host: p.test\n  proxy_jump: jump.test\n  identity_files: /x/id_ed25519\n";
        [$endpoints, $warnings] = $this->withWarningCapture(
            static fn () => Config::parse($raw, 'wishlist.yml')
        );
        self::assertSame('jump.test', $endpoints[0]->proxyJump);
        self::assertSame(['/x/id_ed25519'], $endpoints[0]->identityFiles);
        self::assertSame('', $warnings, 'documented aliases must not warn');
    }

    public function testRemoteCommandSnakeAliasBindsWithoutWarning(): void
    {
        // -007: remote_command joins the documented alias table; a row using
        // the snake spelling binds remoteCommand, rides the command positional
        // at argv build, and stays quiet on the unknown-key channel.
        $raw = "- name: kprod\n  host: k.test\n  remote_command: kubectl exec -it bash\n";
        [$endpoints, $warnings] = $this->withWarningCapture(
            static fn () => Config::parse($raw, 'wishlist.yml')
        );
        self::assertSame('kubectl exec -it bash', $endpoints[0]->remoteCommand);
        self::assertSame(
            ['ssh', '--', 'k.test', 'kubectl exec -it bash'],
            $endpoints[0]->toSshArgv(),
            'the command must ride after the destination (ssh synopsis)',
        );
        self::assertSame('', $warnings, 'documented aliases must not warn');
    }

    public function testFlagsListAndScalarShorthandBind(): void
    {
        // -033: the flags lane shares the options list machinery — nested
        // `- item` lines plus the bare-scalar one-item shorthand.
        $raw = "- name: tty\n  host: t.test\n  flags:\n    - t\n    - N\n"
             . "- name: single\n  host: s.test\n  flags: X\n";
        [$endpoints, $warnings] = $this->withWarningCapture(
            static fn () => Config::parse($raw, 'wishlist.yml')
        );
        self::assertSame(['t', 'N'], $endpoints[0]->flags);
        self::assertSame(['ssh', '-t', '-N', '--', 't.test'], $endpoints[0]->toSshArgv());
        self::assertSame(['X'], $endpoints[1]->flags);
        self::assertSame(['ssh', '-X', '--', 's.test'], $endpoints[1]->toSshArgv());
        self::assertSame('', $warnings);
    }

    public function testCanonicalKeyWinsOverItsAlias(): void
    {
        // Documented precedence (FIELD_ALIASES): canonical first, alias fills.
        $json = '[{"name":"prod","host":"p.test","proxyJump":"a.test","proxy_jump":"b.test",'
            . '"identityFiles":["/a"],"identity_files":["/b"],'
            . '"remoteCommand":"a.cmd","remote_command":"b.cmd"}]';
        [$endpoints, $warnings] = $this->withWarningCapture(
            static fn () => Config::parse($json, 'wishlist.json')
        );
        self::assertSame('a.test', $endpoints[0]->proxyJump);
        self::assertSame(['/a'], $endpoints[0]->identityFiles);
        self::assertSame('a.cmd', $endpoints[0]->remoteCommand, '-007: canonical beats alias here too');
        self::assertSame('', $warnings);
    }

    public function testDuplicateEndpointNamesWarnAndBothLoad(): void
    {
        // -100 (QA-005): names are identifiers; the silence violated fail-loud.
        $raw = "- name: dup\n  host: one.test\n- name: dup\n  host: two.test\n";
        [$endpoints, $warnings] = $this->withWarningCapture(
            static fn () => Config::parse($raw, 'wishlist.yml')
        );
        self::assertCount(2, $endpoints, 'the warning arm keeps both rows selectable');
        self::assertStringContainsString('dup', $warnings);
        self::assertStringContainsString('duplicate', $warnings);
    }

    public function testEmptyNameIsRefusedNotRenderedBlank(): void
    {
        // -023: README documents name as required (non-empty); the F-22
        // host_empty guard now has its name-side twin.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('empty name');
        Config::parse('[{"name":"","host":"h.test"}]', 'wishlist.json');
    }
}
