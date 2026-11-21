<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Server;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Server\Auth\TokenStore;
use SugarCraft\Code\Server\DiscoveryFile;
use SugarCraft\Code\Server\StateDir;
use SugarCraft\Code\Support\PrivateDir;
use SugarCraft\ServeProtocol\Auth\TokenFile;
use SugarCraft\ServeProtocol\Discovery\DiscoveryRecordReader;
use SugarCraft\ServeProtocol\Discovery\StateDirReader;

/**
 * The read-only halves of the serve state (PL-1b, section 4.12): the state
 * directory, the discovery record and the token file are read by
 * sugar-serve-protocol now, and candy-code's classes delegate to it.
 *
 * Each fixture is run through THREE readers: the original candy-code body
 * (kept verbatim below as a reference oracle, taken from before the
 * delegation), candy's delegating method, and the library reader. All three
 * must agree on the value and on the refusal text, so a drift on either side
 * fails here rather than in a running server.
 */
final class DiscoveryReaderParityTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = \sys_get_temp_dir() . '/discovery-parity-' . \bin2hex(\random_bytes(4));
        \mkdir($this->root, 0o700, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testStateDirExistingAgreesWithTheOriginalOnEveryFixture(): void
    {
        $valid = $this->dir('state', 0o700);
        $cases = [
            'missing' => $this->root . '/absent',
            'valid' => $valid,
            'valid with a trailing slash' => $valid . '/',
            'mode 0755' => $this->dir('loose', 0o755),
            'symbolic link to a valid directory' => $this->symlink($valid, 'linked'),
            'regular file' => $this->file('plain', 'not a directory'),
        ];

        foreach ($cases as $name => $path) {
            $expected = self::outcome(static fn (): ?string => self::legacyStateDirExisting($path));
            self::assertSame($expected, self::outcome(static fn (): ?string => StateDir::existing($path)?->path), $name);
            self::assertSame($expected, self::outcome(static fn (): ?string => StateDirReader::existing($path)), $name . ' (library)');
        }
    }

    public function testDiscoveryRecordReadAgreesWithTheOriginalOnEveryFixture(): void
    {
        $record = DiscoveryFile::forThisProcess('1.0.0', 'ws://127.0.0.1:7420/ws', '127.0.0.1', 7420, null, false, null, 1_000);
        $fixtures = [
            'valid record' => null,
            'malformed JSON' => '{"pid": ',
            'empty file' => '',
            'top-level list' => '[1, 2]',
            'top-level scalar' => '42',
            'nested beyond the depth limit' => \str_repeat('[', 9) . \str_repeat(']', 9),
            'record without a url' => '{"pid": 4242}',
        ];

        foreach ($fixtures as $name => $content) {
            $state = StateDir::open($this->dir('record-' . \md5($name), 0o700));
            if ($content === null) {
                $record->write($state);
            } else {
                \file_put_contents($state->discoveryPath(), $content);
            }

            $expected = self::outcome(static fn (): ?array => self::legacyDiscoveryRaw($state->discoveryPath()));
            self::assertSame($expected, self::outcome(static fn (): ?array => DiscoveryRecordReader::read($state->discoveryPath())), $name . ' (library)');

            $expectedRecord = self::outcome(static fn (): ?array => self::legacyDiscoveryRead($state)?->toArray());
            self::assertSame($expectedRecord, self::outcome(static fn (): ?array => DiscoveryFile::read($state)?->toArray()), $name);
        }
    }

    public function testDiscoveryRecordReadRefusesASymbolicLinkAndADirectory(): void
    {
        $record = DiscoveryFile::forThisProcess('1.0.0', 'ws://127.0.0.1:7420/ws', '127.0.0.1', 7420, null, false, null, 1_000);
        $state = StateDir::open($this->dir('linked-record', 0o700));
        $record->write($state);
        $target = $state->path . '/target.json';
        \rename($state->discoveryPath(), $target);
        \symlink($target, $state->discoveryPath());

        $expected = self::outcome(static fn (): ?array => self::legacyDiscoveryRaw($state->discoveryPath()));
        self::assertSame(['value' => null], $expected, 'the original refuses a symbolic link');
        self::assertSame($expected, self::outcome(static fn (): ?array => DiscoveryRecordReader::read($state->discoveryPath())));
        self::assertSame($expected, self::outcome(static fn (): ?array => DiscoveryFile::read($state)?->toArray()));

        $asDirectory = StateDir::open($this->dir('directory-record', 0o700));
        \mkdir($asDirectory->discoveryPath(), 0o700);
        self::assertSame(['value' => null], self::outcome(static fn (): ?array => DiscoveryRecordReader::read($asDirectory->discoveryPath())));
        self::assertSame(['value' => null], self::outcome(static fn (): ?array => DiscoveryFile::read($asDirectory)?->toArray()));
    }

    public function testTokenFileReadAgreesWithTheOriginalOnEveryFixture(): void
    {
        $hex = \str_repeat('ab', 32);
        $fixtures = [
            'missing' => null,
            'valid token with a newline' => ["{$hex}\n", 'file'],
            'valid token with padding' => ["  {$hex}  \n", 'file'],
            'uppercase hex' => [\strtoupper($hex) . "\n", 'file'],
            'token one character short' => [\substr($hex, 1) . "\n", 'file'],
            'not a token' => ["hello\n", 'file'],
            'a directory named token' => [null, 'dir'],
        ];

        foreach ($fixtures as $name => $fixture) {
            $dir = $this->dir('token-' . \md5($name), 0o700);
            if ($fixture !== null) {
                [$content, $kind] = $fixture;
                if ($kind === 'dir') {
                    \mkdir($dir . '/token', 0o700);
                } else {
                    \file_put_contents($dir . '/token', $content);
                }
            }
            $expected = self::outcome(static fn (): ?string => self::legacyTokenFromFile($dir));
            self::assertSame($expected, self::outcome(static fn (): ?string => TokenFile::read($dir)), $name . ' (library)');
        }

        $linked = $this->dir('token-linked', 0o700);
        $target = $this->root . '/token-target';
        \file_put_contents($target, "{$hex}\n");
        \symlink($target, $linked . '/token');
        self::assertSame(
            ['value' => null],
            self::outcome(static fn (): ?string => self::legacyTokenFromFile($linked)),
            'the original refuses a symbolic link',
        );
        self::assertSame(
            ['value' => null],
            self::outcome(static fn (): ?string => TokenFile::read($linked)),
            'the library refuses a symbolic link',
        );
    }

    public function testTokenStoreReturnsTheStoredTokenThroughTheLibraryReader(): void
    {
        $hex = \str_repeat('cd', 32);
        $dir = $this->dir('token-store', 0o700);
        \file_put_contents($dir . '/token', "  {$hex}\n");

        self::assertSame($hex, TokenStore::new($dir)->token());
        self::assertSame(TokenFile::FILE, TokenStore::FILE);
    }

    public function testUnreadableTokenFileIsRefusedNotRotated(): void
    {
        if (\function_exists('posix_geteuid') && \posix_geteuid() === 0) {
            self::markTestSkipped('root reads an unreadable file, so the refusal cannot be provoked');
        }
        $dir = $this->dir('token-unreadable', 0o700);
        \file_put_contents($dir . '/token', \str_repeat('ef', 32) . "\n");
        \chmod($dir . '/token', 0o000);

        $expected = self::outcome(static fn (): ?string => self::legacyTokenFromFile($dir));
        self::assertArrayHasKey('throws', $expected, 'the original refuses to replace an unreadable token');
        self::assertSame($expected, self::outcome(static fn (): ?string => TokenFile::read($dir)));
        self::assertSame($expected, self::outcome(static fn (): ?string => TokenStore::new($dir)->token()));
    }

    /**
     * The pre-PL-1b body of StateDir::existing, verbatim apart from the
     * return type. Reference only: the production method now delegates.
     */
    private static function legacyStateDirExisting(string $path): ?string
    {
        $path = \rtrim($path, '/');
        \clearstatcache(true, $path);
        if (@\lstat($path) === false) {
            return null;
        }
        $reason = PrivateDir::refusal($path, 'server state');
        if ($reason !== null) {
            throw new \RuntimeException($reason);
        }

        return $path;
    }

    /**
     * The pre-PL-1b decode half of DiscoveryFile::read, verbatim. Reference only.
     *
     * @return array<mixed>|null
     */
    private static function legacyDiscoveryRaw(string $path): ?array
    {
        \clearstatcache(true, $path);
        $stat = @\lstat($path);
        if ($stat === false || ((int) $stat['mode'] & 0o170000) !== 0o100000) {
            return null;
        }

        try {
            $data = \json_decode((string) @\file_get_contents($path), true, 8, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return \is_array($data) ? $data : null;
    }

    /** The pre-PL-1b DiscoveryFile::read, verbatim, over the host's own fromArray. Reference only. */
    private static function legacyDiscoveryRead(StateDir $dir): ?DiscoveryFile
    {
        $raw = self::legacyDiscoveryRaw($dir->discoveryPath());

        return $raw === null ? null : DiscoveryFile::fromArray($raw);
    }

    /**
     * The pre-PL-1b file read inside TokenStore::token(), verbatim: the
     * stored token when the file holds one, null when it must be minted.
     * Reference only.
     */
    private static function legacyTokenFromFile(string $dir): ?string
    {
        $path = $dir . '/' . TokenStore::FILE;
        $stored = '';
        if (@\lstat($path) !== false && \is_file($path) && !\is_link($path)) {
            $raw = @\file_get_contents($path);
            if ($raw === false) {
                throw new \RuntimeException(\sprintf('the server token file %s exists but cannot be read; refusing to replace it', $path));
            }
            $stored = \trim($raw);
        }

        return \preg_match('/^[0-9a-f]{64}$/', $stored) === 1 ? $stored : null;
    }

    /**
     * What a reader did, as a comparable value: its return, or the message of
     * the refusal it threw.
     *
     * @return array{value: mixed}|array{throws: string}
     */
    private static function outcome(callable $read): array
    {
        try {
            return ['value' => $read()];
        } catch (\RuntimeException $e) {
            return ['throws' => $e->getMessage()];
        }
    }

    private function dir(string $name, int $mode): string
    {
        $path = $this->root . '/' . $name;
        \mkdir($path, 0o700, true);
        \chmod($path, $mode);

        return $path;
    }

    private function file(string $name, string $content): string
    {
        $path = $this->root . '/' . $name;
        \file_put_contents($path, $content);

        return $path;
    }

    private function symlink(string $target, string $name): string
    {
        $path = $this->root . '/' . $name;
        \symlink($target, $path);

        return $path;
    }

    private function removeTree(string $path): void
    {
        if (\is_link($path) || \is_file($path)) {
            @\chmod($path, 0o600);
            @\unlink($path);

            return;
        }
        if (\is_dir($path)) {
            @\chmod($path, 0o700);
            foreach ((array) \scandir($path) as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $this->removeTree($path . '/' . $entry);
                }
            }
            @\rmdir($path);
        }
    }
}
