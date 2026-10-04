/**
 * Reconnect timing (Appendix O §7.6): 0.5 s, 1 s, 2 s, 4 s, 8 s, then 15 s
 * at most, each with ±30 % jitter so a restarted server is not hit by every
 * tab at once; a connection that stayed up 60 s resets the ladder.
 */
export interface BackoffOptions {
  initialMs?: number
  maxMs?: number
  factor?: number
  /** Fraction of the delay, e.g. 0.3 for ±30 %. */
  jitter?: number
  /** A connection up this long counts as stable and resets the ladder. */
  stableMs?: number
  random?: () => number
}

export class Backoff {
  private readonly initialMs: number
  private readonly maxMs: number
  private readonly factor: number
  private readonly jitter: number
  private readonly stableMs: number
  private readonly random: () => number
  private attempt = 0
  private connectedAt: number | null = null

  constructor(options: BackoffOptions = {}) {
    this.initialMs = options.initialMs ?? 500
    this.maxMs = options.maxMs ?? 15_000
    this.factor = options.factor ?? 2
    this.jitter = options.jitter ?? 0.3
    this.stableMs = options.stableMs ?? 60_000
    this.random = options.random ?? Math.random
  }

  /** How many delays were handed out since the last reset. */
  get attempts(): number {
    return this.attempt
  }

  /** The un-jittered delay of the next attempt. */
  baseDelay(): number {
    return Math.min(this.maxMs, this.initialMs * this.factor ** this.attempt)
  }

  /** The delay before the next attempt, jittered; advances the ladder. */
  next(): number {
    const base = this.baseDelay()
    this.attempt++
    const spread = base * this.jitter
    return Math.max(0, Math.round(base - spread + this.random() * 2 * spread))
  }

  reset(): void {
    this.attempt = 0
  }

  /** The socket is up (and the handshake done) at $now. */
  connected(now: number): void {
    this.connectedAt = now
  }

  /**
   * The socket went down at $now. A connection that lasted at least
   * `stableMs` resets the ladder, so a flap after a long healthy stretch
   * retries fast again rather than waiting out the old 15 s.
   */
  disconnected(now: number): void {
    if (this.connectedAt !== null && now - this.connectedAt >= this.stableMs) {
      this.reset()
    }
    this.connectedAt = null
  }
}

export interface Timers {
  setTimeout(fn: () => void, ms: number): unknown
  clearTimeout(handle: unknown): void
  now(): number
}

export const realTimers: Timers = {
  setTimeout: (fn, ms) => globalThis.setTimeout(fn, ms),
  clearTimeout: (handle) => globalThis.clearTimeout(handle as ReturnType<typeof setTimeout>),
  now: () => Date.now(),
}

/**
 * Liveness: the server sends a `server.tick` every `tickIntervalMs`, so a
 * socket silent for twice that is dead even if TCP has not noticed yet.
 * Every frame re-arms the timer.
 */
export class Watchdog {
  private handle: unknown = null

  constructor(
    private readonly timers: Timers,
    private readonly onSilence: () => void,
  ) {}

  arm(ms: number): void {
    this.disarm()
    this.handle = this.timers.setTimeout(() => {
      this.handle = null
      this.onSilence()
    }, ms)
  }

  disarm(): void {
    if (this.handle !== null) {
      this.timers.clearTimeout(this.handle)
      this.handle = null
    }
  }
}
