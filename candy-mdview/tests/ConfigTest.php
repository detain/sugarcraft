<?php

declare(strict_types=1);

namespace SugarCraft\MdView\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\MdView\Config;
use SugarCraft\MdView\MdViewModel;

/**
 * The preferences door (finding 036): what it reads, what it refuses, and
 * the named-refusal law for everything in between.
 */
final class ConfigTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/mdview-config-' . uniqid('', true);
        $this->assertTrue(mkdir($this->dir, 0o777, true));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $entry) {
            if (is_dir($entry)) {
                @rmdir($entry);
            } else {
                @unlink($entry);
            }
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function write(string $json, string $name = 'mdview.json'): string
    {
        $path = $this->dir . '/' . $name;
        $this->assertNotFalse(file_put_contents($path, $json));
        return $path;
    }

    public function testAbsentFileYieldsTheEmptyConfig(): void
    {
        $config = Config::load($this->dir . '/nope.json');
        $this->assertNull($config->theme);
        $this->assertNull($config->style);
        $this->assertNull($config->width);
        $this->assertNull($config->pager);
        $this->assertSame([], $config->keymap);
        $this->assertNull($config->path);
    }

    public function testValidFileReadsEverySetting(): void
    {
        $path   = $this->write('{"theme":"dracula","style":"tokyo-night","width":100,"pager":true,"keymap":{"quit":"ctrl-q","reload":"r"}}');
        $config = Config::load($path);
        $this->assertSame('dracula', $config->theme);
        $this->assertSame('tokyo-night', $config->style);
        $this->assertSame(100, $config->width);
        $this->assertTrue($config->pager);
        $this->assertSame(['quit' => 'ctrl-q', 'reload' => 'r'], $config->keymap);
        $this->assertSame($path, $config->path);
    }

    public function testWidthZeroIsTheExplicitNoWrapNotAuto(): void
    {
        // The three-state contract S11 gave the flag must survive the file:
        // "width":0 reads as 0 (no-wrap), NOT as null (auto).
        $config = Config::load($this->write('{"width":0}'));
        $this->assertSame(0, $config->width);
        $absent = Config::load($this->write('{}'));
        $this->assertNull($absent->width);
    }

    public function testUnknownKeyRefusesNamingFileAndKey(): void
    {
        $path = $this->write('{"them":"dark","widht":80}');
        try {
            Config::load($path);
            $this->fail('a typo\'d preference key must refuse, not be ignored');
        } catch (\InvalidArgumentException $refusal) {
            $this->assertStringContainsString($path, $refusal->getMessage());
            // Both offenders, sorted — the announcement is complete.
            $this->assertStringContainsString('them, widht', $refusal->getMessage());
        }
    }

    public function testKeymapOnlyAcceptsTheActionsThisModelOwns(): void
    {
        // Every DEFAULT_KEYMAP action remaps; anything else refuses by
        // name (a silently dropped remap is finding 036 in miniature).
        $pairs = [];
        foreach (array_keys(MdViewModel::DEFAULT_KEYMAP) as $action) {
            $pairs[] = '"' . $action . '":"x"';
        }
        Config::load($this->write('{"keymap":{' . implode(',', $pairs) . '}}'));
        try {
            Config::load($this->write('{"keymap":{"scroll":"x"}}'));
            $this->fail('an unknown keymap action must refuse');
        } catch (\InvalidArgumentException $refusal) {
            $this->assertStringContainsString('keymap.scroll', $refusal->getMessage());
        }
    }

    public function testKeymapChordGrammarIsExact(): void
    {
        foreach (['"x"', '"ctrl-x"', '"alt-x"'] as $ok) {
            Config::load($this->write('{"keymap":{"quit":' . $ok . '}}'));
        }
        foreach (['"Ctrl-X"', '"xx"', '""', '"esc"', '5'] as $bad) {
            try {
                $path = $this->write('{"keymap":{"quit":' . $bad . '}}', 'gram-' . md5($bad) . '.json');
                Config::load($path);
                $this->fail('chord ' . $bad . ' must refuse');
            } catch (\InvalidArgumentException $refusal) {
                $this->assertStringContainsString('keymap.quit', $refusal->getMessage());
            }
        }
    }

    public function testNonObjectPayloadRefusesAsInvalid(): void
    {
        foreach (['"just a string"', '[1,2,3]', '{"theme":'] as $payload) {
            try {
                $path = $this->write($payload, 'invalid-' . md5($payload) . '.json');
                Config::load($path);
                $this->fail('payload ' . $payload . ' must refuse');
            } catch (\InvalidArgumentException $refusal) {
                $this->assertStringContainsString($path, $refusal->getMessage());
            }
        }
    }

    public function testTypedValuesAreHeldToTheirTypes(): void
    {
        // NOTE: `"keymap":[]` cannot be refused — an empty JSON array
        // decodes to the same PHP [] as an empty object; nothing to remap,
        // nothing to complain about. A LIST with entries does refuse.
        foreach (['{"width":"100"}', '{"pager":"yes"}', '{"theme":""}', '{"width":-1}', '{"width":10000}', '{"keymap":[1]}', '{"keymap":{"quit":true}}'] as $payload) {
            try {
                $path = $this->write($payload, 'typed-' . md5($payload) . '.json');
                Config::load($path);
                $this->fail('payload ' . $payload . ' must refuse');
            } catch (\InvalidArgumentException $refusal) {
                $this->assertStringContainsString($path, $refusal->getMessage());
            }
        }
    }

    public function testDirectoryAtTheConfigPathRefusesNotLooksAbsent(): void
    {
        // is_file() is the door law shared with every other file entrance:
        // a directory named as the config file is a named refusal.
        try {
            Config::load($this->dir);
            $this->fail('a directory config must refuse');
        } catch (\InvalidArgumentException $refusal) {
            $this->assertStringContainsString($this->dir, $refusal->getMessage());
        }
    }

    public function testFifoAtTheConfigPathRefusesWithoutHanging(): void
    {
        if (!function_exists('posix_mkfifo')) {
            $this->markTestSkipped('ext-posix required for the FIFO door');
        }
        $path = $this->dir . '/fifo.json';
        $this->assertTrue(posix_mkfifo($path, 0o666));
        $started = microtime(true);
        try {
            Config::load($path);
            $this->fail('a FIFO config must refuse');
        } catch (\InvalidArgumentException $refusal) {
            $this->assertStringContainsString($path, $refusal->getMessage());
        } finally {
            unlink($path);
        }
        $this->assertLessThan(1.0, microtime(true) - $started, 'refusal is immediate, never a blocked read');
    }

    public function testOversizedConfigRefusesAgainstTheCap(): void
    {
        $path   = $this->dir . '/big.json';
        $handle = fopen($path, 'wb');
        $this->assertNotFalse($handle);
        $this->assertTrue(ftruncate($handle, Config::MAX_BYTES + 1));
        fclose($handle);
        try {
            Config::load($path);
            $this->fail('an oversized config must refuse');
        } catch (\InvalidArgumentException $refusal) {
            $this->assertStringContainsString((string) Config::MAX_BYTES, $refusal->getMessage());
        }
    }

    public function testLocationChainPrefersSeamThenXdgThenHome(): void
    {
        $this->withEnv(['MDVIEW_CONFIG' => '/exact/path.json'], function (): void {
            $this->assertSame('/exact/path.json', Config::defaultPath());
        });
        // The hermetic escape: an explicitly empty seam disables file lookup
        // even when HOME/XDG would resolve.
        $this->withEnv(['MDVIEW_CONFIG' => '', 'XDG_CONFIG_HOME' => '/xdg', 'HOME' => '/home/u'], function (): void {
            $this->assertNull(Config::defaultPath());
        });
        $this->withEnv(['MDVIEW_CONFIG' => null, 'XDG_CONFIG_HOME' => '/xdg', 'HOME' => '/home/u'], function (): void {
            $this->assertSame('/xdg/mdview/mdview.json', Config::defaultPath());
        });
        $this->withEnv(['MDVIEW_CONFIG' => null, 'XDG_CONFIG_HOME' => null, 'HOME' => '/home/u'], function (): void {
            $this->assertSame('/home/u/.config/mdview/mdview.json', Config::defaultPath());
        });
        $this->withEnv(['MDVIEW_CONFIG' => null, 'XDG_CONFIG_HOME' => null, 'HOME' => null], function (): void {
            $this->assertNull(Config::defaultPath());
        });
    }

    /**
     * @param array<string,string|null> $vars null unsets; both reader layers
     *                                        ($_SERVER and getenv) are driven
     */
    private function withEnv(array $vars, \Closure $observe): void
    {
        $restore = [];
        foreach ($vars as $key => $value) {
            $restore[$key] = [getenv($key), $_SERVER[$key] ?? null];
            if ($value === null) {
                putenv($key);
                unset($_SERVER[$key]);
            } else {
                putenv($key . '=' . $value);
                $_SERVER[$key] = $value;
            }
        }
        try {
            $observe();
        } finally {
            foreach ($restore as $key => [$envValue, $serverValue]) {
                if ($envValue === false || $envValue === null) {
                    putenv($key);
                } else {
                    putenv($key . '=' . $envValue);
                }
                if ($serverValue === null) {
                    unset($_SERVER[$key]);
                } else {
                    $_SERVER[$key] = $serverValue;
                }
            }
        }
    }
}
