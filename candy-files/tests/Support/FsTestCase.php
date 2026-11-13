<?php

declare(strict_types=1);

namespace SugarCraft\Files\Tests\Support;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Files\FsLister;
use SugarCraft\Files\Manager;

/**
 * Shared real-filesystem fixture for the CF-52..CF-33 suites: a private
 * tmp tree per test, a Manager rooted on it, and key/cursor helpers.
 * Real bytes on disk (not fake listers) because every one of those
 * features is about what the filesystem actually says.
 */
abstract class FsTestCase extends TestCase
{
    protected string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/candyfiles-t-' . bin2hex(random_bytes(6));
        mkdir($this->tmp, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->rm($this->tmp);
    }

    protected function rm(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        @chmod($path, 0755);
        foreach (@scandir($path) ?: [] as $item) {
            if ($item !== '.' && $item !== '..') {
                $this->rm($path . '/' . $item);
            }
        }
        @rmdir($path);
    }

    protected function manager(?string $right = null): Manager
    {
        return Manager::start($this->tmp, $right ?? $this->tmp, FsLister::lister());
    }

    protected function press(Manager $m, string $rune, bool $ctrl = false): Manager
    {
        [$m] = $m->update(new KeyMsg(KeyType::Char, $rune, ctrl: $ctrl));
        return $m;
    }

    protected function key(Manager $m, KeyType $t): Manager
    {
        [$m] = $m->update(new KeyMsg($t));
        return $m;
    }

    protected function cursorOn(Manager $m, string $name): Manager
    {
        for ($i = 0, $n = count($m->activePane()->entries); $i < $n; $i++) {
            if ($m->activePane()->currentEntry()?->name === $name) {
                return $m;
            }
            $m = $this->press($m, 'j');
        }
        $this->fail("cursor never reached {$name}");
    }
}
