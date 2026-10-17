/**
 * Terminal escape sequences in text meant for a terminal (ISS-109).
 *
 * Tool output arrives verbatim from commands that colour their work; the
 * browser's `pre` would show the escapes as noise. The raw text is never
 * touched — it is what the model re-reads and what the full-output fetch
 * returns — only its display form is stripped here.
 */
const SEQUENCES = /\x1b(?:\[[0-?]*[ -/]*[@-~]|\][^\x07\x1b]*(?:\x07|\x1b\\)|[@-Z\\-_])/g

/** Removes CSI/OSC/other escape sequences (SGR included). */
export function stripTerminalSequences(text: string): string {
  return text.replace(SEQUENCES, '')
}
