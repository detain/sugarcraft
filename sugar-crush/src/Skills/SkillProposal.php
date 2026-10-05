<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Skills;

use SugarCraft\Crush\Memory\ConsolidationPlan;

/**
 * One skill the dream pass proposes (roadmap 5.4-3, the opt-in propose mode):
 * a name, a one-line description and a markdown body, as the model wrote them.
 *
 * MODEL OUTPUT, SO NOTHING HERE IS TRUSTED YET. {@see fromReply()} only peels
 * the fields out of the dream's JSON answer; every check that makes one safe
 * to put on disk — the name sanitised, secrets redacted, the size caps, the
 * frontmatter parsed back by the skill loader — is {@see ProposedSkills::propose()}'s,
 * the one writer of a draft. A proposal never reaches a live skills directory:
 * the only way a draft becomes a skill is the user's `/skills accept`.
 */
final readonly class SkillProposal
{
    private function __construct(
        public string $name,
        public string $description,
        public string $body,
    ) {
    }

    public static function new(string $name, string $description, string $body): self
    {
        return new self($name, $description, $body);
    }

    /**
     * The proposals in a dream answer's `skills` list, at most
     * {@see ProposedSkills::MAX_PER_PASS} of them; entries that are not an
     * object with string `name`, `description` and `body` are dropped. Never
     * throws: an answer without the list proposes nothing.
     *
     * @return list<self>
     */
    public static function fromReply(string $reply): array
    {
        $json = ConsolidationPlan::extractObject($reply);
        if ($json === null) {
            return [];
        }

        try {
            $data = json_decode($json, true, 16, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        $raw = \is_array($data) ? ($data['skills'] ?? []) : [];
        if (!\is_array($raw) || !array_is_list($raw)) {
            return [];
        }

        $proposals = [];
        foreach ($raw as $entry) {
            if (!\is_array($entry)
                || !\is_string($entry['name'] ?? null)
                || !\is_string($entry['description'] ?? null)
                || !\is_string($entry['body'] ?? null)) {
                continue;
            }
            $proposals[] = new self($entry['name'], $entry['description'], $entry['body']);
            if (\count($proposals) === ProposedSkills::MAX_PER_PASS) {
                break;
            }
        }

        return $proposals;
    }
}
