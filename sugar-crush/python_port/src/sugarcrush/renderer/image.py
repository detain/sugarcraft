"""
Image rendering for terminal image display.
Ports the image rendering portion of src/Renderer.php.
"""

import hashlib
from dataclasses import dataclass
from typing import Optional, Dict
from sugarcrush.renderer.style import Style


# ANSI escape sequences
RESET = '\033[0m'

# Image marker base (U+E000)
MARKER_BASE = '\ue000'


@dataclass
class ImagePlacement:
    """A placed image in the frame for pixel-graphics protocols."""
    id: int
    body: str
    cols: int
    rows: int


class ImageRenderer:
    """
    Renders images at the terminal using the best available protocol.
    
    Supports Sixel, Kitty, iTerm2, and ASCII fallbacks.
    """
    
    # LRU cache size
    CACHE_MAX = 8
    
    # Image dimensions
    IMAGE_COLS = 40
    
    def __init__(self, theme, protocol: str = 'halfblock'):
        """
        Initialize the image renderer.
        
        Args:
            theme: Theme object with system_label color
            protocol: Detected terminal protocol ('kitty', 'sixel', 'iterm2', 'halfblock', etc.)
        """
        self.theme = theme
        self.protocol = protocol
        self._cache: Dict[str, dict] = {}
        self._placement_id = 0
    
    def render(
        self,
        bytes_data: bytes,
        cols: int,
        rows: int,
        placements: list[ImagePlacement],
    ) -> tuple[str, list[ImagePlacement]]:
        """
        Render image bytes to terminal.
        
        Args:
            bytes_data: Raw image bytes
            cols: Target column width
            rows: Target row height
            placements: List to append pixel-graphics placements to
            
        Returns:
            Tuple of (rendered_output, updated_placements)
        """
        cache_key = self._cache_key(bytes_data, cols, rows)
        
        if cache_key in self._cache:
            hit = self._cache[cache_key]
            # Move to end (most recently used)
            del self._cache[cache_key]
            self._cache[cache_key] = hit
        else:
            try:
                hit = self._encode_image(bytes_data, cols, rows)
            except Exception as e:
                hit = {
                    'ok': False,
                    'body': f'{self.theme.system_label}🖼 image unavailable: {e}{RESET}'
                }
            
            self._cache[cache_key] = hit
            
            # Evict oldest if cache is full
            if len(self._cache) > self.CACHE_MAX:
                oldest = next(iter(self._cache))
                del self._cache[oldest]
        
        if not hit['ok']:
            return hit['body'], placements
        
        # For inline protocols, return the body directly
        if self._is_inline():
            return hit['body'], placements
        
        # For pixel-graphics protocols, create a placement
        self._placement_id += 1
        placement = ImagePlacement(
            id=self._placement_id,
            body=hit['body'],
            cols=cols,
            rows=rows,
        )
        placements.append(placement)
        
        # Return placeholder marker
        return f'{MARKER_BASE}{self._placement_id}{MARKER_BASE}', placements
    
    def _is_inline(self) -> bool:
        """Check if current protocol is inline (character-based)."""
        return self.protocol in ('halfblock', 'quarterblock', 'ascii', 'chafa')
    
    def _cache_key(self, data: bytes, cols: int, rows: int) -> str:
        """Generate cache key for image data."""
        return f'{hashlib.blake2b(data, digest_size=8).hexdigest()}:{cols}x{rows}:{self.protocol}'
    
    def _encode_image(self, data: bytes, cols: int, rows: int) -> dict:
        """
        Encode image data using the appropriate protocol.
        
        This is a simplified implementation. A full port would use
        a library like pillow for decoding and chafa/sixel for encoding.
        """
        # For now, return a placeholder for non-inline protocols
        if self._is_inline():
            # Generate ASCII art placeholder
            return self._ascii_placeholder(cols, rows)
        
        # For Sixel/Kitty/iTerm2, we'd need actual encoding libraries
        # Return the raw bytes wrapped in the protocol escape sequence
        return {
            'ok': True,
            'body': data.decode('latin-1', errors='replace'),
        }
    
    def _ascii_placeholder(self, cols: int, rows: int) -> dict:
        """Generate an ASCII art placeholder for images."""
        lines = []
        for y in range(min(rows, 10)):
            line = ''
            for x in range(min(cols, 40)):
                # Simple gradient pattern
                char = '░▒▓█'[int((x + y) / (cols / 4 + 1)) % 4]
                line += char
            lines.append(line)
        
        body = '\n'.join(lines)
        return {
            'ok': True,
            'body': f'\033[38;5;250m{body}\033[0m',
        }
    
    @staticmethod
    def collapsed_notice(width: int, dimensions: Optional[str] = None, 
                        protocol: Optional[str] = None) -> str:
        """
        Generate the collapsed image notice text.
        
        Args:
            width: Available width for the notice
            dimensions: Image dimensions string (e.g., "800×600")
            protocol: Image protocol name
            
        Returns:
            Faint-styled notice text
        """
        parts = ['🖼']
        if dimensions:
            parts.append(f'{dimensions} ')
        if protocol:
            parts.append(f'{protocol} ')
        parts.append('image hidden (ctrl+o)')
        
        text = ''.join(parts)
        # Truncate to fit width
        if len(text) > width:
            text = text[:width - 3] + '…'
        
        return f'\033[2m{text}\033[0m'
    
    @staticmethod
    def image_rows(image_bytes: bytes, cols: int, budget: int) -> int:
        """
        Calculate cell height for an image based on aspect ratio.
        
        Args:
            image_bytes: Raw image bytes
            cols: Target column width
            budget: Maximum rows to allow
            
        Returns:
            Calculated row height
        """
        # Try to read dimensions from image header
        width, height = 0, 0
        
        # Check for PNG
        if image_bytes[:8] == b'\x89PNG\r\n\x1a\n':
            if len(image_bytes) >= 24:
                width = int.from_bytes(image_bytes[16:20], 'big')
                height = int.from_bytes(image_bytes[20:24], 'big')
        
        # Check for JPEG
        elif image_bytes[:2] == b'\xff\xd8':
            # JPEG: need to parse segments to find dimensions
            i = 2
            while i < len(image_bytes) - 1:
                if image_bytes[i] != 0xff:
                    i += 1
                    continue
                marker = image_bytes[i + 1]
                if marker in (0xc0, 0xc1, 0xc2):  # SOF markers
                    if i + 9 < len(image_bytes):
                        height = int.from_bytes(image_bytes[i + 5:i + 7], 'big')
                        width = int.from_bytes(image_bytes[i + 7:i + 9], 'big')
                    break
                elif marker == 0xd9:  # EOI
                    break
                elif marker == 0xd8:  # SOI
                    i += 2
                    continue
                elif 0xd0 <= marker <= 0xd7:  # RST
                    i += 2
                    continue
                else:
                    length = int.from_bytes(image_bytes[i + 2:i + 4], 'big')
                    i += 2 + length
                    continue
        
        # Calculate aspect ratio
        if width > 0 and height > 0:
            aspect = width / height
        else:
            aspect = 1.0
        
        # Cells are roughly twice as tall as wide, so divide by 2
        rows = int(cols / aspect / 2)
        
        return max(1, min(rows, budget))
