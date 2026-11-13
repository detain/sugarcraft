# Caliber Learnings

Accumulated patterns and anti-patterns from development sessions in this bundle.

- **[pattern:support:mutate-funnel]** Immutable `with*()` transitions in this
  bundle go through ONE private `mutate(array $changes): self` funnel
  (`new self(...array_merge(get_object_vars($this), $changes))`) mirroring
  sugar-core `Concerns/Mutable` — never ad-hoc cloning at the call site. The
  funnel needs a promoted-parameter constructor even when the class was
  previously argument-less (`new self(named: …)` fatals without a matching
  parameter). Declared in-place exception: `TextSafety::addSecret()` — a mint
  time secret cannot be pre registered, and the roster is monotonic (entries
  only ever join).
- **[pattern:msteams:pairing-codes-are-secrets-both-ways]** A pairing code is
  a redaction secret the moment it exists: `addSecret` at mint AND every live
  pending code of every account re registers at boot (`msteams-21` leg b),
  because the store outlives the process that armed the roster. Approve an
  id-less code and nothing burns: the code stays pending, the allowlist stays
  closed, and the caller says why (`msteams-21` leg a).
- **[pattern:msteams:identity-arms-the-dial-gate]** A re dial guard is only
  real if the success path RECORDS the identity (`$this->dialIdentity =
  $identity` in the hello arm, `msteams-02`). Clearing on failure without
  stamping on success reads like protection while the poll rebuilds the whole
  router every tick. Pins for such gates must run the tick twice over a live
  loopback fake (two-tick discovery test) — a single tick can never tell a
  gate from a no-op.
