"""
Color theme for the renderer.
"""

from dataclasses import dataclass
from typing import Optional


# Predefined color sets (ANSI escape sequences)
DARK_THEME = {
    'border': '\033[38;5;241m',  # Gray
    'user_label': '\033[38;5;75m',  # Blue
    'assistant_label': '\033[38;5;78m',  # Cyan
    'system_label': '\033[38;5;250m',  # Light gray
    'markdown': {
        'h1': '\033[1;38;5;75m',  # Bold blue
        'h2': '\033[1;38;5;141m',  # Bold purple
        'code': '\033[38;5;117m',  # Light blue
        'link': '\033[38;5;75m\033[4m',  # Blue underlined
        'bold': '\033[1m',
        'italic': '\033[3m',
    }
}

LIGHT_THEME = {
    'border': '\033[38;5;250m',
    'user_label': '\033[38;5;33m',  # Dark blue
    'assistant_label': '\033[38;5;31m',  # Dark red
    'system_label': '\033[38;5;245m',  # Gray
    'markdown': {
        'h1': '\033[1;38;5;33m',
        'h2': '\033[1;38;5;125m',
        'code': '\033[38;5;24m',
        'link': '\033[38;5;33m\033[4m',
        'bold': '\033[1m',
        'italic': '\033[3m',
    }
}

DRACULA_THEME = {
    'border': '\033[38;5;139m',  # Purple
    'user_label': '\033[38;5;219m',  # Pink
    'assistant_label': '\033[38;5;121m',  # Green
    'system_label': '\033[38;5;250m',  # Gray
    'markdown': {
        'h1': '\033[1;38;5;219m',
        'h2': '\033[1;38;5;141m',
        'code': '\033[38;5;249m',
        'link': '\033[38;5;81m\033[4m',
        'bold': '\033[1m',
        'italic': '\033[3m',
    }
}

TOKYO_NIGHT_THEME = {
    'border': '\033[38;5;139m',
    'user_label': '\033[38;5;117m',  # Blue
    'assistant_label': '\033[38;5;151m',  # Green
    'system_label': '\033[38;5;250m',
    'markdown': {
        'h1': '\033[1;38;5;117m',
        'h2': '\033[1;38;5;141m',
        'code': '\033[38;5;117m',
        'link': '\033[38;5;117m\033[4m',
        'bold': '\033[1m',
        'italic': '\033[3m',
    }
}

ANSI_THEME = {
    'border': '\033[33m',  # Yellow
    'user_label': '\033[34m',  # Blue
    'assistant_label': '\033[32m',  # Green
    'system_label': '\033[36m',  # Cyan
    'markdown': {
        'h1': '\033[1;34m',
        'h2': '\033[1;36m',
        'code': '\033[33m',
        'link': '\033[4m',
        'bold': '\033[1m',
        'italic': '\033[3m',
    }
}

THEMES = {
    'dark': DARK_THEME,
    'light': LIGHT_THEME,
    'dracula': DRACULA_THEME,
    'tokyoNight': TOKYO_NIGHT_THEME,
    'ansi': ANSI_THEME,
}

NAMES = list(THEMES.keys())


@dataclass
class Theme:
    """
    A named color theme for the renderer's chrome and markdown rendering.
    """
    name: str
    border: str
    user_label: str
    assistant_label: str
    system_label: str
    markdown: dict

    @classmethod
    def by_name(cls, name: str) -> 'Theme':
        """Create a theme by name."""
        canonical = name.lower()
        if canonical not in THEMES:
            raise ValueError(f"Unknown theme '{name}'. Available themes: {', '.join(NAMES)}.")
        
        colors = THEMES[canonical]
        return cls(
            name=canonical,
            border=colors['border'],
            user_label=colors['user_label'],
            assistant_label=colors['assistant_label'],
            system_label=colors['system_label'],
            markdown=colors['markdown'],
        )

    @classmethod
    def default(cls) -> 'Theme':
        """Get the default (dark) theme."""
        return cls.by_name('dark')

    @classmethod
    def names(cls) -> list:
        """Get list of available theme names."""
        return NAMES.copy()
