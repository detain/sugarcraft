"""
Pane types for the SugarCrush TUI layout.
Ports src/Tui/Pane.php
"""

from enum import Enum


class Pane(Enum):
    """Pane types for the TUI layout."""
    CHAT = 'chat'
    INPUT = 'input'
    SKILLS = 'skills'
    AGENTS = 'agents'
    FILES = 'files'
    TOOLS = 'tools'
    SETTINGS = 'settings'
    HELP = 'help'
    MENU = 'menu'

    def next(self) -> 'Pane':
        """Returns the next pane in the cycling order."""
        return {
            Pane.CHAT: Pane.FILES,
            Pane.FILES: Pane.TOOLS,
            Pane.TOOLS: Pane.SKILLS,
            Pane.SKILLS: Pane.AGENTS,
            Pane.AGENTS: Pane.SETTINGS,
            Pane.SETTINGS: Pane.CHAT,
        }.get(self, Pane.CHAT)

    def label(self) -> str:
        """Returns a human-readable label for the pane."""
        return {
            Pane.CHAT: 'Chat',
            Pane.INPUT: 'Input',
            Pane.SKILLS: 'Skills',
            Pane.AGENTS: 'Agents',
            Pane.FILES: 'Files',
            Pane.TOOLS: 'Tools',
            Pane.SETTINGS: 'Settings',
            Pane.HELP: 'Help',
            Pane.MENU: 'Menu',
        }.get(self, 'Unknown')
