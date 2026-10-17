"""
Conversation role for a Message.
"""

from enum import Enum


class Role(Enum):
    """Conversation role for a message."""
    SYSTEM = 'system'
    USER = 'user'
    ASSISTANT = 'assistant'

    def __str__(self) -> str:
        return self.value
