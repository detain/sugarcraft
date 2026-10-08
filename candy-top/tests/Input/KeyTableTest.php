<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Input;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\I18n\T;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\TickRequest;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Top\App;
use SugarCraft\Top\Config\Config;
use SugarCraft\Top\Input\KeyName;
use SugarCraft\Top\Input\KeyTable;
use SugarCraft\Top\Msg\ClockTickMsg;
use SugarCraft\Top\Msg\SetOptionMsg;
use SugarCraft\Top\Panel\GpuPanel;
use SugarCraft\Top\Panel\Panel;
use SugarCraft\Top\Panel\Panels;
use SugarCraft\Top\Source\Fake\FakeGpu;
use SugarCraft\Top\Tests\Support\Cmds;
use SugarCraft\Top\Tests\Support\Harness;
use SugarCraft\Top\Theme\ThemeConfig;

/**
 * The single key table: every row translates, every binding is a btop key
 * name, and the roster covers the keys candy-top handles.
 */
final class KeyTableTest extends TestCase
{
    protected function setUp(): void
    {
        T::reset();
    }

    public function testEveryRowTranslates(): void
    {
        foreach (KeyTable::rows() as $row) {
            if ($row->text !== '') {
                $this->assertNotSame($row->text, $row->description(), 'missing Lang key ' . $row->text);
            }
            if ($row->keysLang) {
                $this->assertNotSame($row->keys, $row->keyLabel(), 'missing Lang key ' . $row->keys);
            }
            $this->assertLessThanOrEqual(20, mb_strlen($row->keyLabel()), 'btop cjust(key, 20)');
            $this->assertLessThanOrEqual(56, mb_strlen($row->description()), 'fits the 78-wide help box');
        }
    }

    public function testBindingsAreTheHandledRoster(): void
    {
        $bindings = KeyTable::bindings();
        $this->assertSame(array_values(array_unique($bindings)), $bindings);
        foreach (['escape', 'm', 'f1', '?', 'h', 'f2', 'o', 'q', 'ctrl+c', '1', '4', '5', '0', '+', '-', 't', 'k', 's', 'N', 'F', 'u', 'O', 'mouse_click', 'p', 'P', 'ctrl+r', 'x', '[', ']', ...KeyName::MODIFIED_ARROWS] as $key) {
            $this->assertContains($key, $bindings);
        }
        foreach (['ctrl+z'] as $later) {
            $this->assertNotContains($later, $bindings, $later . ' is not wired yet (no suspend)');
        }
        foreach ($bindings as $b) {
            $this->assertIsString($b);
        }
    }

    /**
     * The anti-drift pin: drive every key candy-top could receive through a
     * running App (fake roster) in several states, and require
     * handled == documented — a key that changes anything (config, a menu,
     * a panel, a Cmd) must be in the table, and every documented key must
     * act in at least one state.
     */
    public function testBindingsMatchWhatTheHandlersActuallyDo(): void
    {
        $acted = [];
        foreach (self::states() as $state => $app) {
            foreach (self::candidates() as $name => $msg) {
                [$next, $cmd] = $app->update($msg);
                if (self::changed($app, $next, $cmd)) {
                    $acted[$name][] = $state;
                }
            }
        }
        $documented = array_values(array_filter(KeyTable::bindings(), static fn (string $b): bool => !str_starts_with($b, 'mouse_')));
        $missing = array_diff(array_map('strval', array_keys($acted)), $documented);
        $this->assertSame([], array_values($missing), 'keys that act but are not in KeyTable: ' . json_encode(array_intersect_key($acted, array_flip($missing))));
        $dead = array_diff($documented, array_map('strval', array_keys($acted)));
        $this->assertSame([], array_values($dead), 'documented keys that act in no state');
    }

    /** @return array<string, App> */
    private static function states(): array
    {
        $boot = static function (Config $config, string ...$keys): App {
            return self::boot(Panels::standard(Harness::host(), $config, true), $config, ...$keys);
        };
        $filtered = $boot(Config::new(), 'down');
        [$filtered] = $filtered->update(new SetOptionMsg('proc_filter', 'n'));
        // Six accelerators, so every gpu slot key (5-0) can open a box somewhere.
        $sixGpus = Panels::standard(Harness::host(), Config::new(), true);
        $sixGpus['gpu'] = GpuPanel::new(FakeGpu::new(6, 0))->withRoster(GpuPanel::rosterOf(FakeGpu::new(6, 0)->sample()[0]->devices, []));

        return [
            'idle' => $boot(Config::new()),
            // #1476 ctrl_shift_down only acts away from the 55 % default.
            'wide proc' => $boot(Config::new()->with('proc_box_width_percent', 40)),
            'selected' => $boot(Config::new(), 'down', 'down'),
            'tree' => $boot(Config::new()->with('proc_tree', true), 'down', 'down'),
            'vim' => $boot(Config::new()->with('vim_keys', true), 'down', 'down'),
            'detail' => $boot(Config::new(), 'down', 'enter'),
            'filtered' => $filtered,
            'six gpus' => self::boot($sixGpus, Config::new()),
            // btop PR #1873: `[` / `]` act only while the ctr box is shown.
            'containers' => $boot(Config::new()->with('shown_boxes', 'cpu mem net ctr proc')),
        ];
    }

    /** @param array<string, \SugarCraft\Top\Panel\Panel> $panels */
    private static function boot(array $panels, Config $config, string ...$keys): App
    {
        $app = App::start($config, ThemeConfig::new(), Harness::host(), $panels, static fn (): ClockTickMsg => new ClockTickMsg(Harness::TIME), ColorProfile::TrueColor);
        [$app] = $app->update(new WindowSizeMsg(120, 40));
        // Follow-up Cmds too: the ctr box is filled by a Cmd its proc tap returns.
        $pending = [$app->init()];
        while ($pending !== []) {
            foreach (Cmds::run(array_shift($pending)) as $m) {
                if (!$m instanceof TickRequest) {
                    [$app, $next] = $app->update($m);
                    $pending[] = $next;
                }
            }
        }
        foreach ($keys as $k) {
            [$app] = $app->update($k === 'enter' ? new KeyMsg(KeyType::Enter) : new KeyMsg(KeyType::Down));
        }

        return $app;
    }

    /** @return array<string, KeyMsg> btop name => key */
    private static function candidates(): array
    {
        $out = [];
        foreach (KeyType::cases() as $type) {
            if ($type !== KeyType::Char) {
                $msg = new KeyMsg($type, $type === KeyType::Space ? ' ' : '');
                $name = KeyName::key($msg);
                $out[$name !== '' ? $name : 'type:' . $type->value] = $msg;
            }
        }
        $out['shift_tab'] = new KeyMsg(KeyType::Tab, shift: true);
        foreach ([KeyType::Left, KeyType::Right, KeyType::Up, KeyType::Down] as $type) {
            foreach ([[true, false, false], [true, true, false], [true, false, true]] as [$shift, $alt, $ctrl]) {
                $msg = new KeyMsg($type, shift: $shift, alt: $alt, ctrl: $ctrl);
                $name = KeyName::key($msg);
                $out[$name !== '' ? $name : 'mod:' . $type->value . ($alt ? '+alt' : '') . ($ctrl ? '+ctrl' : '')] = $msg;
            }
        }
        for ($c = 0x21; $c <= 0x7e; $c++) {
            $out[chr($c)] = new KeyMsg(KeyType::Char, chr($c));
        }
        foreach (['c', 'r', 'z', 'l', 'g'] as $c) {
            $out['ctrl+' . $c] = new KeyMsg(KeyType::Char, $c, ctrl: true);
        }

        return $out;
    }

    private static function changed(App $before, App $after, ?\Closure $cmd): bool
    {
        if ($cmd !== null || $after->overlay() !== null || $after->config->toArray() !== $before->config->toArray() || $after->preset !== $before->preset) {
            return true;
        }
        foreach ($before->panels() as $box => $panel) {
            if ($after->panel($box) !== $panel) {
                return true;
            }
        }

        return false;
    }
}
