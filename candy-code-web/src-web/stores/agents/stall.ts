import type { AgentRun } from './tree'

/**
 * CWP-03 — the web port of the TUI's live-agent stall detector
 * (`candy-code/src/Tui/StallDetector.php`, PTV-P01 parity). The server watches
 * each delegated run's billed-token deltas and flags a live row whose output
 * rate stays under its floor for one whole window; a browser never sees billed
 * tokens, only the beats' running totals and their bounded display tail, so
 * this port proxies output at {@see CHARS_PER_TOKEN} characters per token —
 * the 0.5 tok/s floor becomes ~1.5 chars/s (the deviation is disclosed in the
 * lane report).
 *
 * The server is pumped from its own clock each tick; here beats arrive only on
 * activity, so silence is itself the signal: {@see stallSeconds} adds the
 * elapsed-since-last-arrival as a zero-rate window against the local clock,
 * while {@see observeStall} folds each actual arrival into the slow
 * accumulation exactly as `StallDetector::track()` does
 * (candy-code/src/Tui/StallDetector.php:57) — reset at or above the floor,
 * accrue below it, keep the prior state through clock skew, and record a first
 * observation as a baseline without flagging it. A run that is not live (a
 * queued placeholder or any terminal state) is never tracked and never
 * flagged, the parity rule the TUI enforces by consulting only its live rows.
 */

/** Seconds of under-floor output before a live run is flagged. Mirrors StallDetector.php:34. */
export const STALL_THRESHOLD_SECONDS = 30
/** Output floor, tokens per second, at or above which the window resets. Mirrors StallDetector.php:35. */
export const STALL_MIN_TOKENS_PER_SECOND = 0.5
/** Char proxy: this port's stand-in for one billed token (disclosed deviation). */
export const CHARS_PER_TOKEN = 3
/** How often the Agents panel re-observes its fold so silence advances the badge. */
export const STALL_TICK_MS = 1000

/** What one run's detector thread holds between observations. */
export interface StallTrack {
  /** The last beat seq folded in — the fold can replay, a beat may not repeat. */
  readonly seq: number
  /** The last observed token total (the wire carries a running total). */
  readonly tokens: number
  /** The last observed tail length in characters (the char-proxy input). */
  readonly chars: number
  /** Seconds accumulated in below-floor windows, reset by a good beat. */
  readonly slowSeconds: number
  /** Local wall clock at the last observation that was folded in. */
  readonly lastAtMs: number
}

/** Only a live run is watch-able: queued placeholders and terminals never badge. */
export function isTrackedRun(run: AgentRun): boolean {
  return run.status === 'running'
}

/**
 * Fold one arrival into $track, mirroring `StallDetector::track()`
 * (candy-code/src/Tui/StallDetector.php:57). Returns $track unchanged — same
 * identity — for a repeated or regressed beat and for a clock-skewed arrival,
 * a fresh baseline for a first observation, undefined when the run is not (or
 * is no longer) live, and a new thread otherwise.
 */
export function observeStall(track: StallTrack | undefined, run: AgentRun, nowMs: number): StallTrack | undefined {
  if (!isTrackedRun(run)) return undefined
  if (track === undefined) {
    return { seq: run.seq, tokens: run.tokens, chars: run.tail.length, slowSeconds: 0, lastAtMs: nowMs }
  }
  // The fold re-presents what it already holds on every seed/beat merge; only
  // a genuinely newer beat is an observation. The server never sees replays
  // (its rows advance once per dispatch), so this guard is the port's own.
  if (run.seq <= track.seq) return track
  const elapsed = (nowMs - track.lastAtMs) / 1000
  if (elapsed <= 0) return track // clock skew: keep the state, consume nothing (StallDetector.php:66-71)
  const tokenDelta = Math.max(0, run.tokens - track.tokens)
  const charDelta = Math.max(0, run.tail.length - track.chars) / CHARS_PER_TOKEN
  const rate = Math.max(tokenDelta, charDelta) / elapsed
  return {
    seq: run.seq,
    tokens: Math.max(track.tokens, run.tokens),
    chars: Math.max(track.chars, run.tail.length),
    slowSeconds: rate >= STALL_MIN_TOKENS_PER_SECOND ? 0 : track.slowSeconds + elapsed,
    lastAtMs: nowMs,
  }
}

/**
 * Seconds this run has sat under the floor, counting the silence since the
 * last folded arrival as a zero-rate window — the web's stand-in for the
 * server's tick pump. 0 for a run that is not (or is no longer) live.
 */
export function stallSeconds(track: StallTrack | undefined, run: AgentRun, nowMs: number): number {
  if (track === undefined || !isTrackedRun(run)) return 0
  return track.slowSeconds + Math.max(0, (nowMs - track.lastAtMs) / 1000)
}

/** The verdict at parity with `StallDetector::isStalled()` (candy-code/src/Tui/StallDetector.php:109) — inclusive at the window. */
export function isStalled(track: StallTrack | undefined, run: AgentRun, nowMs: number): boolean {
  return stallSeconds(track, run, nowMs) >= STALL_THRESHOLD_SECONDS
}
