import { readFileSync, writeFileSync } from 'node:fs'
import { join } from 'node:path'
import type { Page } from '@playwright/test'
import { expect, newSession, openApp, send, SugarCrushServer, test } from './fixtures/server'
import { RpcClient } from './fixtures/rpc'
import { PNG_MAGIC_HEX, startStubSd, TINY_PNG_BASE64, type StubSd } from './fixtures/media'

/**
 * The serve-mode media chain (plan W6.5): a fake sd endpoint dialed by the
 * real `GenerateImage` tool inside a real `sugarcrush serve`, the artifact
 * refs riding `tool.finished`, and the bytes arriving in the browser only
 * through sliced `media.fetch` frames.
 *
 * Consent posture, as the plan asks it be documented: this file's server is
 * started with `--allow-bypass` and the browser legs run in `bypass-permissions`
 * sessions — the harness-set auto-approve door — so those render legs need no
 * UI click. The RPC leg instead proves the real out-of-turn law: `generate.submit`
 * on an un-answered default-mode session is refused `permission_ask_unanswered`,
 * and only a live turn answered `always` (memoized on the session) opens that
 * door. The final test runs a DEFAULT-mode browser session, proving the real
 * Ask card (the tool is Ask-class) still gates the same chain behind an
 * `ask-once` click.
 *
 * The server and stub are file-scoped on purpose: every test watches the
 * same journal, so the second render costs seconds, not a server boot.
 */

let server: SugarCrushServer
let stub: StubSd

test.beforeAll(async () => {
  stub = await startStubSd()
  server = await SugarCrushServer.start((scratch) => {
    // User-tier settings.json is the only dial address the fixture env can
    // set: it strips every SUGARCRUSH_* variable, so the tool must find
    // `sd.baseUrl` in the HOME the server reads.
    const file = join(scratch, 'home', '.sugar-crush', 'settings.json')
    const settings = JSON.parse(readFileSync(file, 'utf8')) as Record<string, unknown>
    settings['sd.baseUrl'] = stub.baseUrl
    writeFileSync(file, `${JSON.stringify(settings, null, 2)}\n`, { mode: 0o600 })
    return ['--allow-bypass']
  })
})

test.afterAll(async () => {
  await server?.dispose()
  await stub?.close()
})

/** The MediaRef row shape W6.1 puts on `tool.finished` and `generate.status`. */
interface MediaRow {
  artifactId: string
  kind: string
  path: string
  bytes: number
  mime: string
  infotextDigest?: string | null
}

/** One `media.fetch` answer row (mime rides the offset-0 row only). */
interface FetchRow {
  artifactId: string
  offset: number
  bytes: string
  totalBytes: number
  eof: boolean
  mime?: string
}

/** A JSON-RPC frame in either direction, read only for the fields we police. */
interface RpcFrame {
  id?: string
  method?: string
  params?: Record<string, unknown>
  result?: Record<string, unknown>
}

/** Sit on the page's socket, recording RPC calls out and answers back. */
async function spyFrames(page: Page, sink: { requests: RpcFrame[], responses: RpcFrame[] }): Promise<void> {
  await page.routeWebSocket(/\/ws(\?|$)/, (ws) => {
    const upstream = ws.connectToServer()
    ws.onMessage((message) => {
      const frame = JSON.parse(String(message)) as RpcFrame
      if (frame.method !== undefined && frame.id !== undefined) sink.requests.push(frame)
      void upstream.send(message)
    })
    upstream.onMessage((message) => {
      const frame = JSON.parse(String(message)) as RpcFrame
      if (frame.id !== undefined && frame.method === undefined) sink.responses.push(frame)
      void ws.send(message)
    })
  })
}

/** A session in the harness's auto-approve mode (server started `--allow-bypass`). */
async function bypassSession(rpc: RpcClient, name: string): Promise<string> {
  const created = await rpc.call<{ id: string }>('session.create', { name, permissionMode: 'bypass-permissions' })
  expect(created.id).toBeTruthy()
  return created.id
}

function generateTurn(prompt: string): string {
  return `::tool GenerateImage {"prompt":${JSON.stringify(prompt)}}`
}

/** Click the GenerateImage row's head so its (collapsed-on-success) body mounts. */
async function expandToolCard(tab: Page): Promise<void> {
  await tab.getByTestId('tool-card').filter({ hasText: 'GenerateImage' }).locator('button.head').click()
}

/** Where the RPC leg parks its artifact so the negative legs reuse it (one render, not two). */
let rendered: { artifactId: string, path: string } | undefined

test('a bypass-session render paints a MediaCard whose bytes stream over media.fetch', async ({ page }) => {
  const rpc = await RpcClient.connect(server)
  const id = await bypassSession(rpc, 'media-happy')
  const frames: { requests: RpcFrame[], responses: RpcFrame[] } = { requests: [], responses: [] }
  await spyFrames(page, frames)
  await rpc.call('session.subscribe', { sessionId: id })
  await openApp(page, server, `/s/${id}`)

  await rpc.call('session.send', { sessionId: id, text: generateTurn('crimson dusk') })
  const finished = await rpc.waitFor((e) => e.sessionId === id && e.type === 'tool.finished')

  // The prompt crossed the real HTTP transport into the stub.
  expect(stub.txt2imgBodies().some((body) => body.prompt === 'crimson dusk')).toBe(true)

  // The event carries refs — artifact bytes never ride the journal.
  expect(JSON.stringify(finished.data)).not.toContain(TINY_PNG_BASE64)
  const rows = finished.data.media as MediaRow[]
  expect(rows.length).toBe(1)
  expect(rows[0]?.kind).toBe('image')
  expect(rows[0]?.mime).toBe('image/png')

  // The card appears, loads, and hands the browser a blob. A successful tool
  // row renders collapsed (ToolCard opens only error/denied bodies), so the
  // media arm — and MediaCard's eager fetch — exists only after the expand.
  await expandToolCard(page)
  await expect(page.getByTestId('tool-media')).toBeVisible()
  await expect(page.getByTestId('media-card')).toHaveAttribute('data-phase', 'ready')
  await expect(page.getByTestId('media-card').locator('img')).toHaveAttribute('src', /^blob:/)

  // The fetch frame the page sent, exactly as lib/media.ts contracts it.
  const request = frames.requests.find((f) => f.method === 'media.fetch')
  expect(request?.params).toEqual({ sessionId: id, artifactId: rows[0]?.artifactId, offset: 0, limit: 1_048_576 })
  const answer = frames.responses.find((f) => f.id === request?.id)
  expect(answer?.result?.mime).toBe('image/png')
  expect(answer?.result?.eof).toBe(true)
  const streamed = Buffer.from(String(answer?.result?.bytes), 'base64')
  expect(streamed.subarray(0, 4).toString('hex')).toBe(PNG_MAGIC_HEX)
  expect(streamed.length).toBe(Number(answer?.result?.totalBytes))

  rpc.close()
})

test('a second tab streams the same card live and the journal replays its media to a cursor-0 client', async ({ page, context }) => {
  const rpc = await RpcClient.connect(server)
  const id = await bypassSession(rpc, 'media-multi')
  await rpc.call('session.subscribe', { sessionId: id })
  await openApp(page, server, `/s/${id}`)

  const early = await context.newPage()
  await early.goto(`${server.baseURL}/#/s/${id}`)
  await expect(early.getByTestId('session-view')).toHaveAttribute('data-phase', 'live')

  await rpc.call('session.send', { sessionId: id, text: generateTurn('twin tab') })
  const finished = await rpc.waitFor((e) => e.sessionId === id && e.type === 'tool.finished')
  const artifactId = String((finished.data.media as MediaRow[])[0]?.artifactId)

  for (const tab of [page, early]) {
    await expandToolCard(tab)
    await expect(tab.getByTestId('media-card')).toHaveAttribute('data-phase', 'ready')
    await expect(tab.getByTestId('media-card').locator('img')).toHaveAttribute('src', /^blob:/)
  }

  // A tab opening after completion catches up from the snapshot (the web
  // client subscribes with no cursor on purpose). W7 closed the seam this
  // leg used to narrate (R16(a)): the snapshot row now carries the very same
  // media refs `tool.finished` stamped, so the late tab paints the card from
  // the row alone — no event replay involved. The journal stays byte-clean:
  // asserted below over RPC:
  const late = await context.newPage()
  await late.goto(`${server.baseURL}/#/s/${id}`)
  await expect(late.getByTestId('tool-card').filter({ hasText: 'GenerateImage' })).toBeVisible()
  await expect(late.locator('main')).toContainText('Generated 1 image')
  await expandToolCard(late)
  await expect(late.getByTestId('media-card')).toHaveAttribute('data-phase', 'ready')
  await expect(late.getByTestId('media-card').locator('img')).toHaveAttribute('src', /^blob:/)

  // And the same EventEnvelope replay an RPC client sees names the same artifact.
  const replay = await RpcClient.connect(server)
  await replay.call('session.subscribe', { sessionId: id, afterSeq: 0 })
  const replayed = await replay.waitFor((e) => e.sessionId === id && e.type === 'tool.finished')
  expect(String((replayed.data.media as MediaRow[])[0]?.artifactId)).toBe(artifactId)
  expect(JSON.stringify(replayed.data)).not.toContain(TINY_PNG_BASE64)

  replay.close()
  rpc.close()
})

test('a reload repaints the MediaCard from the snapshot row alone (R16(a))', async ({ page }) => {
  const rpc = await RpcClient.connect(server)
  const id = await bypassSession(rpc, 'media-reload')
  await rpc.call('session.subscribe', { sessionId: id })
  await openApp(page, server, `/s/${id}`)

  await rpc.call('session.send', { sessionId: id, text: generateTurn('dusk after rain') })
  const finished = await rpc.waitFor((e) => e.sessionId === id && e.type === 'tool.finished')
  const artifactId = String((finished.data.media as MediaRow[])[0]?.artifactId)
  await expandToolCard(page)
  await expect(page.getByTestId('media-card')).toHaveAttribute('data-phase', 'ready')

  // The wire truth of the fold: a cursor-less subscribe is answered by the
  // snapshot, and that snapshot's row now names the artifact — refs only,
  // never the PNG the journal refused to carry.
  const reader = await RpcClient.connect(server)
  const subscription = await reader.call<{ snapshot?: { messages?: Record<string, unknown>[] } }>(
    'session.subscribe', { sessionId: id },
  )
  const rows = subscription.snapshot?.messages ?? []
  const mediaEntries = rows
    .flatMap((row) => Array.isArray(row.toolResults) ? row.toolResults : [])
    .map((entry) => (entry as Record<string, unknown>).media)
    .filter((media): media is MediaRow[] => Array.isArray(media))
  expect(mediaEntries.flatMap((refs) => refs.map((ref) => ref.artifactId))).toContain(artifactId)
  expect(JSON.stringify(subscription)).not.toContain(TINY_PNG_BASE64)
  reader.close()

  // The UX the finding named: refresh drops nothing. The reloaded client has
  // seen no events at all — everything it paints arrives in the snapshot.
  await page.reload()
  await expect(page.getByTestId('session-view')).toHaveAttribute('data-phase', 'live')
  await expandToolCard(page)
  await expect(page.getByTestId('tool-media')).toBeVisible()
  await expect(page.getByTestId('media-card')).toHaveAttribute('data-phase', 'ready')
  await expect(page.getByTestId('media-card').locator('img')).toHaveAttribute('src', /^blob:/)

  rpc.close()
})

test('a stateless client drives submit, status and fetch purely over RPC', async () => {
  const rpc = await RpcClient.connect(server)
  const created = await rpc.call<{ id: string }>('session.create', { name: 'media-rpc' })
  const id = created.id
  await rpc.call('session.subscribe', { sessionId: id })

  // Silence is not consent (the W6.2 out-of-turn law): a default-mode session
  // that has never answered the ask cannot take a submit at all.
  await expect(rpc.call('generate.submit', { sessionId: id, prompt: 'unanswered', idempotencyKey: 'w3-unasked' }))
    .rejects.toMatchObject({ code: -32003, data: { kind: 'permission_ask_unanswered' } })

  // A live turn asks; answering `always` memoizes the grant on the session.
  await rpc.call('session.send', { sessionId: id, text: generateTurn('office hours') })
  const asked = await rpc.waitFor((e) => e.sessionId === id && e.type === 'permission.requested')
  expect(asked.data.tool).toBe('GenerateImage')
  expect(asked.data.options).toContain('always')
  await rpc.call('permission.respond', { sessionId: id, askId: String(asked.data.askId), reply: 'always' })
  await rpc.waitFor((e) => e.sessionId === id && e.type === 'permission.resolved')
  await rpc.waitFor((e) => e.sessionId === id && e.type === 'tool.finished')
  expect(stub.txt2imgBodies().some((body) => body.prompt === 'office hours')).toBe(true)

  // The memo is the identical door generate.submit consults, and GenerateImage
  // has no subject argument — the grant covers the tool, so a new prompt submits.
  const submitted = await rpc.call<{ jobId: string }>('generate.submit', { sessionId: id, prompt: 'second light', idempotencyKey: 'w3-idem-1' })
  expect(submitted.jobId).toMatch(/^[0-9a-f]{16}$/)
  // The dispatcher's idempotency cache answers the replay — no second render.
  const again = await rpc.call<{ jobId: string }>('generate.submit', { sessionId: id, prompt: 'second light', idempotencyKey: 'w3-idem-1' })
  expect(again.jobId).toBe(submitted.jobId)

  const deadline = Date.now() + 60_000
  let status: { state?: string, artifacts?: MediaRow[], error?: string } = {}
  for (;;) {
    status = await rpc.call('generate.status', { sessionId: id, jobId: submitted.jobId })
    if (status.state === 'done' || status.state === 'failed' || status.state === 'cancelled') break
    expect(Date.now()).toBeLessThan(deadline)
    await new Promise((r) => setTimeout(r, 250))
  }
  expect(status.error ?? '').toBe('')
  expect(status.state).toBe('done')

  const ref = status.artifacts?.[0]
  expect(ref?.kind).toBe('image')
  // Status rows are path-only: the ledger answers refs, fetch answers bytes.
  expect(ref?.bytes).toBe(0)

  const first = await rpc.call<FetchRow>('media.fetch', { sessionId: id, artifactId: ref?.artifactId })
  expect(first.offset).toBe(0)
  expect(first.eof).toBe(true)
  expect(first.mime).toBe('image/png')
  const bytes = Buffer.from(first.bytes, 'base64')
  expect(bytes.length).toBe(first.totalBytes)
  expect(bytes.subarray(0, 4).toString('hex')).toBe(PNG_MAGIC_HEX)

  // The path form works inside its own session's jail, and agrees on the id.
  const byPath = await rpc.call<FetchRow>('media.fetch', { sessionId: id, path: ref?.path })
  expect(byPath.artifactId).toBe(ref?.artifactId)

  rendered = { artifactId: String(ref?.artifactId), path: String(ref?.path) }
  rpc.close()
})

test("one session cannot read another session's artifact", async () => {
  const artifact = rendered
  if (artifact === undefined) {
    test.skip(true, 'the RPC leg above produced no artifact')
    return
  }
  const rpc = await RpcClient.connect(server)
  const intruder = await bypassSession(rpc, 'media-intruder')

  // An artifact id from another session's store is simply not there.
  await expect(rpc.call('media.fetch', { sessionId: intruder, artifactId: artifact.artifactId }))
    .rejects.toMatchObject({ code: -32004, data: { kind: 'media_artifact_not_found' } })

  // The raw-path jail contains to the CALLING session's media root.
  await expect(rpc.call('media.fetch', { sessionId: intruder, path: artifact.path }))
    .rejects.toMatchObject({ code: -32004, data: { kind: 'media_artifact_not_found' } })

  // And traversal is refused at parse, before any filesystem touch.
  await expect(rpc.call('media.fetch', { sessionId: intruder, path: '../../etc/passwd' }))
    .rejects.toMatchObject({ code: -32602 })

  rpc.close()
})

test('a default-mode session still asks: one approval click runs the same chain', async ({ page }) => {
  await openApp(page, server)
  await newSession(page)
  await send(page, generateTurn('asked nicely'))

  const ask = page.getByTestId('permission-card')
  await expect(ask).toBeVisible()
  await ask.getByTestId('ask-once').click()

  await expect(page.getByTestId('tool-card').filter({ hasText: 'GenerateImage' })).toHaveAttribute('data-status', 'done')
  await expandToolCard(page)
  await expect(page.getByTestId('media-card')).toHaveAttribute('data-phase', 'ready')
  expect(stub.txt2imgBodies().some((body) => body.prompt === 'asked nicely')).toBe(true)
})
