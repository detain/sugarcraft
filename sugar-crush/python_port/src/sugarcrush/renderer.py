"""
Main renderer module for the SugarCrush TUI.
Ports src/Renderer.php - the pure view function for Chat transcript rendering.
"""

from sugarcrush.renderer.chat_renderer import ChatRenderer
from sugarcrush.renderer.style import Style
from sugarcrush.renderer.markdown import MarkdownRenderer
from sugarcrush.renderer.diff import DiffRenderer
from sugarcrush.renderer.image import ImageRenderer
from sugarcrush.renderer.zones import ZoneScanner, ZoneMarker

__all__ = [
    'ChatRenderer',
    'Style',
    'MarkdownRenderer',
    'DiffRenderer',
    'ImageRenderer',
    'ZoneScanner',
    'ZoneMarker',
]
