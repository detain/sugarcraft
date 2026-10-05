import { spawn, spawnSync, type ChildProcess } from 'node:child_process'
import { randomBytes } from 'node:crypto'
import { chmodSync, existsSync, mkdirSync, mkdtempSync, rmSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join, resolve } from 'node:path'
import { test as base, expect, type BrowserContext, type Page } from '@playwright/test'

/**
 * A real `sugarcrush serve` for the end-to-end suite (Appendix O §7.8): the
 * PHP server from the sibling sugar-crush package, on a free port, over a
 * throwaway git repository and a throwaway HOME, answering with the offline
 * EchoProvider — deterministic, no network, no keys. A `::tool <Name> <json>`
 * prompt makes EchoProvider call a real tool, which is what drives the
 * permission questions, tool cards and diffs below.
 *
 * Environment: PHP_BINARY (default `php`), SUGARCRUSH_BIN (default the
 * monorepo's ../sugar-crush/bin/sugarcrush). The server needs ext-pcntl,
 * ext-posix and ext-ffi, as `sugarcrush serve` always does.
 */
const WEB_ROOT = resolve(import.meta.dirname, '../..')
const BIN = process.env.SUGARCRUSH_BIN ?? resolve(WEB_ROOT, '../sugar-crush/bin/sugarcrush')
const PHP = process.env.PHP_BINARY ?? 'php'

export class SugarCrushServer {
  readonly token = randomBytes(32).toString('hex')
  private child: ChildProcess | null = null
  private stderr = ''
  port = 0
  signInUrl = ''

  private constructor(
    /** A short directory: unix-socket paths are capped at 108 bytes. */
    readonly scratch: string,
    readonly home: string,
    readonly repo: string,
    /** Extra `serve` flags this server starts with. */
    readonly serveArgs: string[] = [],
  ) {}

  /**
   * $prepare runs on the scratch directory before the server starts, and
   * returns the extra `serve` flags it needs (e.g. `--allow-dir-browse`).
   */
  static async start(prepare: (scratch: string) => string[] = () => []): Promise<SugarCrushServer> {
    const scratch = mkdtempSync(join(process.env.E2E_TMPDIR ?? tmpdir(), 'scw-'))
    chmodSync(scratch, 0o1777)
    const home = join(scratch, 'home')
    const repo = join(scratch, 'repo')
    mkdirSync(home, { mode: 0o700 })
    mkdirSync(repo)
    writeFileSync(join(repo, 'README.md'), '# e2e\n')
    const git = (...args: string[]) => spawnSync('git', ['-c', 'user.email=e2e@example.com', '-c', 'user.name=e2e', ...args], { cwd: repo })
    git('init', '-q')
    git('add', '.')
    git('commit', '-qm', 'init')

    const server = new SugarCrushServer(scratch, home, repo, prepare(scratch))
    await server.launch(0)
    return server
  }

  get baseURL(): string {
    return `http://127.0.0.1:${this.port}`
  }

  private env(): NodeJS.ProcessEnv {
    const env: NodeJS.ProcessEnv = {}
    for (const [key, value] of Object.entries(process.env)) {
      // No provider, no inherited server settings: the offline EchoProvider.
      if (!key.startsWith('SUGARCRUSH_') && !key.startsWith('ANTHROPIC_') && !key.startsWith('OPENAI_')) env[key] = value
    }
    return { ...env, HOME: this.home, TMPDIR: this.scratch, SUGARCRUSH_SERVER_TOKEN: this.token, NO_COLOR: '1' }
  }

  private async launch(port: number): Promise<void> {
    if (!existsSync(BIN)) throw new Error(`no sugarcrush binary at ${BIN} (set SUGARCRUSH_BIN)`)
    this.stderr = ''
    const child = spawn(PHP, [BIN, '--root', this.repo, 'serve', '--port', String(port), '--web-root', join(WEB_ROOT, 'dist'), ...this.serveArgs], {
      env: this.env(),
      stdio: ['ignore', 'ignore', 'pipe'],
    })
    this.child = child
    child.stderr?.setEncoding('utf8')
    child.stderr?.on('data', (chunk: string) => {
      this.stderr += chunk
    })

    const deadline = Date.now() + 30_000
    while (Date.now() < deadline) {
      const signIn = /sign in:\s+(http:\/\/127\.0\.0\.1:(\d+)\/#code=[0-9a-f]+)/.exec(this.stderr)
      if (signIn) {
        this.signInUrl = signIn[1] ?? ''
        this.port = Number(signIn[2])
        return
      }
      if (child.exitCode !== null) break
      await new Promise((r) => setTimeout(r, 50))
    }
    throw new Error(`sugarcrush serve did not start:\n${this.stderr}`)
  }

  /** A fresh one-time sign-in URL (`sugarcrush serve url`). */
  freshSignInUrl(): string {
    const result = spawnSync(PHP, [BIN, 'serve', 'url', '--output-format', 'json'], { env: this.env(), encoding: 'utf8' })
    const parsed = JSON.parse(result.stdout) as { result?: { loginUrl?: string } }
    const url = parsed.result?.loginUrl
    if (!url) throw new Error(`serve url failed: ${result.stdout}${result.stderr}`)
    return url
  }

  async stop(): Promise<void> {
    const child = this.child
    if (!child || child.exitCode !== null) return
    const exited = new Promise<void>((r) => child.once('exit', () => r()))
    child.kill('SIGTERM')
    const timer = setTimeout(() => child.kill('SIGKILL'), 20_000)
    await exited
    clearTimeout(timer)
  }

  /** Stop and start again on the same port and state: sessions persist, browser sign-ins do not. */
  async restart(): Promise<void> {
    const port = this.port
    await this.stop()
    await this.launch(port)
  }

  async dispose(): Promise<void> {
    await this.stop()
    rmSync(this.scratch, { recursive: true, force: true })
  }
}

/** Sign $context in with the owner token (a cookie, as a browser holds it). */
export async function signIn(context: BrowserContext, server: SugarCrushServer): Promise<void> {
  const response = await context.request.post(`${server.baseURL}/api/login`, {
    data: { token: server.token },
    headers: { Origin: server.baseURL },
  })
  expect(response.ok()).toBe(true)
}

/** Open the UI signed in, and wait for the socket. */
export async function openApp(page: Page, server: SugarCrushServer, path = '/'): Promise<void> {
  await signIn(page.context(), server)
  await page.goto(`${server.baseURL}/#${path}`)
  await expect(page.getByTestId('connection-status')).toContainText('connected')
}

/** Start a session from the sidebar and wait for its view to follow it. */
export async function newSession(page: Page): Promise<string> {
  await page.getByTestId('new-session').click()
  const view = page.getByTestId('session-view')
  await expect(view).toHaveAttribute('data-phase', 'live')
  return (await view.getAttribute('data-session-id')) ?? ''
}

export async function send(page: Page, text: string): Promise<void> {
  await page.getByTestId('composer-input').fill(text)
  await page.getByTestId('composer-input').press('Enter')
}

export const test = base.extend<object, { server: SugarCrushServer }>({
  server: [
    async ({}, use) => {
      const server = await SugarCrushServer.start()
      await use(server)
      await server.dispose()
    },
    { scope: 'worker' },
  ],
})

export { expect }
