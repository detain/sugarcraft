#!/usr/bin/env node
/**
 * Local pre-push affordance for the dist-freshness law:
 *
 *   A change to the web source MUST rebuild the tracked dist/ in the same
 *   commit. dist/ is committed so PHP users get the UI from `composer
 *   require` without Node; CI enforces this in .github/workflows/web.yml
 *   ("Committed dist/ matches the source"), which does exactly what this
 *   script does: rebuild from the committed tree and fail when the
 *   committed dist/ differs.
 *
 * Run `npm run check:dist` from candy-code-web/ before pushing a source
 * change; the verdict is the same one web.yml will give the commit.
 *
 * Semantics are commit-faithful on purpose: the check judges HEAD, so it
 * refuses to run while the build's inputs are uncommitted or staged
 * (a green verdict must mean "the commit I am about to push passes web.yml",
 * not "my scratch tree happens to match").
 *
 * Exit codes: 0 = committed dist/ is fresh; 1 = stale (the law is
 * violated); 2 = the check could not judge (missing tools, uncommitted
 * build inputs, build failure).
 */
import { spawnSync } from 'node:child_process'
import { existsSync, readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'

const ROOT = fileURLToPath(new URL('..', import.meta.url))

// Exactly the paths the Vite build consumes: entry HTML + everything under
// the module roots + the config/version the buildInfo plugin reads. Files
// outside this set (README edits, notes) must not block the check.
const BUILD_INPUTS = [
  'index.html',
  'package.json',
  'package-lock.json',
  'vite.config.ts',
  'src-web',
  'public',
]

function git(...args) {
  const run = spawnSync('git', ['-C', ROOT, ...args], { encoding: 'utf8' })
  if (run.status !== 0) {
    die(2, `git ${args.join(' ')} failed: ${run.stderr.trim() || `exit ${run.status}`}`)
  }
  return run.stdout
}

function die(code, message) {
  process.stderr.write(`${message}\n`)
  process.exit(code)
}

function changedLines(pathspecs) {
  return git('status', '--porcelain', '-uall', '--', ...pathspecs)
    .split('\n')
    .filter((line) => line.trim() !== '')
}

// A warning, not a failure: a mismatched Node major can emit different
// content hashes than CI's pinned Node, making the verdict confusing.
const nvmrc = existsSync(`${ROOT}/.nvmrc`) ? readFileSync(`${ROOT}/.nvmrc`, 'utf8').trim() : ''
if (nvmrc && process.version.replace(/^v/, '').split('.')[0] !== nvmrc.replace(/^v/, '')) {
  process.stderr.write(
    `warning: node ${process.version} != .nvmrc ${nvmrc}; CI builds with the pinned major\n`,
  )
}

if (!existsSync(`${ROOT}/node_modules`)) {
  die(2, "candy-code-web/node_modules is missing — run 'npm ci' first.")
}

const staged = changedLines(['.']).filter((line) => line[0] !== ' ' && line[0] !== '?')
if (staged.length > 0) {
  die(
    2,
    'check:dist judges the committed tree; unstage these first (git restore --staged <path>):\n' +
      staged.map((l) => `  ${l}`).join('\n'),
  )
}

const dirtyInputs = changedLines(BUILD_INPUTS)
if (dirtyInputs.length > 0) {
  die(
    2,
    'check:dist judges a clean build of HEAD; commit or revert these build inputs first:\n' +
      dirtyInputs.map((l) => `  ${l}`).join('\n'),
  )
}

const build = spawnSync('npm', ['run', 'build'], { cwd: ROOT, stdio: 'inherit' })
if (build.status !== 0) {
  die(2, `npm run build failed (exit ${build.status ?? 'signal'}) — nothing judged.`)
}

// Both halves mirror web.yml: `git diff` catches changed/deleted committed
// files, porcelain catches NEW emitted files (e.g. a renamed content-hashed
// chunk) that were never committed.
const diff = git('diff', '--name-only', 'HEAD', '--', 'dist')
const untracked = changedLines(['dist'])

if (diff.trim() !== '' || untracked.length > 0) {
  const offenders = new Set(
    [...diff.split('\n'), ...untracked.map((l) => l.slice(3))].filter((f) => f.trim() !== ''),
  )
  die(
    1,
    'FAIL: candy-code-web/dist is stale — committed dist/ does not match a clean ' +
      'build of the committed source:\n' +
      [...offenders].map((f) => `  ${f}`).join('\n') +
      "\n\nRun 'npm run build' and commit dist/ together with the source change " +
      '(the same-commit law; web.yml enforces it).',
  )
}

process.stdout.write('OK: dist/ is byte-identical to a clean build of the committed source\n')
