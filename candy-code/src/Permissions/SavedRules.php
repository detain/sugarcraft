<?php

declare(strict_types=1);

namespace SugarCraft\Code\Permissions;

use SugarCraft\Code\Chat;
use SugarCraft\Code\Config\Settings\SettingsTier;
use SugarCraft\Code\Config\Settings\SettingsWriter;
use SugarCraft\Code\Lang;

/**
 * The user's SAVED permission rules — the `permissionRules` array in the
 * user-tier config, the half of the "always" answer that outlives the
 * session. `p` at a prompt appends one allow-rule here
 * ({@see PermissionPrompt::persistRules()} delegates to {@see self::append()});
 * `/permissions saved` lists them and `/permissions forget` takes one out
 * (C-SEC-6.a, the step that made this list readable and reversible from the
 * transcript instead of only by hand-editing config).
 *
 * Every access goes through the workspace's {@see SettingsWriter} — the
 * settings editor's own write door — so the schema's validation of the key
 * (a value the launch would not load whole is refused) is this service's
 * validation too, and this file never opens config.json itself.
 *
 * WHY A SERVICE AT ALL: the write used to live inside PermissionPrompt, the
 * only door into the key, which made the saved rules add-only in practice —
 * there was no honest way to show them or take one back without duplicating
 * the reader. The list, the append and the removal share the file because
 * they share the invariants: first-match-wins order rides down the list, an
 * existing allow-pattern is not written twice, and a `deny` the user wrote by
 * hand keeps its place in front of anything `p` saves after it.
 */
final class SavedRules
{
    public function __construct(private readonly ?SettingsWriter $writer)
    {
    }

    /**
     * The saved rules in stored order, as the transcript numbers them:
     * `pattern` plus `action`, one entry per LOADABLE rule.
     *
     * A row the launch would skip is not shown and not counted — the same
     * {@see self::loadableRow()} filter `remove()` numbers against and the
     * same rows {@see \SugarCraft\Code\Cli\Bootstrap::permissionRules()}
     * refuses with its launch warning. Fabricating them as `allow` would
     * over-count against this report's own first-match-wins header and print
     * an action no gate will ever try (C-SEC-6.a review r1, MINOR).
     *
     * A read failure propagates — a caller showing the list must say the
     * read failed rather than show an empty list and imply the user has
     * saved nothing.
     *
     * @return list<array{pattern: string, action: string}>
     */
    public function list(): array
    {
        $stored = $this->door()->current(SettingsTier::You)['permissionRules'] ?? [];
        $rules = [];
        foreach (\is_array($stored) ? $stored : [] as $rule) {
            $row = self::loadableRow($rule);
            if ($row === null) {
                continue;
            }
            $rules[] = $row;
        }

        return $rules;
    }

    /**
     * ONE loadable-row filter, shared by {@see self::list()} and the
     * `<n>` numbering in {@see self::remove()} so the list the user counts
     * on and the rows the remover addresses cannot drift. It mirrors what
     * {@see \SugarCraft\Code\Cli\Bootstrap::permissionRules()} loads at
     * launch: a stored entry must be an array with a string pattern and an
     * action the {@see PermissionAction} enum actually knows; anything else
     * answers null — shown never, numbered never, and never fabricated as
     * an `allow`. (The launch refuses a FEW rows deeper than this — a
     * pattern its grammar cannot parse — and those still ride here as
     * loadable; the grammar stays the loader's jurisdiction, this filter
     * only answers "could this row ever mean anything", the question the
     * count and the numbering must agree on.)
     *
     * @return ?array{pattern: string, action: string}
     */
    private static function loadableRow(mixed $rule): ?array
    {
        if (!\is_array($rule)
            || !\is_string($rule['pattern'] ?? null)
            || !\is_string($rule['action'] ?? null)
            || PermissionAction::tryFrom($rule['action']) === null) {
            return null;
        }

        return ['pattern' => $rule['pattern'], 'action' => $rule['action']];
    }

    /**
     * Where the list lives, for the reply that names its file.
     */
    public function path(): string
    {
        return (string) $this->door()->targetPath(SettingsTier::You);
    }

    /**
     * Append $patterns to the user's `permissionRules` as allow-rules.
     *
     * Existing entries ride in front of the new ones: rules are
     * first-match-wins, so a `deny` the user wrote keeps refusing what `p`
     * saves, and the grant only answers questions the mode would have asked
     * anyway. An allow-pattern already there is not written twice.
     *
     * Returns the notice the transcript shows — where it landed, or why it
     * did not. A failed save is REPORTED, never fatal, exactly as a model
     * choice that cannot be persisted still switches for the session: the
     * session grant `p` rides on has already been recorded.
     *
     * @param list<string> $patterns
     */
    public function append(array $patterns): string
    {
        $scope = implode(', ', $patterns);
        try {
            $writer = $this->door();
            $config = $writer->current(SettingsTier::You);
            $existing = $config['permissionRules'] ?? [];
            $existing = \is_array($existing)
                ? array_values(array_filter($existing, static fn (mixed $rule): bool => \is_array($rule)))
                : [];
            $have = [];
            foreach ($existing as $rule) {
                if (($rule['action'] ?? null) === 'allow' && \is_string($rule['pattern'] ?? null)) {
                    $have[$rule['pattern']] = true;
                }
            }

            $next = $existing;
            foreach ($patterns as $pattern) {
                if (!isset($have[$pattern])) {
                    $next[] = ['pattern' => $pattern, 'action' => 'allow'];
                }
            }
            $path = $next === $existing
                ? (string) $writer->targetPath(SettingsTier::You)
                : $writer->write(SettingsTier::You, ['permissionRules' => $next]);

            return Lang::t('chat.permission.persisted', [
                'scope' => $scope,
                'path' => Chat::reportField($path),
            ]);
        } catch (\Throwable $e) {
            return Lang::t('chat.permission.not_persisted', [
                'scope' => $scope,
                'error' => Chat::reportField($e->getMessage()),
            ]);
        }
    }

    /**
     * Take out the rule the transcript numbered $which (1-based, counted by
     * the SAME {@see self::loadableRow()} filter {@see self::list()} shows —
     * a row the list hides is unnumberable here, and the rows it does show
     * answer to the numbers the user saw), or every loadable rule whose
     * stored pattern equals $which exactly. Returns the removed entries —
     * possibly several for a pattern, since duplicates a hand-edit made all
     * go — and an empty list for no match, which wrote nothing.
     *
     * A row the filter rejects is unnumberable — `forget` may only take out
     * what `saved` showed — and it cannot ride a rewrite anyway: the
     * settings schema refuses an array still containing one, so a forget
     * that lands normalizes those rows out beside the entry asked for.
     *
     * Unlike {@see self::append()}, a missing door or a failed write THROWS:
     * a half-reported forget is exactly the lie the permission screen exists
     * to prevent, so the caller answers with a visible refusal instead of a
     * notice in the save's kinder voice.
     *
     * @return list<array>
     */
    public function remove(int|string $which): array
    {
        $writer = $this->door();
        $config = $writer->current(SettingsTier::You);
        $stored = $config['permissionRules'] ?? [];
        $stored = \is_array($stored) ? array_values($stored) : [];

        $keep = [];
        $gone = [];
        $number = 0;
        foreach ($stored as $rule) {
            $row = self::loadableRow($rule);
            if ($row === null) {
                // Unnumberable, and it cannot ride the rewrite anyway:
                // the settings schema REFUSES any permissionRules array
                // still containing these rows, so a forget that lands
                // normalizes them out beside what was actually taken.
                continue;
            }
            $number++;
            $hit = \is_int($which)
                ? $number === $which
                : $row['pattern'] === $which;
            if ($hit) {
                $gone[] = $rule;
            } else {
                $keep[] = $rule;
            }
        }

        if ($gone !== []) {
            $writer->write(SettingsTier::You, ['permissionRules' => $keep]);
        }

        return $gone;
    }

    private function door(): SettingsWriter
    {
        if ($this->writer === null) {
            // The sentence append() has always reported through the
            // not-persisted notice; keeping the words here is what lets the
            // no-door case ride its catch and answer byte-identically.
            throw new \RuntimeException('this session has no settings write door');
        }

        return $this->writer;
    }
}
