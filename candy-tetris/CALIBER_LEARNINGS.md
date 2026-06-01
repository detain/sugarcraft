[pattern:srs-kick-table-separation] — Keep kick-offset data in a stateless table class (SrsKickTable) separate from Piece; Piece just queries the table and builds candidate positions. This keeps the rotation logic testable in isolation.
[pattern:tspin-3-corner-rule] — T-Spin detection via the 3-corner rule: the T must have rotated (final rotation ≠ pre-lock rotation) AND ≥2 diagonal corner cells must be occupied (out-of-bounds/wall = filled). Pass the pre-rotation state explicitly so TSpin::detect() can distinguish a spin from a simple piece lock.
[pattern:b2b-combo-multiplier-stacking] — B2B (1.5×) and combo (+10×combo) bonuses apply on top of the line-clear base × level multiplier, NOT as separate addends. Compute the base score change first, then apply B2B multiplier to that delta, then add T-Spin + combo points, all scaled by (level+1). This avoids double-counting the level multiplier.

### 2026-05-31 — buffer-cell-grid-for-game-boards
Pattern: Canonical 10×20 Buffer cell grid is the canonical representation for a game playfield — each tetromino's background style and ghost-piece foreground style are applied per-cell. Use `Mark::zone()` for sub-cell interactive regions rather than ad-hoc string composition.
Anti-pattern: Ad-hoc string concatenation for grid assembly loses per-cell style granularity and makes snapshot testing fragile.
Source: step-32 ai/games-shared
