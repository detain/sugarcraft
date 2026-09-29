<?php

declare(strict_types=1);

namespace SugarCraft\Kit;

use SugarCraft\Sprinkles\Style;

/**
 * Fluent builder for custom {@see Theme} instances.
 *
 * @internal  Use via {@see Theme::build()}
 */
final class ThemeBuilder
{
    private ?Style $success = null;
    private ?Style $error   = null;
    private ?Style $warn    = null;
    private ?Style $info    = null;
    private ?Style $prompt  = null;
    private ?Style $accent  = null;
    private ?Style $muted   = null;

    /** @return $this */
    public function success(Style $s): self { $this->success = $s; return $this; }

    /** @return $this */
    public function error(Style $s): self { $this->error = $s; return $this; }

    /** @return $this */
    public function warn(Style $s): self { $this->warn = $s; return $this; }

    /** @return $this */
    public function info(Style $s): self { $this->info = $s; return $this; }

    /** @return $this */
    public function prompt(Style $s): self { $this->prompt = $s; return $this; }

    /** @return $this */
    public function accent(Style $s): self { $this->accent = $s; return $this; }

    /** @return $this */
    public function muted(Style $s): self { $this->muted = $s; return $this; }

    /**
     * @throws \InvalidArgumentException if any field is still null
     */
    public function build(): Theme
    {
        return new Theme(
            success: $this->success ?? throw new \InvalidArgumentException('success style is required'),
            error:   $this->error   ?? throw new \InvalidArgumentException('error style is required'),
            warn:    $this->warn    ?? throw new \InvalidArgumentException('warn style is required'),
            info:    $this->info    ?? throw new \InvalidArgumentException('info style is required'),
            prompt:  $this->prompt  ?? throw new \InvalidArgumentException('prompt style is required'),
            accent:  $this->accent  ?? throw new \InvalidArgumentException('accent style is required'),
            muted:   $this->muted   ?? throw new \InvalidArgumentException('muted style is required'),
        );
    }
}
