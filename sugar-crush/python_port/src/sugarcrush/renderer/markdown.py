"""
Markdown renderer for the SugarCrush TUI.

Ports src/Shine/Renderer.php (candy-shine) — renders Markdown to ANSI-styled text.
Supports headings, bold, italic, code, links, lists, and blockquotes.
"""

import re
import unicodedata
from typing import Optional


# ANSI codes
RESET = '\033[0m'
BOLD = '\033[1m'
DIM = '\033[2m'
ITALIC = '\033[3m'
UNDERLINE = '\033[4m'


def ansi_color(n: int) -> str:
    """Return ANSI 256-color foreground code."""
    return f'\033[38;5;{n}m'


class MarkdownRenderer:
    """
    Renders Markdown text to ANSI-styled terminal output.

    Mimics candy-shine's Renderer, which uses a syntax highlighter under the hood.
    """

    def __init__(self, theme: Optional[dict] = None):
        """
        Args:
            theme: Dict with 'h1', 'h2', 'code', 'link', 'bold', 'italic' ANSI codes.
        """
        self.theme = theme or {
            'h1': ansi_color(75),
            'h2': ansi_color(141),
            'code': ansi_color(117),
            'link': ansi_color(75) + UNDERLINE,
            'bold': BOLD,
            'italic': ITALIC,
        }

    def render(self, text: str) -> str:
        """
        Render Markdown text to ANSI-styled terminal output.

        Processes in order: code blocks → inline code → headings →
        bold/italic → links → blockquotes → lists → paragraphs.
        """
        if not text:
            return ''

        # Normalize line endings
        text = text.replace('\r\n', '\n').replace('\r', '\n')

        lines = text.split('\n')
        result_lines = []
        in_code_block = False
        code_block_lang = ''
        code_block_lines = []

        for line in lines:
            if line.startswith('```'):
                if not in_code_block:
                    # Start of code block
                    in_code_block = True
                    code_block_lang = line[3:].strip()
                    code_block_lines = []
                else:
                    # End of code block — render it
                    result_lines.append(self._render_code_block(code_block_lines, code_block_lang))
                    in_code_block = False
                    code_block_lang = ''
                    code_block_lines = []
                continue

            if in_code_block:
                code_block_lines.append(line)
                continue

            # Inline code
            line = self._render_inline_code(line)
            # Headings
            line = self._render_heading(line)
            # Bold + italic
            line = self._render_bold_italic(line)
            # Links
            line = self._render_links(line)
            # Blockquotes
            line = self._render_blockquote(line)
            # Lists
            line = self._render_list(line)
            # Horizontal rules
            line = self._render_hr(line)

            result_lines.append(line)

        return '\n'.join(result_lines)

    def _render_heading(self, line: str) -> str:
        """Render ATX-style headings (# ## ### etc)."""
        m = re.match(r'^(#{1,6})\s+(.*)$', line)
        if not m:
            return line
        level = len(m.group(1))
        content = m.group(2)
        style = self.theme['h1'] if level == 1 else self.theme['h2']
        return f'{style}{BOLD}{content}{RESET}'

    def _render_bold_italic(self, text: str) -> str:
        """Render bold (**text**) and italic (*text*)."""
        # Bold+italic (***text***) first
        text = re.sub(
            r'\*\*\*(.+?)\*\*\*',
            lambda m: f'{self.theme["bold"]}{self.theme["italic"]}{m.group(1)}{RESET}',
            text
        )
        # Bold
        text = re.sub(
            r'\*\*(.+?)\*\*',
            lambda m: f'{self.theme["bold"]}{m.group(1)}{RESET}',
            text
        )
        # Italic
        text = re.sub(
            r'\*(.+?)\*',
            lambda m: f'{self.theme["italic"]}{m.group(1)}{RESET}',
            text
        )
        return text

    def _render_inline_code(self, text: str) -> str:
        """Render inline `code`."""
        return re.sub(
            r'`([^`]+)`',
            lambda m: f'{self.theme["code"]}{m.group(1)}{RESET}',
            text
        )

    def _render_code_block(self, lines: list[str], lang: str) -> str:
        """Render a fenced code block."""
        if not lines:
            return ''
        code = '\n'.join(lines)
        return f'{self.theme["code"]}{code}{RESET}'

    def _render_links(self, text: str) -> str:
        """Render [text](url) as colored text (no actual navigation)."""
        return re.sub(
            r'\[([^\]]+)\]\([^\)]+\)',
            lambda m: f'{self.theme["link"]}{m.group(1)}{RESET}',
            text
        )

    def _render_blockquote(self, line: str) -> str:
        """Render > blockquotes."""
        if line.startswith('>'):
            content = line[1:].lstrip()
            return f'{DIM}│ {content}{RESET}'
        return line

    def _render_list(self, line: str) -> str:
        """Render bullet and numbered lists."""
        # Unordered
        m = re.match(r'^(\s*)[-*+]\s+(.*)$', line)
        if m:
            indent = m.group(1)
            content = m.group(2)
            return f'{indent}• {content}'
        # Ordered
        m = re.match(r'^(\s*)\d+\.\s+(.*)$', line)
        if m:
            indent = m.group(1)
            content = m.group(2)
            return f'{indent}  {content}'
        return line

    def _render_hr(self, line: str) -> str:
        """Render horizontal rules (--- or ***)."""
        stripped = line.strip()
        if stripped in ('---', '***', '___'):
            return f'{DIM}{"─" * 40}{RESET}'
        return line
