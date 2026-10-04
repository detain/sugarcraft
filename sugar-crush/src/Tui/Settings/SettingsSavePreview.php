<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui\Settings;

use SugarCraft\Core\Util\Width;
use SugarCraft\Crush\Config\Settings\ApplyMode;
use SugarCraft\Crush\Config\Settings\SettingsSchema;
use SugarCraft\Crush\Config\Settings\SettingsTier;
use SugarCraft\Crush\Theme;
use SugarCraft\Diff\Diff;
use SugarCraft\Diff\DiffOptions;
use SugarCraft\Diff\LineKind;
use SugarCraft\Sprinkles\Style;

/**
 * What a settings save will do, shown before it does it (roadmap N-P2,
 * Appendix N §4.4): the file, a unified diff of that file's JSON before and
 * after (sugar-diff), when each change applies, and anything that blocks the
 * save.
 *
 * "Saved" never implies "applied", so the apply summary is part of the
 * preview: a next-turn key reaches the next turn on its own, a restart key
 * needs a relaunch, and — because the running turn read its settings when it
 * started — a save made mid-turn applies from the next turn at the earliest.
 *
 * Pure data and rendering: the file was read by whoever built it
 * ({@see \SugarCraft\Crush\App\App::previewSettings()}), so {@see lines()} does
 * no I/O.
 */
final class SettingsSavePreview
{
    /**
     * @param array<string, mixed> $set
     * @param list<string> $unset
     * @param array<string, string> $refusals by key ('*' for the tier)
     * @param list<string> $notes advisories that do not block the save
     */
    private function __construct(
        public readonly SettingsTier $tier,
        public readonly ?string $path,
        public readonly string $before,
        public readonly string $after,
        public readonly array $set,
        public readonly array $unset,
        public readonly array $refusals,
        public readonly array $notes = [],
    ) {
    }

    /**
     * @param array<string, mixed> $before the file's current object
     * @param array<string, mixed> $after the object the save would write
     * @param array<string, mixed> $set
     * @param list<string> $unset
     * @param array<string, string> $refusals
     */
    public static function new(
        SettingsTier $tier,
        ?string $path,
        array $before,
        array $after,
        array $set,
        array $unset = [],
        array $refusals = [],
    ): self {
        return new self($tier, $path, self::json($before), self::json($after), $set, array_values($unset), $refusals);
    }

    /**
     * The same preview with advisories that do not block it — e.g. that the
     * value now overrides your `settings.json`, or that a higher tier still wins.
     *
     * @param list<string> $notes
     */
    public function withNotes(array $notes): self
    {
        return new self($this->tier, $this->path, $this->before, $this->after, $this->set, $this->unset, $this->refusals, array_values($notes));
    }

    /** A preview that cannot proceed, e.g. the target file is unreadable. */
    public static function blocked(SettingsTier $tier, ?string $path, string $reason): self
    {
        return new self($tier, $path, '', '', [], [], ['*' => $reason]);
    }

    /** Whether confirming would write: something to write and nothing refused. */
    public function canSave(): bool
    {
        return $this->refusals === [] && ($this->set !== [] || $this->unset !== []);
    }

    /** @return list<string> the keys this save sets or resets */
    public function changed(): array
    {
        return [...array_map('strval', array_keys($this->set)), ...$this->unset];
    }

    public function diff(): Diff
    {
        return Diff::compute($this->before, $this->after, DiffOptions::new()->withContextLines(2));
    }

    /**
     * How many of the changed keys apply when, as one line:
     * `2 next turn · 1 restart`.
     */
    public function applySummary(): string
    {
        $counts = [];
        foreach ($this->changed() as $key) {
            $mode = SettingsSchema::byKey($key)?->applyMode ?? ApplyMode::Restart;
            $counts[$mode->badge()] = ($counts[$mode->badge()] ?? 0) + 1;
        }

        $parts = [];
        foreach ($counts as $badge => $n) {
            $parts[] = "{$n} {$badge}";
        }

        return implode(' · ', $parts);
    }

    /**
     * The preview, one styled line per entry, each at most `$width` cells.
     *
     * @return list<string>
     */
    public function lines(Theme $theme, int $width, bool $turnRunning = false): array
    {
        $width = max(1, $width);
        $muted = Style::new()->foreground($theme->shellMuted);
        $head = Style::new()->foreground($theme->shellPrimary)->bold();
        $text = Style::new()->foreground($theme->shellForeground);
        $error = Style::new()->foreground($theme->shellError);
        $fit = static fn (string $s): string => Width::truncate($s, $width);

        $lines = [$head->render($fit('Save to ' . $this->tier->label()))];
        if ($this->path !== null) {
            $lines[] = $muted->render(Width::truncateMiddle($this->path, $width));
        }

        foreach ($this->refusals as $reason) {
            $lines[] = $error->render($fit('✗ ' . $reason));
        }

        if ($this->refusals !== [] && $this->set === [] && $this->unset === []) {
            return $lines;
        }

        $lines[] = '';
        $diff = $this->diff();
        if ($diff->isEmpty()) {
            $lines[] = $muted->render($fit('No change to the file.'));
        }

        foreach ($diff->hunks as $hunk) {
            $lines[] = $muted->render($fit($hunk->header()));
            foreach ($hunk->lines() as $line) {
                $style = match ($line->kind) {
                    LineKind::Insert => Style::new()->foreground($theme->shellSuccess),
                    LineKind::Delete => $error,
                    LineKind::Equal => $text,
                };
                $lines[] = $style->render($fit($line->marker() . $line->text));
            }
        }

        $lines[] = '';
        $summary = $this->applySummary();
        if ($summary !== '') {
            $lines[] = $text->render($fit('Applies: ' . $summary));
        }

        foreach ($this->notes as $note) {
            $lines[] = Style::new()->foreground($theme->shellWarning)->render($fit('! ' . $note));
        }

        if ($turnRunning) {
            $lines[] = $muted->render($fit('A turn is running — it keeps the settings it began with.'));
        }

        return $lines;
    }

    /** @param array<string, mixed> $data */
    private static function json(array $data): string
    {
        // An object even when empty, so "{}" diffs against the first key added.
        $json = json_encode($data === [] ? new \stdClass() : $data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);

        return $json === false ? '' : $json . "\n";
    }
}
