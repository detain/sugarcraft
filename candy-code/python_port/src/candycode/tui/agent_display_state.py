"""
Agent display state for the renderer's agent view.
"""

from dataclasses import dataclass


@dataclass
class AgentDisplayState:
    """
    Display state for one agent, consumed by AgentStatusBar and AgentViewPane.
    """
    name: str
    status: str  # 'working' | 'stopped'
    operation: str
    elapsed_seconds: int = 0
    tokens_used: int = 0
    cost_usd: float = 0.0

    @classmethod
    def new(
        cls,
        name: str,
        status: str,
        operation: str,
        elapsed_seconds: int = 0,
        tokens_used: int = 0,
        cost_usd: float = 0.0,
    ) -> 'AgentDisplayState':
        return cls(name, status, operation, elapsed_seconds, tokens_used, cost_usd)

    def elapsed_display(self) -> str:
        """Format elapsed time as '0s', '1m 30s', etc."""
        total = self.elapsed_seconds
        if total < 60:
            return f'{total}s'
        minutes = total // 60
        seconds = total % 60
        if minutes < 60:
            return f'{minutes}m {seconds}s'
        hours = minutes // 60
        minutes = minutes % 60
        return f'{hours}h {minutes}m'

    def usage_display(self) -> str:
        """Format token/cost usage as '0 tok | $0.0000'."""
        tokens = self.tokens_used
        cost = self.cost_usd
        return f'{tokens} tok | ${cost:.4f}'
