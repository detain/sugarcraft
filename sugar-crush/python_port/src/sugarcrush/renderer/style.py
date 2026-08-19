"""
Style system for the SugarCrush TUI renderer.

Ports src/Style.php (candy-sprinkles) and the styling helpers from src/Renderer.php.
Provides ANSI-styled text with borders, padding, and foreground/background colors.
"""

from dataclasses import dataclass, field
from enum import Enum
from typing import Optional


# ANSI escape sequences
RESET = '\033[0m'
BOLD = '\033[1m'
DIM = '\033[2m'
ITALIC = '\033[3m'
UNDERLINE = '\033[4m'
STRIKETHROUGH = '\033[9m'

# Foreground colors (standard ANSI)
FG_BLACK = '\033[30m'
FG_RED = '\033[31m'
FG_GREEN = '\033[32m'
FG_YELLOW = '\033[33m'
FG_BLUE = '\033[34m'
FG_MAGENTA = '\033[35m'
FG_CYAN = '\033[36m'
FG_WHITE = '\033[37m'

# Foreground bright
FG_BRIGHT_BLACK = '\033[90m'
FG_BRIGHT_RED = '\033[91m'
FG_BRIGHT_GREEN = '\033[92m'
FG_BRIGHT_YELLOW = '\033[93m'
FG_BRIGHT_BLUE = '\033[94m'
FG_BRIGHT_MAGENTA = '\033[95m'
FG_BRIGHT_CYAN = '\033[96m'
FG_BRIGHT_WHITE = '\033[97m'

# 256-color foreground
def fg_256(n: int) -> str:
    return f'\033[38;5;{n}m'


class BorderStyle(Enum):
    """Border styles for styled boxes."""
    NORMAL = 'normal'
    ROUNDED = 'rounded'
    HEAVY = 'heavy'
    DOUBLE = 'double'
    SINGLE = 'single'
    NONE = 'none'

    def chars(self) -> tuple[str, str, str, str, str, str, str, str]:
        """
        Return (top_left, top, top_right, right, bottom_right, bottom, bottom_left, left)
        as 8 single-character strings.
        """
        return {
            BorderStyle.NORMAL:   ('┌', '─', '┐', '│', '┘', '─', '└', '│'),
            BorderStyle.ROUNDED:  ('╭', '─', '╮', '│', '╯', '─', '╰', '│'),
            BorderStyle.HEAVY:    ('┏', '━', '┓', '┃', '┛', '━', '┗', '┃'),
            BorderStyle.DOUBLE:   ('╔', '═', '╗', '║', '╝', '═', '╚', '║'),
            BorderStyle.SINGLE:   ('+', '-', '+', '|', '+', '-', '+', '|'),
            BorderStyle.NONE:     ('',  '',  '',  '',  '',  '',  '',  ''),
        }[self]

    def with_title(self, title: str) -> 'BorderStyle':
        """Returns self; title is handled in render()."""
        return self


# Default tab width used by ANSI expansion
DEFAULT_TAB_WIDTH = 4


@dataclass
class Style:
    """
    A composable ANSI style for terminal text.

    Mimics candy-sprinkles' Style class used throughout the PHP renderer.
    """
    foreground_color: Optional[str] = None
    background_color: Optional[str] = None
    bold_: bool = False
    faint_: bool = False
    italic_: bool = False
    underline_: bool = False
    strikethrough_: bool = False
    border_style: Optional[BorderStyle] = None
    border_color: Optional[str] = None
    padding_top: int = 0
    padding_right: int = 0
    padding_bottom: int = 0
    padding_left: int = 0
    width: Optional[int] = None
    _tab_width: int = DEFAULT_TAB_WIDTH

    def foreground(self, color: str) -> 'Style':
        """Set foreground (text) color."""
        return _copy_with(self, foreground_color=color)

    def background(self, color: str) -> 'Style':
        """Set background color."""
        return _copy_with(self, background_color=color)

    def bold(self, value: bool = True) -> 'Style':
        """Set bold."""
        return _copy_with(self, bold_=value)

    def faint(self, value: bool = True) -> 'Style':
        """Set faint/dim."""
        return _copy_with(self, faint_=value)

    def italic(self, value: bool = True) -> 'Style':
        """Set italic."""
        return _copy_with(self, italic_=value)

    def underline(self, value: bool = True) -> 'Style':
        """Set underline."""
        return _copy_with(self, underline_=value)

    def strikethrough(self, value: bool = True) -> 'Style':
        """Set strikethrough."""
        return _copy_with(self, strikethrough_=value)

    def border(self, style: BorderStyle) -> 'Style':
        """Set border style."""
        return _copy_with(self, border_style=style)

    def borderForeground(self, color: str) -> 'Style':
        """Set border color."""
        return _copy_with(self, border_color=color)

    def padding(self, vertical: int, horizontal: int) -> 'Style':
        """Set vertical and horizontal padding."""
        return _copy_with(self,
                          padding_top=vertical,
                          padding_bottom=vertical,
                          padding_left=horizontal,
                          padding_right=horizontal)

    def width(self, cols: int) -> 'Style':
        """Set fixed width for the rendered box."""
        return _copy_with(self, width=cols)

    def get_tab_width(self) -> int:
        """Return the tab width for expansion."""
        return self._tab_width

    def sgr_open(self) -> str:
        """Return the opening SGR sequence, with no text and no trailing reset."""
        parts = []
        if self.foreground_color:
            parts.append(self.foreground_color)
        if self.background_color:
            parts.append(self.background_color)
        if self.bold_:
            parts.append(BOLD)
        if self.faint_:
            parts.append(DIM)
        if self.italic_:
            parts.append(ITALIC)
        if self.underline_:
            parts.append(UNDERLINE)
        if self.strikethrough_:
            parts.append(STRIKETHROUGH)
        return ''.join(parts)

    def render(self, text: str = '') -> str:
        """
        Render text with this style's ANSI codes.

        Always terminates with a full reset.
        """
        open_ = self.sgr_open()
        return f'{open_}{text}{RESET}'

    def render_box(self, text: str) -> str:
        """
        Render text inside a bordered box with padding.

        This is the main method used by the renderer for shell wrapping.
        """
        lines = text.split('\n')

        # Apply width constraint if set
        target_width = self.width
        if target_width is not None:
            # Clamp each line to target_width
            clamped = []
            for line in lines:
                w = string_width(line)
                if w > target_width:
                    clamped.append(truncate(line, target_width))
                else:
                    clamped.append(line)
            lines = clamped

        # Add padding
        if self.padding_top > 0:
            lines = [''] * self.padding_top + lines
        if self.padding_bottom > 0:
            lines = lines + [''] * self.padding_bottom

        # Apply horizontal padding to each line
        pad_str = ' ' * self.padding_left
        pad_right = ' ' * self.padding_right
        padded = [f'{pad_str}{line}{pad_right}' for line in lines]

        # Apply border
        if self.border_style and self.border_style != BorderStyle.NONE:
            border_chars = self.border_style.chars()
            tl, top, tr, right, br, bot, bl, left = border_chars

            border_color = self.border_color or ''
            border_reset = RESET
            top_border = f'{border_color}{tl}{top}{tr}{border_reset}'
            bot_border = f'{border_color}{bl}{bot}{br}{border_reset}'
            side_border = f'{border_color}{right}{border_reset}'
            left_border = f'{border_color}{left}{border_reset}'

            # Build top border with optional title
            inner_width = 0
            if padded:
                inner_width = max(string_width(line) for line in padded)
            # top border spans inner_width + left_pad + right_pad
            top_line = f'{border_color}{tl}{top}{tr}{border_reset}'
            bot_line = f'{border_color}{bl}{bot}{br}{border_reset}'

            # Simple approach: single-line borders
            # For multi-line, we build the full box
            top_str = f'{border_color}{tl}{top * max(1, inner_width)}{tr}{border_reset}'
            bot_str = f'{border_color}{bl}{bot * max(1, inner_width)}{br}{border_reset}'

            result_lines = []
            result_lines.append(top_str)
            for line in padded:
                line_w = string_width(line)
                fill = ' ' * max(0, inner_width - line_w)
                result_lines.append(f'{border_color}{left}{border_reset}{line}{fill}{border_color}{right}{border_reset}')
            result_lines.append(bot_str)
            return '\n'.join(result_lines)

        return '\n'.join(padded)


def _copy_with(s: Style, **kwargs) -> Style:
    """Create a new Style with updated fields."""
    import copy
    new_s = copy.copy(s)
    for k, v in kwargs.items():
        setattr(new_s, k, v)
    return new_s


def new() -> Style:
    """Create a fresh default Style."""
    return Style()


def string_width(s: str) -> int:
    """
    Compute the display width of a string (counting ANSI escape sequences as zero width).

    This mimics candy-core's Width::string().
    """
    # Strip ANSI escape sequences
    import re
    stripped = re.sub(r'\x1b\[[0-9;]*m', '', s)
    # Count double-width (CJK) characters as 2
    import unicodedata
    width = 0
    for ch in stripped:
        if unicodedata.east_asian_width(ch) in ('F', 'W'):
            width += 2
        else:
            width += 1
    return width


def truncate(s: str, max_width: int) -> str:
    """
    Truncate string to max_width display columns, preserving the last character
    if it would be cut in half.

    Mimics candy-core's Width::truncate().
    """
    import re
    stripped = re.sub(r'\x1b\[[0-9;]*m', '', s)
    result = []
    width = 0
    for ch in stripped:
        if unicodedata.east_asian_width(ch) in ('F', 'W'):
            char_w = 2
        else:
            char_w = 1
        if width + char_w > max_width:
            break
        result.append(ch)
        width += char_w
    return ''.join(result)


def of(s: str) -> int:
    """Alias for string_width."""
    return string_width(s)
