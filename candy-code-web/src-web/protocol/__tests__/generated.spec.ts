import { existsSync, readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import { generate, toType } from '../../../scripts/gen-protocol.mjs'
import { EVENT_KINDS, IDEMPOTENT_METHODS, METHOD_SCOPES, PROTOCOL } from '../generated'

// vitest runs from the package root.
const schemaPath = resolve(process.cwd(), '../candy-code/docs/protocol/candy-code.v1.schema.json')
const generatedPath = resolve(process.cwd(), 'src-web/protocol/generated.ts')

describe('gen:protocol', () => {
  it.skipIf(!existsSync(schemaPath))('generated.ts matches the server schema (run `npm run gen:protocol`)', () => {
    const schema = JSON.parse(readFileSync(schemaPath, 'utf8'))
    const fresh = generate(schema, 'candy-code/docs/protocol/candy-code.v1.schema.json')
    expect(readFileSync(generatedPath, 'utf8')).toBe(fresh)
  })

  it('maps the JSON Schema subset the protocol uses', () => {
    expect(toType({ type: 'string' })).toBe('string')
    expect(toType({ type: ['integer', 'null'] })).toBe('number | null')
    expect(toType({ enum: ['a', 'b'] })).toBe('"a" | "b"')
    expect(toType({ $ref: '#/$defs/SessionId' })).toBe('SessionId')
    expect(toType({ oneOf: [{ $ref: '#/$defs/Usage' }, { type: 'null' }] })).toBe('Usage | null')
    expect(toType({ type: 'array', items: { enum: ['x', 'y'] } })).toBe('("x" | "y")[]')
    expect(toType({ type: 'object', additionalProperties: { type: 'string' } })).toBe('Record<string, string>')
    expect(toType({})).toBe('unknown')
    expect(toType({ type: 'object', properties: { a: { type: 'string' }, 'b.c': { type: 'boolean' } }, required: ['a'] }))
      .toBe('{\n  a: string\n  "b.c"?: boolean\n}')
  })

  it('carries the protocol tables', () => {
    expect(PROTOCOL).toBe(1)
    expect(METHOD_SCOPES['permission.respond']).toBe('approve')
    expect(METHOD_SCOPES['session.list']).toBe('read')
    expect(IDEMPOTENT_METHODS).toContain('session.send')
    expect(IDEMPOTENT_METHODS).not.toContain('session.subscribe')
    // SES-3: the share-page methods ride the schema into the client tables.
    expect(METHOD_SCOPES['session.share']).toBe('write')
    expect(METHOD_SCOPES['session.shares']).toBe('read')
    expect(METHOD_SCOPES['session.unshare']).toBe('write')
    // C-SES-7.e: import and the archive tray pair ride the same tables, and
    // all three are side-effecting — replay-safe by the digest-keyed ledger
    // (import) and the WHERE-clause idempotence (archive/unarchive).
    expect(METHOD_SCOPES['session.import']).toBe('write')
    expect(METHOD_SCOPES['session.archive']).toBe('write')
    expect(METHOD_SCOPES['session.unarchive']).toBe('write')
    expect(IDEMPOTENT_METHODS).toContain('session.import')
    expect(IDEMPOTENT_METHODS).toContain('session.archive')
    expect(IDEMPOTENT_METHODS).toContain('session.unarchive')
    // C-SES-8.a: usage.rollup rides the same tables — a read, with no write
    // path on the wire (the Usage panel renders the fold and links Settings).
    expect(METHOD_SCOPES['usage.rollup']).toBe('read')
    expect(EVENT_KINDS['assistant.delta']).toEqual({ durable: false, scope: 'session' })
    expect(EVENT_KINDS['tool.finished']).toEqual({ durable: true, scope: 'session' })
  })
})
