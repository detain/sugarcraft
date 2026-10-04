/**
 * A session's gap-free seq cursor (Appendix O §6.8, the OpenHands algorithm).
 *
 * Durable events carry a `seq` that is gap-free and increasing per session.
 * The cursor is the highest seq up to which this client holds EVERY event —
 * what it hands back on reconnect (`resume` / `afterSeq`). An event past a
 * hole is held, not applied, until the hole fills; a hole that stays open
 * longer than {@see GAP_TIMEOUT_MS}, or one with more than
 * {@see MAX_HELD} events behind it, means the client must resubscribe from
 * its cursor. Events at or below the cursor are duplicates (a replay that
 * overlaps the live stream) and are dropped.
 */
export const GAP_TIMEOUT_MS = 2000
export const MAX_HELD = 1024

export class SeqCursor<T> {
  private cursor: number
  private readonly held = new Map<number, T>()
  private gapSince: number | null = null

  constructor(start = 0) {
    this.cursor = start
  }

  get value(): number {
    return this.cursor
  }

  get heldCount(): number {
    return this.held.size
  }

  /** Restart from $seq (a snapshot's `throughSeq`), dropping what was held at or below it. */
  reset(seq: number): void {
    this.cursor = seq
    for (const key of [...this.held.keys()]) {
      if (key <= seq) this.held.delete(key)
    }
    this.gapSince = this.held.size > 0 ? this.gapSince : null
  }

  /**
   * Offer one durable event; returns the events now applicable, in order —
   * none for a duplicate or one past a hole, several when it fills one.
   */
  offer(seq: number, item: T, now: number): T[] {
    if (seq <= this.cursor || this.held.has(seq)) return []
    if (seq !== this.cursor + 1) {
      this.held.set(seq, item)
      this.gapSince ??= now
      return []
    }

    const ready = [item]
    this.cursor = seq
    let next = this.held.get(this.cursor + 1)
    while (next !== undefined) {
      this.held.delete(this.cursor + 1)
      ready.push(next)
      this.cursor++
      next = this.held.get(this.cursor + 1)
    }
    this.gapSince = this.held.size > 0 ? now : null
    return ready
  }

  /** Whether the hole has stayed open too long, or too much waits behind it. */
  needsResync(now: number): boolean {
    if (this.gapSince === null) return false
    return now - this.gapSince > GAP_TIMEOUT_MS || this.held.size > MAX_HELD
  }
}
