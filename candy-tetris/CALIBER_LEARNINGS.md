[pattern:srs-kick-table-separation] — Keep kick-offset data in a stateless table class (SrsKickTable) separate from Piece; Piece just queries the table and builds candidate positions. This keeps the rotation logic testable in isolation.
[pattern:tspin-3-corner-rule] — T-Spin detection = last-input rule + corner rule: the piece's LAST SUCCESSFUL INPUT before lock must have been a rotation (`Game::$lastActionWasRotation`; rotate sets it, move/soft/hard drop clear it, gravity steps leave it alone so rotate-then-settle is legal) AND ≥2 diagonal corner cells occupied (out-of-bounds/wall = filled; exactly-2 front-pair = mini). A rotation-delta comparison cannot express this — sync bugs made it dead for humans and phantom for AI.
[pattern:b2b-combo-multiplier-stacking] — Lock scoring, all riding the OLD level (+1): full line-clear base delta is ALWAYS awarded (dropping it unless B2B was a bug), plus 0.5×base B2B extra when the streak continues, plus (int)(spin-base × B2B multiplier) × (oldLevel+1) so the advertised 1.5× rides the T-Spin base too, plus combo×10 × (oldLevel+1), plus perfect-clear 5000 × (oldLevel+1).

### 2026-05-31 — buffer-cell-grid-for-game-boards
Pattern: Canonical 10×20 Buffer cell grid is the canonical representation for a game playfield — each tetromino's background style and ghost-piece foreground style are applied per-cell. Use `Mark::zone()` for sub-cell interactive regions rather than ad-hoc string composition.
Anti-pattern: Ad-hoc string concatenation for grid assembly loses per-cell style granularity and makes snapshot testing fragile.
Source: step-32 ai/games-shared

### 2026-10-23 — kick-table-sign-convention
[pattern:kick-table-spec-verbatim-flip-at-choke] — SrsKickTable transcribes the published SRS tables VERBATIM (spec convention: +y is UP), while the board is row-major y-DOWN. The single sign flip lives at `Piece::rotationsWithKicks` (`$this->y - $dy`) — never re-sign individual table rows, and never compensate in tests. Pins are split accordingly: SrsKickTableTest proves the table still matches the published transcription; SrsKickBehaviorTest proves board behavior (candidate coordinate lists, up-into-ceiling and floor-kick landings). Behavior-first pins are mandatory — provider tests that re-echo table literals survive an inverted application site and certify nothing.
Anti-pattern: Flipping the sign at the apply site ad hoc, or pinning only "the table contains [dx,dy]" — the real-world bug lives in the convention crossing.
Source: docs-fix-1023 / candy-tetris MAJOR-1

[pattern:b2b-resets-on-clear-not-on-lock] — Back-to-back is a LINE-CLEAR-conditional flag per the Tetris guideline: a difficult clear (Tetris or any T-Spin) sets it, a non-difficult LINE CLEAR breaks it, and a lock that clears nothing preserves it. The old code reset on every non-difficult LOCK, silently punishing rotate-then-settle play. Pins pair the two scenarios (quiet lock preserves / single non-T clear breaks) so a reintroduction can't pass half the matrix.
Source: docs-fix-1023 / candy-tetris MAJOR-2
