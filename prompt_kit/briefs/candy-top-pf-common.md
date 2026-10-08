# candy-top phase P-F (menus/overlays/options) — shared brief

Repo /home/sites/sugarcraft, lib candy-top/ (SugarCraft\Top\). btop source /home/sites/btop — menus live in
btop_menu.cpp (Menu::mainMenu, optionsMenu, helpMenu, signalChoose/signalSend/signalReturn, sizeError, msgBox,
reniceMenu), keys in btop_input.cpp, presets in btop_config.cpp (`presets` grammar) + btop_draw.cpp calcSizes.
Plan: plan_top.md §4 P-F row + Wave U (#1476, #1411, #1791b, #1849). PR notes: prompt_kit/findings/btop-upstream-prs.md
(sections #1476 :468, #1411 :487, #1791 :254, #1849 :191). Contract + deferred list: candy-top/CALIBER_LEARNINGS.md
(panel seam, "Deferred from P-A", "Deferred to P-F (proc)", P-B..P-E sections). Conventions: AGENTS.md.

P-F is the phase that OWNS App.php changes: an App-owned overlay/menu stack sits ABOVE the modal tier in input
precedence (ctrl+c still first) and paints over the whole frame (btop overlay law: background dimmed — SGR-strip +
inactive_fg, plan §3 Overlays.php / sugar-veil precedent). Keep panels decoupled: panels REQUEST overlays via a Msg or
PanelResult field (e.g. OpenMenuMsg / Overlay value object), never construct App state.

Hard rules: NEVER git stash/checkout/reset/clean/commit/add (supervisor commits). Don't touch sugar-crush or
candy-core. Only candy-top/ (and, for P-F2, sugar-bits only if a genuine lib gap — report it). Other agents may edit
candy-top/themes/ and theme tests concurrently (P-G mellow theme) — don't touch those. User-visible strings via
Lang::t in your own lang/en.php block `// ---- <name> (phase P-Fx) ----` before the closing `];`. Append your own
CALIBER_LEARNINGS section. TEA purity: side effects (signals via posix_kill, config file writes, file reads) only
inside Cmds. Every key/mouse path tested; goldens under tests/fixtures/overlays/ fail when missing unless
CANDY_TOP_UPDATE_GOLDENS=1. Run `cd candy-top && vendor/bin/phpunit` + php tools/check-one-type-per-file.php +
php tools/check-path-repos.php --no-lib-path-repos + php tools/check-child-lifetimes.php before reporting. Optional
tmux smoke of bin/candy-top --fake (kill sessions after). Report: exact file list (created/modified/deleted), btop
mapping, deviations, deferred items.
