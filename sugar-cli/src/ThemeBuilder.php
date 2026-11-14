<?php

declare(strict_types=1);

namespace SugarCraft\Cli;

use SugarCraft\Style\Style;

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
     * Assemble the theme. Every slot must have been set — a theme half
     * populated would silently render some lines unstyled, so a missing
     * style is an authoring error, not a default.
     *
     * Building CLEARS the slate: a reused builder that kept the previous
     * theme's slots would silently merge old styling into a half-specified
     * new theme — wrong bytes with no error at any call site. After
     * build() the next pass must re-set all seven slots (or hear the
     * same authoring-error complaint a fresh builder makes).
     *
     * @throws \InvalidArgumentException if any of the seven slots is still unset
     */
    public function build(): Theme
    {
        // Snapshot, clear, then validate the snapshot: the throws below see
        // exactly the caller's slots, while the object itself is left empty.
        $success = $this->success;
        $error   = $this->error;
        $warn    = $this->warn;
        $info    = $this->info;
        $prompt  = $this->prompt;
        $accent  = $this->accent;
        $muted   = $this->muted;

        $this->success = $this->error = $this->warn = $this->info = null;
        $this->prompt = $this->accent = $this->muted = null;

        return new Theme(
            success: $success ?? throw new \InvalidArgumentException('ThemeBuilder::build(): success style is required (all seven slots must be set).'),
            error:   $error   ?? throw new \InvalidArgumentException('ThemeBuilder::build(): error style is required (all seven slots must be set).'),
            warn:    $warn    ?? throw new \InvalidArgumentException('ThemeBuilder::build(): warn style is required (all seven slots must be set).'),
            info:    $info    ?? throw new \InvalidArgumentException('ThemeBuilder::build(): info style is required (all seven slots must be set).'),
            prompt:  $prompt  ?? throw new \InvalidArgumentException('ThemeBuilder::build(): prompt style is required (all seven slots must be set).'),
            accent:  $accent  ?? throw new \InvalidArgumentException('ThemeBuilder::build(): accent style is required (all seven slots must be set).'),
            muted:   $muted   ?? throw new \InvalidArgumentException('ThemeBuilder::build(): muted style is required (all seven slots must be set).'),
        );
    }
}
