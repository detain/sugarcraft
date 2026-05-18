<?php

declare(strict_types=1);

// Foundation\Color has been removed as a true duplicate of \SugarCraft\Core\Util\Color.
// Use \SugarCraft\Core\Util\Color directly.
//
// This file is kept only to provide a backward-compat redirect during the
// transition. It can be git-deleted once all consumers have been updated.
// See sugar-dash/CALIBER_LEARNINGS.md entry [pattern:dual-foundation-ssot].

// phpcs:ignore
\class_alias(\SugarCraft\Core\Util\Color::class, 'SugarCraft\Dash\Foundation\Color');
