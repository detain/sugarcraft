"""
ToolResult class representing the result of a tool/function call.
"""

from dataclasses import dataclass
from typing import Optional


@dataclass
class ToolResult:
    """
    Represents the result of a tool/function call.
    
    Tool results are added to the conversation history so the AI
    can see the outcome of its requested action and respond accordingly.
    """
    name: str
    result: str
    error: Optional[str] = None
    id: Optional[str] = None
    image_bytes: Optional[str] = None
    image_path: Optional[str] = None
    image_protocol: Optional[str] = None
    diff: Optional[str] = None
    duration_ms: Optional[int] = None
    description: Optional[str] = None
    
    @classmethod
    def ok(cls, name: str, result: str, id: Optional[str] = None) -> 'ToolResult':
        """Create a successful result."""
        return cls(name, result, None, id)
    
    @classmethod
    def error(cls, name: str, error: str, id: Optional[str] = None) -> 'ToolResult':
        """Create an error result."""
        return cls(name, '', error, id)
    
    def is_error(self) -> bool:
        """True when this result represents an error."""
        return self.error is not None
    
    def has_description(self) -> bool:
        """True when this result knows the call it answers."""
        return self.description is not None
    
    def has_image(self) -> bool:
        """True when this result carries image bytes for in-TUI rendering."""
        return self.image_bytes is not None
    
    def has_diff(self) -> bool:
        """True when this result carries a unified diff for rendering."""
        return self.diff is not None
    
    def with_description(self, description: Optional[str]) -> 'ToolResult':
        """Create a new result with a description attached."""
        trimmed = description.strip() if description else None
        return ToolResult(
            name=self.name,
            result=self.result,
            error=self.error,
            id=self.id,
            image_bytes=self.image_bytes,
            image_path=self.image_path,
            image_protocol=self.image_protocol,
            diff=self.diff,
            duration_ms=self.duration_ms,
            description=trimmed if trimmed else None,
        )
