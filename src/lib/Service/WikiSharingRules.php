<?php

declare(strict_types=1);

namespace OCA\HcHomeWiki\Service;

/** Pure page-policy transformations; no I/O and no implicit grants. */
final class WikiSharingRules {
    /** Extract exactly the selected ancestor's grants for a child. */
    public static function fromAncestor(array $ancestor, array $acl): array {
        $visibility = (string)($ancestor['visibility'] ?? 'private');
        if (!in_array($visibility, ['private', 'shared', 'all'], true)) {
            throw new \InvalidArgumentException('Invalid ancestor sharing state');
        }
        return [
            'visibility' => $visibility,
            'allPermissions' => [
                'read' => $visibility === 'all',
                'edit' => $visibility === 'all' && (bool)($ancestor['all_can_edit'] ?? false),
                'delete' => $visibility === 'all' && (bool)($ancestor['all_can_delete'] ?? false),
                'share' => $visibility === 'all' && (bool)($ancestor['all_can_share'] ?? false),
            ],
            'acl' => $visibility === 'private' ? [] : $acl,
        ];
    }

    /** Clear all prior grants when ownership changes. */
    public static function privateForRecipient(array $page, string $recipientUid, string $slug, string $filePath, bool $root): array {
        $new = $page;
        $new['owner_uid'] = $recipientUid;
        $new['slug'] = $slug;
        $new['file_path'] = $filePath;
        $new['parent_id'] = $root ? null : ($page['parent_id'] ?? null);
        $new['visibility'] = 'private';
        $new['all_can_edit'] = false;
        $new['all_can_delete'] = false;
        $new['all_can_share'] = false;
        $new['inherit_sharing'] = false;
        $new['revision'] = (int)$page['revision'] + 1;
        $new['updated_at'] = time();
        return $new;
    }
}
