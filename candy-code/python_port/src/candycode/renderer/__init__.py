"""
Main renderer module for the CandyCode TUI.
Ports src/Renderer.php - the pure view function for Chat transcript rendering.
"""

from candycode.renderer.chat_renderer import ChatRenderer, Renderer
from candycode.renderer.style import Style
from candycode.renderer.markdown import MarkdownRenderer
from candycode.renderer.diff import DiffRenderer
from candycode.renderer.image import ImageRenderer
from candycode.renderer.zones import ZoneScanner, ZoneMarker

__all__ = [
    'ChatRenderer',
    'Renderer',
    'Style',
    'MarkdownRenderer',
    'DiffRenderer',
    'ImageRenderer',
    'ZoneScanner',
    'ZoneMarker',
]
