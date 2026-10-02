<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\MCP;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\MCP\McpTrustPins;

/**
 * Audit MCP-5: what a server's trust fingerprint covers, and what it does not.
 */
final class McpTrustPinsTest extends TestCase
{
    private const ENTRY = ['command' => 'npx', 'args' => ['-y', 'some-mcp'], 'env' => ['TOKEN' => '${TOKEN}']];

    public function testCommandArgsEnvAndTypeAreEachPinned(): void
    {
        $base = McpTrustPins::fingerprint('stdio', self::ENTRY);

        foreach ([
            'command' => ['command' => 'sh'] + self::ENTRY,
            'args' => ['args' => ['-c', 'curl x|sh']] + self::ENTRY,
            'arg order' => ['args' => ['some-mcp', '-y']] + self::ENTRY,
            'env' => ['env' => ['TOKEN' => '${TOKEN}', 'LD_PRELOAD' => '/tmp/x.so']] + self::ENTRY,
            'an unanticipated key' => self::ENTRY + ['cwd' => '/elsewhere'],
        ] as $what => $entry) {
            self::assertNotSame($base, McpTrustPins::fingerprint('stdio', $entry), $what);
        }
        self::assertNotSame($base, McpTrustPins::fingerprint('http', self::ENTRY), 'type');
    }

    public function testMapOrderAndBoundingKeysDoNotChangeTheFingerprint(): void
    {
        $base = McpTrustPins::fingerprint('stdio', self::ENTRY);

        self::assertSame($base, McpTrustPins::fingerprint('stdio', array_reverse(self::ENTRY, true)));
        self::assertSame($base, McpTrustPins::fingerprint('stdio', self::ENTRY + ['startTimeout' => 90, 'enabled' => true]));
    }

    public function testForeignSpellingsArePinnedAsTheClientWouldBuildThem(): void
    {
        $native = McpTrustPins::pinsFor(['s' => self::ENTRY])['pins']['s'];
        $foreign = McpTrustPins::pinsFor(['s' => [
            'type' => 'local',
            'command' => ['npx', '-y', 'some-mcp'],
            'environment' => ['TOKEN' => '${TOKEN}'],
        ]])['pins']['s'];

        self::assertSame($native['fingerprint'], $foreign['fingerprint']);
    }

    public function testTheSummaryNamesEnvVariablesButNeverTheirValues(): void
    {
        $summary = McpTrustPins::summary('stdio', ['command' => 'srv', 'env' => ['API_KEY' => 'sk-live-secret']]);

        self::assertSame('srv [env: API_KEY]', $summary);
    }

    public function testDisabledEntriesAreNotPinnedAndMalformedOnesAreNamed(): void
    {
        $result = McpTrustPins::pinsFor([
            'off' => ['command' => 'x', 'enabled' => false],
            'bad' => ['command' => []],
            'ok' => ['command' => 'y'],
        ]);

        self::assertSame(['ok'], array_keys($result['pins']));
        self::assertSame(['bad'], $result['invalid']);
    }

    public function testARecordRoundTripsAndAnUnusableFileIsEmpty(): void
    {
        $path = sys_get_temp_dir() . '/sc_pins_' . bin2hex(random_bytes(6)) . '.json';
        try {
            self::assertNull(McpTrustPins::load($path)->forRoot('/r'));

            $pin = McpTrustPins::pin('stdio', self::ENTRY);
            McpTrustPins::load($path)->withRoot('/r', ['s' => $pin])->save();
            self::assertSame(['s' => $pin], McpTrustPins::load($path)->forRoot('/r'));
            self::assertSame(0600, fileperms($path) & 0777);

            file_put_contents($path, '{not json');
            self::assertNull(McpTrustPins::load($path)->forRoot('/r'));
        } finally {
            @unlink($path);
        }
    }
}
