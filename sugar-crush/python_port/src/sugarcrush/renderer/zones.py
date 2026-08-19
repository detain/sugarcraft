"""
Mouse zone handling for clickable UI regions.
Ports the zone scanning and marking from src/Renderer.php.
"""

import re
from dataclasses import dataclass, field
from typing import Optional


# Zone marker codepoints (Private Use Area)
SENTINEL_OPEN = '\ue000'   # U+E000
SENTINEL_CLOSE = '\ue001'  # U+E001
MAX_ID_BYTES = 64

# Zone prefixes
SESSION_TAB_ZONE_PREFIX = 'tab:'
PANE_ZONE_PREFIX = 'pane:'
PALETTE_ITEM_ZONE_PREFIX = 'picker-item:'
TOOL_CALL_ZONE_PREFIX = 'toolcall:'

# Charset for zone IDs (must match PHP's ZONE_ID_CHARSET)
ZONE_ID_PATTERN = re.compile(r'^[A-Za-z0-9._:-]+$')


@dataclass
class Zone:
    """A clickable zone in the rendered frame."""
    id: str
    x1: int
    y1: int
    x2: int
    y2: int


@dataclass
class ZoneScanner:
    """
    Registry of clickable zones, populated by scanning the rendered frame.
    
    Mirrors bubblezone's single global manager.
    """
    zones: list[Zone] = field(default_factory=list)
    
    def clear(self) -> None:
        """Clear all registered zones."""
        self.zones = []
    
    def scan(self, frame: str, width: int) -> None:
        """
        Scan the frame for zone markers and register their positions.
        
        Args:
            frame: The rendered frame string
            width: Viewport width for clamping zone end columns
        """
        self.zones = []
        
        # Find all zone markers
        # Pattern: \xee\x80\x80 (U+E000) followed by optional id, then \xee\x80\x81 (U+E001)
        pattern = re.compile(r'\xee\x80\x80/?([A-Za-z0-9._:-]*)\xee\x80\x81')
        
        current_zone: Optional[dict] = None
        lines = frame.split('\n')
        current_y = 0
        
        for line in lines:
            # Find markers in this line
            pos = 0
            while pos < len(line):
                # Look for sentinel open
                open_idx = line.find('\ue000', pos)
                if open_idx == -1:
                    break
                
                # Look for matching close
                close_idx = line.find('\ue001', open_idx + 1)
                if close_idx == -1:
                    break
                
                # Extract zone ID
                marker_content = line[open_idx:close_idx + 1]
                id_match = re.search(r'\xee\x80\x80/?([A-Za-z0-9._:-]*)\xee\x80\x81', marker_content)
                zone_id = id_match.group(1) if id_match else ''
                
                # Register zone (start of zone)
                if not any(z.id == zone_id for z in self.zones):
                    self.zones.append(Zone(
                        id=zone_id,
                        x1=open_idx,
                        y1=current_y,
                        x2=close_idx,
                        y2=current_y,
                    ))
                
                pos = close_idx + 1
            
            current_y += 1
    
    def zone_at(self, x: int, y: int) -> Optional[str]:
        """
        Find the zone at the given coordinates.
        
        Returns the zone ID if found, None otherwise.
        """
        for zone in self.zones:
            if zone.x1 <= x <= zone.x2 and zone.y1 <= y <= zone.y2:
                return zone.id
        return None


class ZoneMarker:
    """
    Emits zone sentinel markers for clickable regions.
    """
    
    @staticmethod
    def zone(zone_id: str, content: str) -> str:
        """
        Wrap content in a zone marker.
        
        Args:
            zone_id: Unique identifier for the zone
            content: The content to wrap
            
        Returns:
            Content wrapped with zone sentinels
        """
        if not zone_id or not content:
            return content
        
        # Validate zone ID
        if not ZONE_ID_PATTERN.match(zone_id):
            return content
        
        if len(zone_id.encode('utf-8')) > MAX_ID_BYTES:
            return content
        
        return f'{SENTINEL_OPEN}{zone_id}{SENTINEL_CLOSE}{content}{SENTINEL_OPEN}/{zone_id}{SENTINEL_CLOSE}'
    
    @staticmethod
    def strip_markers(text: str) -> str:
        """Remove all zone markers from text."""
        # Pattern matches \xee\x80\x80 id \xee\x80\x81 or self-closing variant
        return re.sub(r'\xee\x80\x80/?[A-Za-z0-9._:-]*\xee\x80\x81', '', text)
    
    @staticmethod
    def has_markers(text: str) -> bool:
        """Check if text contains any zone markers."""
        return SENTINEL_OPEN in text or SENTINEL_CLOSE in text
