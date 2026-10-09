<?php

declare(strict_types=1);

use SugarCraft\Crush\Commands\CommandSpec;
use SugarCraft\Crush\Commands\Specs\BuiltInCommand;
use SugarCraft\Crush\Lang;
use SugarCraft\Crush\Palette\PaletteAction;

// crush-media W3.10: the command-palette door to the image-generation form.
// Palette-only (`slashVisible: false`), mirroring 0101-new-picker.php, so the
// slash rosters the W2 gate pinned stay untouched. Like `settings` it opens
// SHELL state via a message: the arms in Chat send the very OpenMediaFormMsg
// that bare `/generate` raises through CommandEffectKind::OpenMediaForm, so
// both doors run one open path and the frame stays screen-only until
// Generate is pressed (W2.3's scope).
return BuiltInCommand::new(CommandSpec::new(
    'generate-form',
    Lang::t('cmd.generate.form.description'),
    Lang::t('cmd.category.tools'),
    paletteAction: PaletteAction::OpenMediaForm,
    paletteLabel: Lang::t('cmd.generate.form.label'),
    slashVisible: false,
));
