"""
View class for renderer output, including optional image placements.
"""

from dataclasses import dataclass
from typing import Optional


@dataclass
class ImagePlacement:
    """A placed image in the frame."""
    id: int
    body: str
    cols: int
    rows: int


@dataclass
class View:
    """
    The renderer's complete output for one frame.
    
    Includes the text body and any image placements for pixel-graphics
    protocols (Sixel/Kitty/iTerm2).
    """
    body: str
    images: list[ImagePlacement] = None
    
    def __post_init__(self):
        if self.images is None:
            self.images = []
    
    def __str__(self) -> str:
        """String conversion returns the body, matching the simple case contract."""
        return self.body
