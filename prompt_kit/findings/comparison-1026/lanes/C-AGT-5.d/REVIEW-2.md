# C-AGT-5.d REVIEW-2 (r2, delta 44fae6291..4bbcc0f6e) — verdict-first

VERDICT: CLEAN — 0 CRITICAL / 0 MAJOR / 0 MINOR / 2 NIT (→ closeout). r1's two step-local MINORs cured; pair 44fae6291+4bbcc0f6e WELD-READY.

Delta confinement (numstat): SERVER.md +3/−1 (recipe step 4 clause ONLY, :299 row untouched), HookWebhooks.php +3/−1 (class docblock parenthetical, lines 38-40 — zero logic lines), HookWebhooksSignatureTest.php +12/−3 (one request fixture + one assert message + comments; no new methods), REPORT.md +28. Both commits detain A/C (4/4 greps), zero Co-Authored. Worktree clean.

MINOR-1 (vacuity) CURED and discriminating: $missing at :123-126 now pairs VALID x-candy-code-hook=SECRET with a missing signature, 401 demanded "header secret or not". Physical re-mutation was BLOCKED here — reviewer permission map denies repo writes (cp/md5sum/edit rejected; contract = writes /tmp only); disclosed, not a lane finding. Discrimination established by exact predicate trace: under the stated permissive refactor, $wrong (bad HMAC, no header secret) still refuses (true&&true), $missing admits (true&&false → no throw) → assertSame(401) at :127 fails and aborts the method before assertCount; every other test in the file sends a VALID HMAC so the ||-leg is never consulted; the constant-time source-scan pin's regex is a prefix of the mutated line → stays green. Exactly 1 red, precisely the new leg. The tip-green suite empirically proves the new leg asserts real 401 behavior. Builder's claimed 63T/2402A/1F mutation figure is consistent with the trace.

MINOR-2 (doc truth) CURED: docblock + SERVER.md step 4 both state ring = process memory only / restart re-admits; code-accurate — sole storage is `private array $delivered = []` (:93), zero file_get_contents/json_decode/persist sites in the class (grep-verified). GitHub-past-one-retry claim is documented platform behavior.

Gates independently re-run at tip (cwd=candy-code, plain pipe): HookWebhooks|ServerConfigWebhooks|ServerProtocolDocumentationDrift → 69T/2474A/0F/2D (figure byte-identical to r1 → delta added no assertions); DuplicatedDocBlockLine|SymbolCitationDrift → 13T/17557A OK. Both match claims EXACT.

Weld carries re-confirmed: durations.tsv has NO HookWebhooksSignatureTest row yet (welder mints, 10 methods counted); +18T suite-figure carry UNCHANGED by delta (legs are in-method); §4 cites the final-tip chain 44fae6291+4bbcc0f6e.

NIT-1: REPORT says mutated line ":154" — post-delta it is :156 (docblock +2 shifted it); cite was true when the builder ran it.
NIT-2: the 2 Deprecations surface as "PHPUnit Deprecations" summary lines on the combined filter, matching pre-existing sibling noise — no action.
