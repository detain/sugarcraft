/**
 * The persistence verdict of a permission answer (M-22: CW-COVER-B F1).
 *
 * `permission.respond` resolves with `saved`: the server's sentence naming
 * where the always-allow pattern was written — or why it was not. The
 * answer surfaces used to await-discard it, so a failed config write
 * looked exactly like a stored grant: the card vanished and nothing said
 * the rules never landed. The trust twin in ContextPanel has always
 * rendered its write outcome; this is the same truth on the approval
 * path, carried by the three surfaces (drawer, tile, view) through one
 * shared helper so no surface can drift back to silence.
 *
 * The sentence arrives already translated by the server (chat.permission.
 * persisted / chat.permission.not_persisted, naming the config path or
 * the write error). It is server-produced text, not chrome strings: it
 * rides the wire verbatim and is rendered verbatim, exactly like the raw
 * `failure.message` ContextPanel displays — nothing here parses it,
 * because the two shapes share one string type and only the server knows
 * which one it sent.
 */

/**
 * The notice a surface should paint after an answer attempt: the server's
 * verdict verbatim, or null when there is nothing to announce — either
 * nothing was generalisable to persist, or the question was already
 * settled elsewhere and the card left without an outcome to report.
 */
export function respondNotice(result: { saved?: string | null } | undefined | null): string | null {
  const saved = result?.saved
  return typeof saved === 'string' && saved !== '' ? saved : null
}
