<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Tests\Support;

use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\ScriptUi\Runtime\ColourGate;
use SugarCraft\Style\Style;

/**
 * H1 (CI-watch 2026-11-13): `ColourGate::install()` pins a PROCESS-WIDE
 * ambient profile, and every `Style::render()` clamps to it — so any test
 * that calls `Application::run()` leaks the cap its environment resolved
 * into every later test of the same phpunit process. On a CI runner that
 * leaked cap is NoTty/Ansi256 (no TERM/COLORTERM), so style-needle
 * assertions pass in a dev shell and fail in CI purely on inherited state.
 *
 * This seam makes each style-asserting test start from the unrestricted
 * ambient and hand back whatever it found, so files no longer ride each
 * other's residue. (Trait property names are per-file-unique by PHPUnit
 * composition; the two traits coexist safely in one class.)
 */
trait AmbientIsolation
{
    private ?ColorProfile $savedAmbientForIsolation = null;

    protected function isolateAmbient(): void
    {
        $this->savedAmbientForIsolation = Style::defaultProfile();
        ColourGate::resetAmbient();
    }

    protected function releaseAmbient(): void
    {
        Style::setDefaultProfile($this->savedAmbientForIsolation);
        $this->savedAmbientForIsolation = null;
    }
}
