<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Support;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Width;

/**
 * S-X04 drift guard: byte-budget clips ride sugar-core {@see Width::clipBytes()}
 * (marker-in-budget, UTF-8-safe). A file that hand-rolls the cut with
 * `mb_strcut()` is a private clip body; the roster below is the census of the
 * seats still open (each is a distinct truncation law that is not a plain
 * marker-in-budget clip, or awaits its own one-seat-per-commit migration).
 * The roster is fail-closed both ways: a NEW seat is refused, and a seat that
 * migrated must be struck from the list so the census only ever shrinks.
 */
final class ClipCurrencyCensusTest extends TestCase
{
    private const ROSTER = [
            'src/Agents/Live/AgentRunCards.php',
            'src/Agents/Live/SubAgentTranscriptLog.php',
            'src/Attachments/ContextMentions.php',
            'src/Attachments/FileMentions.php',
            'src/Backend/ChildChannel.php',
            'src/Backend/MailboxTurnInbox.php',
            'src/Backend/PendingAsk.php',
            'src/Background/ActiveSubagentsBlock.php',
            'src/Cli/HeadlessPermissionPrompt.php',
            'src/Context/InstructionFileLoader.php',
            'src/Context/MemoryBlock.php',
            'src/Context/RulePathNudge.php',
            'src/Hooks/BuiltIn/AuditHook.php',
            'src/Hooks/BuiltIn/ProtectFilesHook.php',
            'src/Hooks/HookLogEntry.php',
            'src/Host/TranscriptProjector.php',
            'src/Lint/LintReport.php',
            'src/Lint/TestReport.php',
            'src/Memory/MemoryStore.php',
            'src/Permissions/AskOrigin.php',
            'src/Protocol/Methods/BgMethods.php',
            'src/Protocol/Methods/MediaMethods.php',
            'src/Protocol/Methods/SessionMethods.php',
            'src/Protocol/Methods/ToolMethods.php',
            'src/Protocol/Methods/WorkflowMethods.php',
            'src/Server/ErrorLog.php',
            'src/Server/Ws/Connection.php',
            'src/Session/SessionStore.php',
            'src/Skills/SkillPromptLine.php',
            'src/Support/HookContextFiles.php',
            'src/Support/ToolOutputSpill.php',
            'src/Tools/BuiltIn/MemoryTool.php',
            'src/Tools/BuiltIn/PlanExitTool.php',
            'src/Tools/BuiltIn/Read.php',
            'src/Tools/BuiltIn/Recall.php',
            'src/Tools/BuiltIn/SkillsSearch.php',
            'src/Tools/BuiltIn/TaskTool.php',
            'src/Tools/BuiltIn/WebFetch.php',
            'src/Tools/BuiltIn/WorkflowTool.php',
            'src/Tools/Concerns/TruncatesOutput.php',
            'src/Tools/Edit/EditFailureHints.php',
            'src/Tools/Edit/PatchParser.php',
            'src/Tools/ToolCall.php',
            'src/Tools/Web/Search/SearchBackends.php',
            'src/Workflows/WorkflowNarration.php',
            'src/Workspace/CommitMessageWriter.php',
    ];

    public function testNoNewPrivateByteClipBodies(): void
    {
        $found = [];
        $root = dirname(__DIR__, 2);
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            foreach (file($file->getPathname()) ?: [] as $line) {
                if (preg_match('/^\s*(\*|\/\/|\/\*)/', $line) === 1) {
                    continue;
                }
                if (preg_match('/\bmb_strcut\s*\(/', $line) === 1) {
                    $found[] = 'src' . substr($file->getPathname(), strlen($root . '/src'));
                    break;
                }
            }
        }
        sort($found);

        self::assertSame(
            self::ROSTER,
            $found,
            'mb_strcut() seats drifted: new byte clips use Width::clipBytes(); migrated seats leave the roster',
        );
    }

    public function testTheMigratedSeatsDelegateToTheLibraryClip(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (['src/Context/RepoMapBlock.php', 'src/Context/MemoryRecallBlock.php', 'src/Context/InstructionDiffTracker.php', 'src/Memory/CompactionJournal.php'] as $seat) {
            self::assertStringContainsString('Width::clipBytes(', (string) file_get_contents($root . '/' . $seat), $seat);
        }
    }
}
