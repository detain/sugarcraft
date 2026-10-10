import { createServer, type Server } from 'node:http'
import type { AddressInfo } from 'node:net'

/**
 * The fake stable-diffusion endpoint the serve-mode media e2e dials
 * (plan W6.5): the same sd-api surface `GenerateImage` speaks to in
 * production — txt2img answers one tiny valid PNG plus an infotext blob,
 * progress answers idle. Every request is recorded, so a spec can assert the
 * prompt actually crossed the real HTTP transport instead of trusting the
 * card that eventually paints.
 */

/** The canonical 1x1 PNG; every byte assertion decodes its magic back out. */
export const TINY_PNG_BASE64 =
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='

/** PNG signature, first four bytes, as hex — the fetch-loop's ground truth. */
export const PNG_MAGIC_HEX = '89504e47'

export interface StubRequest {
  readonly method: string
  readonly path: string
  readonly body: Record<string, unknown>
}

export interface StubSd {
  /** The origin the fixture points `sd.baseUrl` at. */
  readonly baseUrl: string
  /** Everything the stub has answered, oldest first. */
  readonly requests: StubRequest[]
  /** Bodies of the txt2img POSTs only — the renders, without poll noise. */
  txt2imgBodies(): Record<string, unknown>[]
  close(): Promise<void>
}

/** Start the stub on an ephemeral loopback port (never a fixed one: parallel lanes). */
export function startStubSd(): Promise<StubSd> {
  const requests: StubRequest[] = []
  const server: Server = createServer((req, res) => {
    const path = new URL(req.url ?? '/', 'http://127.0.0.1').pathname
    const chunks: Buffer[] = []
    req.on('data', (chunk: Buffer) => chunks.push(chunk))
    req.on('end', () => {
      const raw = Buffer.concat(chunks).toString('utf8')
      let body: Record<string, unknown> = {}
      if (raw !== '') {
        try {
          body = JSON.parse(raw) as Record<string, unknown>
        } catch {
          body = { unparsed: raw }
        }
      }
      requests.push({ method: req.method ?? '', path, body })
      res.setHeader('content-type', 'application/json')
      if (req.method === 'POST' && path === '/sdapi/v1/txt2img') {
        res.end(JSON.stringify({
          images: [TINY_PNG_BASE64],
          info: JSON.stringify({
            prompt: String(body.prompt ?? ''),
            seed: 1234,
            steps: 1,
            cfg_scale: 7,
            width: Number(body.width ?? 512),
            height: Number(body.height ?? 512),
            sampler_name: 'Euler a',
          }),
        }))
        return
      }
      // Progress polls (and any other read the tool ladder makes): idle.
      res.end(JSON.stringify({ progress: 0, eta_relative: 0, state: null, current_image: null, skip_current_image: true }))
    })
  })

  return new Promise((started) => {
    server.listen(0, '127.0.0.1', () => {
      const { port } = server.address() as AddressInfo
      started({
        baseUrl: `http://127.0.0.1:${port}`,
        requests,
        txt2imgBodies: () => requests.filter((r) => r.path === '/sdapi/v1/txt2img').map((r) => r.body),
        close: () => new Promise<void>((closed) => {
          server.closeAllConnections()
          server.close(() => closed())
        }),
      })
    })
  })
}
