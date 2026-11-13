# candy-tetris — Caliber learnings

## 2026-11-12 — roster unification (tetris-30)

Pre-convention undated entries were given dated sections and the duplicate
kick-table entry was folded: `[pattern:srs-kick-table-separation]` ("keep the
kick offsets in a stateless table, Piece just queries") carried no lesson the
`kick-table-sign-convention` entry below does not already cover — separation
and statelessness are both stated there. All back-to-back facts, previously
scattered and partly contradictory across three entries, are unified into
`[law:back-to-back]` below with code truth as arbiter.

## 2026-11-12 — spin detection and score arithmetic

[pattern:tspin-3-corner-rule] — T-Spin detection = last-input rule + guideline corner rule on the CENTRE DIAGONALS: the piece's LAST SUCCESSFUL INPUT before lock must have been a rotation (`Game::$lastActionWasRotation`; rotate sets it, move/soft/hard drop clear it, gravity steps leave it alone so rotate-then-settle is legal) AND the four cells at (centre.x±1, centre.y±1) — centre is the T's box (1,1) offset, i.e. (piece.x+1, piece.y+1), out-of-bounds/wall/floor = filled — must be ≥3 occupied for a full T-Spin; exactly 2 occupied counts only when at least one is a FRONT corner (nub side: rot0 [TL,TR], rot1 [TR,BR], rot2 [BL,BR], rot3 [TL,BL]) and scores as mini; two back corners score nothing. Bounding-box-exterior corners are NOT the guideline corners — that misread farmed +400 from junk rotations. A rotation-delta comparison cannot express the last-input half — sync bugs made it dead for humans and phantom for AI.
Anti-pattern: Detecting spins by rotation-delta history, or reading corners off the piece bounding box.
Source: era undated (pre-convention, unified 2026-11-12) / candy-tetris-039

[law:back-to-back] — Back-to-back is a LINE-CLEAR-conditional flag per the guideline, and its three legs live in exactly one place (`Game` lock scoring). **Arm:** only a difficult CLEAR arms or re-arms — Tetris (count ≥ 4) or a full T-Spin that cleared at least one line (a mini clearing ≥ 2 lines promotes to full; guideline mini-double = full difficulty). **Break:** a non-difficult LINE CLEAR breaks the flag. **Preserve:** a lock that clears nothing preserves it — including a quiet 3-corner spin, which pays its shape bonus but never arms, never extends, and never breaks the chain (the 1.5× rides only `b2bEligible` locks; see the arbiter expression `backToBack = b2bEligible ? true : (count > 0 ? false : backToBack)`). Lock scoring, all riding the OLD level (+1): full line-clear base delta is ALWAYS awarded (dropping it unless B2B was a bug), plus 0.5×base B2B extra when the streak continues, plus (int)(spin-base × B2B multiplier) × (oldLevel+1) so the advertised 1.5× rides the T-Spin base too, plus combo×10 × (oldLevel+1), plus perfect-clear 5000 × (oldLevel+1).
Anti-pattern: Resetting on every non-difficult LOCK (punishes rotate-then-settle); arming on a quiet spin; letting the multiplier ride the post-clear level.
Pins: `GameTest::testBackToBackAfterTetrisClear` (arm), `GameTest::testBackToBackSurvivesAQuietLock` (preserve), `GameTest::testBackToBackBreaksOnNonDifficultClear` (break), `GameTest::testTSpinBonusWithoutAClearNeverArmsBackToBack` (quiet-spin leg), `EarnedMomentsTest::testComboAndBackToBackBadgeTheScoreCard`.
Source: docs-fix-1023 MAJOR-2 + candy-tetris-110/-111, unified 2026-11-12

## 2026-05-31 — buffer-cell-grid-for-game-boards

Pattern: Canonical 10×20 Buffer cell grid is the canonical representation for a game playfield — each tetromino's background style and ghost-piece foreground style are applied per-cell. Use `Mark::zone()` for sub-cell interactive regions rather than ad-hoc string composition.
Anti-pattern: Ad-hoc string concatenation for grid assembly loses per-cell style granularity and makes snapshot testing fragile.
Source: step-32 ai/games-shared

## 2026-10-23 — kick-table-sign-convention

[pattern:kick-table-spec-verbatim-flip-at-choke] — SrsKickTable transcribes the published SRS tables VERBATIM (spec convention: +y is UP), while the board is row-major y-DOWN. The table stays stateless and separate from Piece: Piece just queries it and builds candidate positions. The single sign flip lives at `Piece::rotationsWithKicks` (`$this->y - $dy`) — never re-sign individual table rows, and never compensate in tests. Pins are split accordingly: SrsKickTableTest proves the table still matches the published transcription; SrsKickBehaviorTest proves board behavior (candidate coordinate lists, up-into-ceiling and floor-kick landings); TetrominoTest::testEveryRotationStateIsThePublishedShapeCoordinateSet pins the shape roster itself as per-kind coordinate sets. Behavior-first pins are mandatory — provider tests that re-echo table literals survive an inverted application site and certify nothing.
Anti-pattern: Flipping the sign at the apply site ad hoc, or pinning only "the table contains [dx,dy]" — the real-world bug lives in the convention crossing.
Source: docs-fix-1023 / candy-tetris MAJOR-1
