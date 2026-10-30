<?php

declare(strict_types=1);

namespace SugarCraft\Code\Config\Settings\Validator;

use SugarCraft\Code\Config\Settings\SettingValidator;
use SugarCraft\Code\Config\ThemeFile;
use SugarCraft\Code\Theme;

/**
 * The `theme` value: a shipped palette name, or the name of a file under
 * `~/.candy-code/themes/`.
 *
 * This replaces the vocabulary check an {@see EnumValidator} would have been,
 * and the reason is not cosmetic. `Theme::names()` is built-in names PLUS
 * whatever theme files this machine's home happens to hold, so a generated
 * enum list is a list of one machine's private files — a page like
 * `docs/SETTINGS.md` must never carry it, and a settings write on another
 * machine must not be judged against it either. What the live set IS is
 * resolved here, at validation time, from the same source {@see Theme::byName()}
 * reads — and because byName can only ever paint a name that resolves, the
 * list a refusal quotes and the list the picker shows are the same list.
 *
 * The refusal carries a did-you-mean when one of the advertised names is
 * close (same folded/Levenshtein budget as
 * {@see Theme::suggest()}); the person who fat-fingers `drakula` in a project
 * settings file hears about it from the launch audit instead of silently
 * getting `dark`.
 */
final class ThemeNameValidator implements SettingValidator
{
    public static function new(): self
    {
        return new self();
    }

    public function validate(mixed $value, array $all = []): ?string
    {
        // null is "unset" — the schema default (dark) applies, nothing refused.
        if ($value === null) {
            return null;
        }

        if (!\is_string($value) || trim($value) === '') {
            return 'must be the name of a theme';
        }

        if (Theme::isBuiltInName($value) || ThemeFile::exists($value)) {
            return null;
        }

        $hint = Theme::suggest($value);

        return sprintf(
            "must be a built-in theme (%s) or a file under ~/.candy-code/themes; '%s' is neither%s",
            implode(', ', Theme::builtInNames()),
            $value,
            $hint === null ? '' : sprintf(' (did you mean `%s`?)', $hint),
        );
    }
}
