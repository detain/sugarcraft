"""
Diff rendering for the SugarCrush TUI.

Ports src/Tui/DiffGutter.php and the diff-rendering logic from src/Renderer.php.
Renders unified diffs with color coding and line-number gutters.
"""

import re
from dataclasses import dataclass
from typing import Optional

from sugarcrush.renderer.style import Style, BorderStyle, string_width, truncate, new as style_new


# ANSI color codes for diff elements
COLOR_ADD = '\033[38;5;2m'    # Green
COLOR_DEL = '\033[38;5;1m'    # Red
COLOR_HUNK = '\033[38;5;6m'   # Cyan (ANSI 6)
COLOR_HEADER = '\033[38;5;250m'  # Gray
COLOR_RESET = '\033[0m'
BOLD = '\033[1m'
DIM = '\033[2m'
RESET = '\033[0m'

# Maximum diff rows before clipping
DIFF_MAX_ROWS = 24
# Minimum body columns before dropping gutter
DIFF_MIN_BODY_COLS = 24
# Separator between line numbers and diff content
GUTTER_SEPARATOR = '│ '


@dataclass
class DiffGutter:
    """
    The old-file/new-file line-number gutter for a unified diff.

    Ports SugarCraft\\Crush\\Tui\\DiffGutter.
    """
    width: int
    prefixes: list[str]
    blank: str

    @staticmethod
    def for_diff(lines: list[str]) -> 'DiffGutter':
        """
        Build the gutter for a whole diff block.
        Ports DiffGutter::forDiff().
        """
        numbers = DiffGutter._number_lines(lines)
        highest = 0
        for old, new, _ in numbers:
            highest = max(highest, old or 0, new or 0)

        if highest == 0:
            return DiffGutter.none(len(lines))

        digits = len(str(highest))
        blank = DiffGutter._format_line(old=None, new=None, digits=digits)
        prefixes = []
        for old, new, _ in numbers:
            prefixes.append(DiffGutter._format_line(old, new, digits))

        gutter_width = digits * 2 + 1 + string_width(GUTTER_SEPARATOR)
        return DiffGutter(gutter_width, prefixes, blank)

    @staticmethod
    def none(rows: int) -> 'DiffGutter':
        """A zero-width gutter for narrow viewports."""
        return DiffGutter(0, [''] * max(0, rows), '')

    @staticmethod
    def file_headers(lines: list[str]) -> list[bool]:
        """
        Which rows are file headers (--- / +++) rather than diff content.
        Ports DiffGutter::fileHeaders().
        """
        numbers = DiffGutter._number_lines(lines)
        return [row[2] for row in numbers]

    @staticmethod
    def _format_line(old: Optional[int], new: Optional[int], digits: int) -> str:
        """Format one line number prefix."""
        old_str = str(old) if old is not None else ''
        new_str = str(new) if new is not None else ''
        return (old_str.rjust(digits) + ' ' + new_str.rjust(digits) + GUTTER_SEPARATOR)

    @staticmethod
    def _number_lines(lines: list[str]) -> list[tuple[Optional[int], Optional[int], bool]]:
        """
        Walk the diff assigning old/new line numbers to each row.
        Ports DiffGutter::number().
        """
        MAX_DIGITS = 9
        in_hunk = False
        old = 0
        new = 0
        out = []

        for line in lines:
            m = re.match(r'^@@ -(\d+)(?:,\d+)? \+(\d+)(?:,\d+)? @@', line)
            if m:
                old_raw, new_raw = m.group(1), m.group(2)
                numberable = (len(old_raw.lstrip('0')) <= MAX_DIGITS and
                              len(new_raw.lstrip('0')) <= MAX_DIGITS)
                in_hunk = numberable
                old = int(old_raw) if numberable else 0
                new = int(new_raw) if numberable else 0
                out.append((None, None, False))
                continue

            if DiffGutter._is_file_header(line, in_hunk):
                in_hunk = False
                out.append((None, None, True))
                continue

            if not in_hunk:
                out.append((None, None, False))
                continue

            # "\ No newline at end of file" — annotate, no line consumed
            if line.startswith('\\'):
                out.append((None, None, False))
                continue

            marker = line[0] if line else ' '
            if marker == '+':
                out.append((None, new, False))
                new += 1
            elif marker == '-':
                out.append((old, None, False))
                old += 1
            else:
                out.append((old, new, False))
                old += 1
                new += 1

        return out

    @staticmethod
    def _is_file_header(line: str, in_hunk: bool) -> bool:
        """Whether this row is a file header (diff --git / index / --- / +++)."""
        if line.startswith('diff --git ') or line.startswith('index '):
            return True
        if not in_hunk and (line.startswith('--- ') or line.startswith('+++ ')):
            return True
        return False


@dataclass
class DiffRenderer:
    """
    Renders unified diffs as bordered, color-coded blocks.
    Ports the diff-rendering portion of Renderer::renderDiff().
    """
    theme: object  # Theme object with system_label, border, etc.

    def render(self, diff: str, width: int) -> str:
        """
        Render a unified diff string to ANSI-styled terminal output.

        Args:
            diff: Raw unified diff (--- a/... / +++ b/... / @@ ... @@ / +/- lines)
            width: Usable columns inside the shell border + padding
            theme: Theme object with system_label, border attributes
        """
        if not diff:
            return ''

        # Border (2 cols) + padding(0, 1) (2 cols) sit outside the text.
        inner = max(8, width - 4)

        raw_rows = diff.rstrip('\r\n').split('\n')
        overflow = len(raw_rows) - DIFF_MAX_ROWS
        if overflow > 0:
            rows = raw_rows[:DIFF_MAX_ROWS]
        else:
            rows = raw_rows

        # Expand tabs using the style tab width
        tab = ' ' * Style().get_tab_width()
        rows = [row.replace('\t', tab) for row in rows]

        # Strip ANSI (rows come from tool output, not trusted)
        rows = [strip_ansi(row) for row in rows]

        # Compute gutter
        gutter = DiffGutter.for_diff(rows)
        if inner - gutter.width < DIFF_MIN_BODY_COLS:
            gutter = DiffGutter.none(len(rows))

        # File headers
        headers = DiffGutter.file_headers(rows)

        body_cols = inner - gutter.width
        gutter_style = Style().foreground(self.theme.system_label).faint()

        painted = []
        for i, row in enumerate(rows):
            text = truncate(row, body_cols)
            prefix = gutter.prefixes[i]
            prefix_rendered = (gutter_style.render(prefix) if prefix else '')
            styled = self._style_diff_line(text, headers[i])
            painted.append(prefix_rendered + styled.render(text))

        if overflow > 0:
            trailer = truncate(f"… {overflow} more diff line{'s' if overflow != 1 else ''}", body_cols)
            painted.append(gutter_style.render(gutter.blank + trailer))

        box = Style()
        box.border_style = BorderStyle.NORMAL
        if hasattr(self.theme, 'border'):
            box.border_color = self.theme.border
        box.padding_top = 0
        box.padding_bottom = 0
        box.padding_left = 1
        box.padding_right = 1

        return box.render_box('\n'.join(painted))

    def _style_diff_line(self, line: str, is_header: bool) -> Style:
        """Pick the Style for one diff line based on its marker."""
        s = Style()
        if is_header:
            return s.foreground(self.theme.system_label).bold()
        if line.startswith('@@'):
            return s.foreground(COLOR_HUNK)
        if line.startswith('+'):
            return s.foreground(COLOR_ADD)
        if line.startswith('-'):
            return s.foreground(COLOR_DEL)
        return s.foreground(self.theme.system_label).faint()


def strip_ansi(text: str) -> str:
    """Remove ANSI escape sequences from text."""
    return re.sub(r'\x1b\[[0-9;]*m', '', text)
