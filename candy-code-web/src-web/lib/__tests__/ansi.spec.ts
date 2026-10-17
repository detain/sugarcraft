import { describe, expect, it } from 'vitest'
import { stripTerminalSequences } from '../ansi'

describe('stripTerminalSequences (ISS-109)', () => {
  it('removes SGR colour runs', () => {
    expect(stripTerminalSequences('\x1b[31mred\x1b[0m plain')).toBe('red plain')
    expect(stripTerminalSequences('\x1b[1;38;2;12;34;56mtruecolor\x1b[m')).toBe('truecolor')
  })

  it('removes cursor and erase sequences, keeping the text between', () => {
    expect(stripTerminalSequences('\x1b[2J\x1b[Hkeep\x1b[?25l')).toBe('keep')
  })

  it('removes OSC sequences to either terminator', () => {
    expect(stripTerminalSequences('\x1b]0;title\x07body')).toBe('body')
    expect(stripTerminalSequences('\x1b]8;;https://example.com\x1b\\link\x1b]8;;\x1b\\')).toBe('link')
  })

  it('leaves plain text (including a lone ESC-less bracket) untouched', () => {
    expect(stripTerminalSequences('git status --porcelain\n?? src/[x]')).toBe('git status --porcelain\n?? src/[x]')
  })
})
