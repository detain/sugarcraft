"""
Message class for a chat conversation turn.
"""

from dataclasses import dataclass
from typing import Optional
from sugarcrush.role import Role


@dataclass
class Message:
    """
    One turn in a chat conversation. Immutable, role-tagged.
    
    Content stays as a plain string here — Markdown is rendered
    lazily at view time.
    """
    role: Role
    content: str
    created_at: int
    attachments: list = None
    tool_calls: list = None
    tool_results: list = None
    pending_tool_call_id: Optional[str] = None
    reasoning: Optional[str] = None
    image_bytes: Optional[str] = None
    image_protocol: Optional[str] = None
    
    def __post_init__(self):
        self.attachments = self.attachments or []
        self.tool_calls = self.tool_calls or []
        self.tool_results = self.tool_results or []
    
    @classmethod
    def user(cls, content: str, now: Optional[int] = None) -> 'Message':
        """Create a user message."""
        return cls(Role.USER, content, now or 0)
    
    @classmethod
    def assistant(cls, content: str, now: Optional[int] = None, reasoning: Optional[str] = None) -> 'Message':
        """Create an assistant message."""
        return cls(Role.ASSISTANT, content, now or 0, reasoning=reasoning)
    
    @classmethod
    def system(cls, content: str, now: Optional[int] = None) -> 'Message':
        """Create a system message."""
        return cls(Role.SYSTEM, content, now or 0)
    
    @classmethod
    def tool_running(cls, name: str, description: str, call_id: Optional[str] = None) -> 'Message':
        """Create a transient 'tool X is running' placeholder."""
        return cls(
            role=Role.SYSTEM,
            content=description,
            created_at=0,
            pending_tool_call_id=call_id or name,
        )
    
    def with_tool_results(self, results: list) -> 'Message':
        """Create a new message with tool results attached."""
        return Message(
            role=self.role,
            content=self.content,
            created_at=self.created_at,
            attachments=self.attachments,
            tool_calls=self.tool_calls,
            tool_results=results,
            pending_tool_call_id=None,
            reasoning=self.reasoning,
            image_bytes=self.image_bytes,
            image_protocol=self.image_protocol,
        )
    
    def with_reasoning(self, reasoning: Optional[str]) -> 'Message':
        """Create a new message with reasoning attached."""
        return Message(
            role=self.role,
            content=self.content,
            created_at=self.created_at,
            attachments=self.attachments,
            tool_calls=self.tool_calls,
            tool_results=self.tool_results,
            pending_tool_call_id=self.pending_tool_call_id,
            reasoning=reasoning,
            image_bytes=self.image_bytes,
            image_protocol=self.image_protocol,
        )
