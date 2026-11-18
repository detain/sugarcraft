"""
CandyCode Python Port
A Python implementation of the candy-code TUI chat renderer.

Experimental. This package does not import: `candycode.renderer` imports
`candycode.renderer.chat_renderer`, which does not exist, so importing `candycode`
raises ModuleNotFoundError until that import is fixed.
"""

from candycode.renderer import Renderer, ChatRenderer
from candycode.theme import Theme
from candycode.role import Role
from candycode.view import View

__all__ = ['Renderer', 'ChatRenderer', 'Theme', 'Role', 'View']
