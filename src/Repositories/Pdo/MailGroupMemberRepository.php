<?php

declare(strict_types=1);

namespace MailPanel\Repositories\Pdo;

class MailGroupMemberRepository extends AbstractPdoRepository
{
    public function forGroupIds(array $groupIds): array
    {
        $groupIds = array_values(array_unique(array_filter(array_map(
            static fn (mixed $groupId): int => (int) $groupId,
            $groupIds
        ), static fn (int $groupId): bool => $groupId > 0)));

        if ($groupIds === []) {
            return [];
        }

        if (count($groupIds) > 1000) {
            throw new \InvalidArgumentException('Too many mail group IDs requested.');
        }

        $placeholders = implode(',', array_fill(0, count($groupIds), '?'));
        return $this->fetchAll("SELECT * FROM mail_group_members WHERE group_id IN ($placeholders) ORDER BY id ASC", $groupIds);
    }

    public function replaceMembers(int $groupId, array $recipients): void
    {
        $this->execute('DELETE FROM mail_group_members WHERE group_id = :group_id', ['group_id' => $groupId]);

        foreach ($recipients as $recipient) {
            $this->execute(
                'INSERT INTO mail_group_members (group_id, recipient_address, created_at, updated_at) VALUES (:group_id, :recipient_address, NOW(), NOW())',
                ['group_id' => $groupId, 'recipient_address' => $recipient]
            );
        }
    }
}
