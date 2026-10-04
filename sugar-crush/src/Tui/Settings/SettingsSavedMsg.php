<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui\Settings;

use SugarCraft\Crush\App\Msg;
use SugarCraft\Crush\Config\Settings\SettingsTier;

/**
 * The settings editor's save finished (roadmap N-P2, Appendix N §4.6): the
 * keys it carried, the tier and file they went to, or why nothing was written.
 *
 * Produced by the Cmd {@see \SugarCraft\Crush\App\App::confirmSettingsSave()}
 * returns — the write is I/O, so it runs as a command, never inside
 * `update()` — and answered by the App, which hands it to the open editor.
 * Feedback stays in the editor's own status line: a "setting changed" row in
 * the transcript would be sent to the provider every turn.
 */
final readonly class SettingsSavedMsg implements Msg
{
    /**
     * @param list<string> $changed the keys the save set or reset
     */
    private function __construct(
        public SettingsTier $tier,
        public array $changed,
        public ?string $path,
        public ?string $error,
    ) {
    }

    /** @param list<string> $changed */
    public static function saved(SettingsTier $tier, array $changed, string $path): self
    {
        return new self($tier, array_values($changed), $path, null);
    }

    /** @param list<string> $changed */
    public static function failed(SettingsTier $tier, array $changed, string $error): self
    {
        return new self($tier, array_values($changed), null, $error);
    }

    public function ok(): bool
    {
        return $this->error === null;
    }
}
