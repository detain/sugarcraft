import { expect, newSession, openApp, send, test } from './fixtures/server'

/**
 * UX-20 regression pin: the "Jump to latest" pill.
 *
 * The scroller carries `overflow-x: hidden` while `.jump` seats itself with
 * `position: sticky; bottom: 0.75rem; left: 100%; margin-right: 1rem; width:
 * max-content; margin-left: auto` (Transcript.vue). N53 flagged the earlier
 * bytes — a `float: right` riding on the sticky box, an engine-divergent
 * combination — as an overlap hazard for long pre lines; the explicit
 * max-content/auto seat removes the ambiguity without moving a pixel: measured
 * here in Chromium, the sticky constraint is clamped inside the containing
 * block's padding box, so `left: 100%` parks the pill's right edge exactly
 * `margin-right` (16px) inside the scroller's right edge at desktop and narrow
 * widths. The pill is visible, hit-testable and clickable; clicking it returns
 * the reader to the bottom and the pill retires.
 *
 * These assertions pin the measured geometry so a future edit that turns the
 * clipping theory back into fact (dropping the sticky clamp for a raw offset,
 * for instance) cannot ship uncaught.
 */
const TALL_TRANSCRIPT = Array.from(
  { length: 40 },
  (_, i) => `paragraph ${i} lorem ipsum dolor sit amet consectetur adipiscing elit sed do eiusmod tempor incididunt ut labore.`,
).join('\n\n')

test('the jump pill rides inside the scroller clip at every width', async ({ page, server }) => {
  await openApp(page, server)
  await newSession(page)
  await send(page, TALL_TRANSCRIPT)
  await expect(page.locator('[data-kind="assistant"] blockquote').first()).toContainText('paragraph 39')
  await expect(page.getByTestId('status-bar')).toHaveAttribute('data-status', 'idle')

  const transcript = page.getByTestId('transcript')
  await expect
    .poll(() => transcript.evaluate((el) => el.scrollHeight - el.clientHeight))
    .toBeGreaterThan(100)

  const measure = () =>
    transcript.evaluate((scroller) => {
      const jump = scroller.querySelector('.jump') as HTMLElement
      const s = scroller.getBoundingClientRect()
      const j = jump.getBoundingClientRect()
      const hit = document.elementFromPoint(j.x + j.width / 2, j.y + j.height / 2)
      return {
        scroller: { left: s.left, right: s.right, top: s.top, bottom: s.bottom },
        pill: { left: j.left, right: j.right, top: j.top, bottom: j.bottom, width: j.width, height: j.height },
        hitIsPill: hit === jump,
      }
    })

  for (const viewport of [{ width: 1280, height: 720 }, { width: 420, height: 740 }]) {
    await page.setViewportSize(viewport)
    // Scrolling away from the bottom edge disarms the follow latch and arms the pill.
    await transcript.evaluate((el) => { el.scrollTop = 0 })
    const jump = transcript.locator('.jump')
    await expect(jump).toBeVisible()

    const clip = await measure()
    // UX-20's question: is any of the pill past the overflow-x clip? It is not —
    // the sticky clamp keeps it 16px inside the scroller's right edge.
    expect(clip.pill.right).toBeLessThanOrEqual(clip.scroller.right - 8)
    expect(clip.pill.right).toBeGreaterThan(clip.scroller.left)
    expect(clip.pill.left).toBeGreaterThanOrEqual(clip.scroller.left)
    expect(clip.pill.bottom).toBeLessThanOrEqual(clip.scroller.bottom)
    expect(clip.pill.width).toBeGreaterThan(0)
    // A rect inside the clip is only half the story: the center pixel belongs to the pill.
    expect(clip.hitIsPill).toBe(true)

    // click() waits on Playwright's hit-target check: a clipped pill fails here.
    await jump.click()
    await expect
      .poll(() => transcript.evaluate((el) => el.scrollHeight - el.scrollTop - el.clientHeight))
      .toBeLessThan(48)
    await expect(jump).toHaveCount(0)
  }
})
