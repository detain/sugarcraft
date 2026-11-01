import { describe, expect, it } from 'vitest'
import { ansiSegments, stripTerminalSequences } from '../ansi'

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

// C-TOOL-6.l: ansiSegments — the tail pane's render model. SGR becomes
// classes, the display-only line discipline (CR rewrite, backspace erase)
// runs at text level, and everything unnameable is dropped. It is NOT a
// terminal emulator: no resize, no alt screen, no input echo.
describe('ansiSegments (C-TOOL-6.l)', () => {
  it('maps SGR looks to classes and splits the run where the style changes', () => {
    expect(ansiSegments('\x1b[1mB\x1b[0mN')).toEqual([
      { text: 'B', classes: ['ansi-bold'] },
      { text: 'N', classes: [] },
    ])
    expect(ansiSegments('\x1b[2;3;4;7mX\x1b[23mY')).toEqual([
      { text: 'X', classes: ['ansi-dim', 'ansi-italic', 'ansi-underline', 'ansi-inverse'] },
      { text: 'Y', classes: ['ansi-dim', 'ansi-underline', 'ansi-inverse'] }, // 23 clears italic only
    ])
  })

  it('names the 16 palette colours for fg and bg, with 39/49 clearing them', () => {
    expect(ansiSegments('\x1b[31mR\x1b[102mRG\x1b[39mG\x1b[49mN')).toEqual([
      { text: 'R', classes: ['ansi-fg-red'] },
      { text: 'RG', classes: ['ansi-fg-red', 'ansi-bg-bright-green'] },
      { text: 'G', classes: ['ansi-bg-bright-green'] },
      { text: 'N', classes: [] },
    ])
    expect(ansiSegments('\x1b[94mbright blue')).toEqual([{ text: 'bright blue', classes: ['ansi-fg-bright-blue'] }])
  })

  it('resolves 38;5 palette colour to a class and DROPS anything it cannot name', () => {
    expect(ansiSegments('\x1b[38;5;9mred\x1b[0m\x1b[48;5;12mblue')).toEqual([
      { text: 'red', classes: ['ansi-fg-bright-red'] }, // 8-15 are the bright half of the palette
      { text: 'blue', classes: ['ansi-bg-bright-blue'] },
    ])

    // Truecolor carries no class, and its five parameters are CONSUMED — the
    // next SGR run applies cleanly, proving the tail never leaks as codes.
    expect(ansiSegments('\x1b[38;2;12;34;56mplain\x1b[1mbold')).toEqual([
      { text: 'plain', classes: [] },
      { text: 'bold', classes: ['ansi-bold'] },
    ])
    expect(ansiSegments('\x1b[38;5;200mweird\x1b[0mreset')).toEqual([
      { text: 'weirdreset', classes: [] }, // beyond the 16 palette: dropped, not guessed — and one run with the reset text
    ])
  })

  it('drops non-SGR sequences (cursor moves, erase, OSC) while keeping the text', () => {
    expect(ansiSegments('\x1b[2J\x1b[Hkeep\x1b[?25lmore\x1b]0;title\x07end')).toEqual([{ text: 'keepmoreend', classes: [] }])
  })

  it('a lone CR rewrites the current line, never earlier ones — and a pty CRLF just ends the line', () => {
    expect(ansiSegments('phase1\rphase2')).toEqual([{ text: 'phase2', classes: [] }])
    expect(ansiSegments('a\rb\nc\rd')).toEqual([{ text: 'b\nd', classes: [] }])
    expect(ansiSegments('one\r\ntwo')).toEqual([{ text: 'one\ntwo', classes: [] }])
  })

  it('backspace erases the previous character but never reaches over a newline', () => {
    expect(ansiSegments('abc\x7f\x7fx')).toEqual([{ text: 'ax', classes: [] }]) // both c AND b erased
    expect(ansiSegments('a\nb\x7fx')).toEqual([{ text: 'a\nx', classes: [] }])
    expect(ansiSegments('a\n\x7fb')).toEqual([{ text: 'a\nb', classes: [] }])
  })

  it('run-length encodes adjacent equal-style cells and survives an empty tail', () => {
    expect(ansiSegments('\x1b[32mgreen\x1b[31mred')).toEqual([
      { text: 'green', classes: ['ansi-fg-green'] },
      { text: 'red', classes: ['ansi-fg-red'] },
    ])
    expect(ansiSegments('')).toEqual([])
    expect(ansiSegments('\x1b[1m')).toEqual([])
  })
})
