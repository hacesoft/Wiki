<?php

declare(strict_types=1);

namespace OCA\HcHomeWiki\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Výchozí databázové schéma Home Wiki (baseline v1).
 *
 * Vstup: aktuální databázové schéma předané Nextcloudem.
 * Výstup: schéma doplněné o všechny tabulky, sloupce a indexy Home Wiki.
 * Existující data se nemažou.
 */
final class Version0001Date20261011160000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        // CZ-FUNKCE: Provádí dílčí operaci `changeSchema` v rámci Home Wiki. Vstup: `$output`, `$schemaClosure`, `$options`. Výstup: hodnota typu `?ISchemaWrapper`.
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        $addColumn = static function ($table, string $name, string $type, array $options = []): void {
            if (!$table->hasColumn($name)) {
                $table->addColumn($name, $type, $options);
            }
        };
        $addIndex = static function ($table, array $columns, string $name, bool $unique = false): void {
            if ($table->hasIndex($name)) {
                return;
            }
            if ($unique) {
                $table->addUniqueIndex($columns, $name);
            } else {
                $table->addIndex($columns, $name);
            }
        };
        $ensurePrimaryKey = static function ($table, array $columns): void {
            if ($table->getPrimaryKey() === null) {
                $table->setPrimaryKey($columns);
            }
        };

        $table = $schema->hasTable('hc_homewiki_pages')
            ? $schema->getTable('hc_homewiki_pages')
            : $schema->createTable('hc_homewiki_pages');
        $addColumn($table, 'id', Types::STRING, ['length' => 36, 'notnull' => true]);
        $addColumn($table, 'owner_uid', Types::STRING, ['length' => 64, 'notnull' => true]);
        $addColumn($table, 'parent_id', Types::STRING, ['length' => 36, 'notnull' => false]);
        $addColumn($table, 'title', Types::STRING, ['length' => 255, 'notnull' => true]);
        $addColumn($table, 'slug', Types::STRING, ['length' => 255, 'notnull' => true]);
        $addColumn($table, 'file_path', Types::STRING, ['length' => 1024, 'notnull' => true]);
        $addColumn($table, 'visibility', Types::STRING, ['length' => 16, 'notnull' => true, 'default' => 'private']);
        $addColumn($table, 'pinned', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
        $addColumn($table, 'sort_order', Types::INTEGER, ['notnull' => true, 'default' => 0]);
        $addColumn($table, 'created_at', Types::BIGINT, ['notnull' => true]);
        $addColumn($table, 'updated_at', Types::BIGINT, ['notnull' => true]);
        $addColumn($table, 'revision', Types::BIGINT, ['notnull' => true, 'default' => 1]);
        $addColumn($table, 'all_can_edit', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
        $addColumn($table, 'all_can_delete', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
        $addColumn($table, 'all_can_share', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
        $ensurePrimaryKey($table, ['id']);
        $addIndex($table, ['owner_uid'], 'hc_hw_pages_owner');
        $addIndex($table, ['parent_id'], 'hc_hw_pages_parent');
        $addIndex($table, ['pinned'], 'hc_hw_pages_pinned');
        $addIndex($table, ['owner_uid', 'slug'], 'hc_hw_pages_owner_slug', true);

        $table = $schema->hasTable('hc_homewiki_acl')
            ? $schema->getTable('hc_homewiki_acl')
            : $schema->createTable('hc_homewiki_acl');
        $addColumn($table, 'id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
        $addColumn($table, 'page_id', Types::STRING, ['length' => 36, 'notnull' => true]);
        $addColumn($table, 'subject_type', Types::STRING, ['length' => 8, 'notnull' => true]);
        $addColumn($table, 'subject_id', Types::STRING, ['length' => 64, 'notnull' => true]);
        $addColumn($table, 'can_read', Types::BOOLEAN, ['notnull' => true, 'default' => true]);
        $addColumn($table, 'can_edit', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
        $addColumn($table, 'can_delete', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
        $addColumn($table, 'can_share', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
        $ensurePrimaryKey($table, ['id']);
        $addIndex($table, ['page_id'], 'hc_hw_acl_page');
        $addIndex($table, ['page_id', 'subject_type', 'subject_id'], 'hc_hw_acl_unique', true);

        $table = $schema->hasTable('hc_homewiki_aliases')
            ? $schema->getTable('hc_homewiki_aliases')
            : $schema->createTable('hc_homewiki_aliases');
        $addColumn($table, 'id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
        $addColumn($table, 'page_id', Types::STRING, ['length' => 36, 'notnull' => true]);
        $addColumn($table, 'owner_uid', Types::STRING, ['length' => 64, 'notnull' => true]);
        $addColumn($table, 'alias', Types::STRING, ['length' => 512, 'notnull' => true]);
        $ensurePrimaryKey($table, ['id']);
        $addIndex($table, ['page_id'], 'hc_hw_alias_page');
        $addIndex($table, ['owner_uid', 'alias'], 'hc_hw_alias_owner_alias', true);

        $table = $schema->hasTable('hc_homewiki_links')
            ? $schema->getTable('hc_homewiki_links')
            : $schema->createTable('hc_homewiki_links');
        $addColumn($table, 'id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
        $addColumn($table, 'source_page_id', Types::STRING, ['length' => 36, 'notnull' => true]);
        $addColumn($table, 'target_page_id', Types::STRING, ['length' => 36, 'notnull' => false]);
        $addColumn($table, 'target_raw', Types::STRING, ['length' => 1024, 'notnull' => true]);
        $addColumn($table, 'link_type', Types::STRING, ['length' => 16, 'notnull' => true]);
        $ensurePrimaryKey($table, ['id']);
        $addIndex($table, ['source_page_id'], 'hc_hw_links_source');
        $addIndex($table, ['target_page_id'], 'hc_hw_links_target');

        $table = $schema->hasTable('hc_homewiki_trash')
            ? $schema->getTable('hc_homewiki_trash')
            : $schema->createTable('hc_homewiki_trash');
        $addColumn($table, 'id', Types::STRING, ['length' => 36, 'notnull' => true]);
        $addColumn($table, 'owner_uid', Types::STRING, ['length' => 64, 'notnull' => true]);
        $addColumn($table, 'title', Types::STRING, ['length' => 255, 'notnull' => true]);
        $addColumn($table, 'trash_path', Types::STRING, ['length' => 1024, 'notnull' => true]);
        $addColumn($table, 'page_json', Types::TEXT, ['notnull' => true]);
        $addColumn($table, 'acl_json', Types::TEXT, ['notnull' => true]);
        $addColumn($table, 'aliases_json', Types::TEXT, ['notnull' => true]);
        $addColumn($table, 'deleted_at', Types::BIGINT, ['notnull' => true]);
        $addColumn($table, 'deleted_by', Types::STRING, ['length' => 64, 'notnull' => true]);
        $ensurePrimaryKey($table, ['id']);
        $addIndex($table, ['owner_uid', 'deleted_at'], 'hc_hw_trash_owner_time');

        $table = $schema->hasTable('hc_homewiki_tags')
            ? $schema->getTable('hc_homewiki_tags')
            : $schema->createTable('hc_homewiki_tags');
        $addColumn($table, 'id', Types::STRING, ['length' => 36, 'notnull' => true]);
        $addColumn($table, 'owner_uid', Types::STRING, ['length' => 64, 'notnull' => true]);
        $addColumn($table, 'name', Types::STRING, ['length' => 64, 'notnull' => true]);
        $addColumn($table, 'normalized_name', Types::STRING, ['length' => 64, 'notnull' => true]);
        $addColumn($table, 'created_at', Types::BIGINT, ['notnull' => true]);
        $addColumn($table, 'updated_at', Types::BIGINT, ['notnull' => true]);
        $ensurePrimaryKey($table, ['id']);
        $addIndex($table, ['owner_uid'], 'hc_hw_tags_owner');
        $addIndex($table, ['owner_uid', 'normalized_name'], 'hc_hw_tags_owner_name', true);

        $table = $schema->hasTable('hc_homewiki_page_tags')
            ? $schema->getTable('hc_homewiki_page_tags')
            : $schema->createTable('hc_homewiki_page_tags');
        $addColumn($table, 'id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
        $addColumn($table, 'page_id', Types::STRING, ['length' => 36, 'notnull' => true]);
        $addColumn($table, 'tag_id', Types::STRING, ['length' => 36, 'notnull' => true]);
        $addColumn($table, 'created_at', Types::BIGINT, ['notnull' => true]);
        $ensurePrimaryKey($table, ['id']);
        $addIndex($table, ['page_id'], 'hc_hw_page_tags_page');
        $addIndex($table, ['tag_id'], 'hc_hw_page_tags_tag');
        $addIndex($table, ['page_id', 'tag_id'], 'hc_hw_page_tags_unique', true);

        return $schema;
    }
}
