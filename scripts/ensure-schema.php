<?php
declare(strict_types=1);

// Re-run on EVERY install, including an interrupted install at the same version.
// Only append absent application tables, columns and indexes. Never drop data.
require '/var/www/html/lib/base.php';
$db = \OC::$server->get(\OCP\IDBConnection::class);
$prefix = (string)\OC::$server->get(\OCP\IConfig::class)->getSystemValue('dbtableprefix', 'oc_');
if (!preg_match('/^[A-Za-z0-9_]+$/D', $prefix)) {
    throw new \RuntimeException('Invalid Nextcloud table prefix');
}
$schema = $db->createSchema();
$changed = false;
$addColumn = static function ($table, string $name, string $type, array $options = []) use (&$changed): void {
    if (!$table->hasColumn($name)) {
        $table->addColumn($name, $type, $options);
        $changed = true;
    }
};
$addIndex = static function ($table, array $columns, string $name, bool $unique = false) use (&$changed): void {
    if (!$table->hasIndex($name)) {
        if ($unique) $table->addUniqueIndex($columns, $name);
        else $table->addIndex($columns, $name);
        $changed = true;
    }
};
$setPrimaryKey = static function ($table, array $columns) use (&$changed): void {
    if (!$table->hasPrimaryKey()) {
        $table->setPrimaryKey($columns);
        $changed = true;
    }
};

// hc_homewiki_pages
if (!$schema->hasTable($prefix . 'hc_homewiki_pages')) $changed = true;
{
$table = $schema->hasTable($prefix . 'hc_homewiki_pages') ? $schema->getTable($prefix . 'hc_homewiki_pages') : $schema->createTable($prefix . 'hc_homewiki_pages');
            $addColumn($table, 'id', 'string', ['length' => 36, 'notnull' => true]);
            $addColumn($table, 'owner_uid', 'string', ['length' => 64, 'notnull' => true]);
            $addColumn($table, 'parent_id', 'string', ['length' => 36, 'notnull' => false]);
            $addColumn($table, 'title', 'string', ['length' => 255, 'notnull' => true]);
            $addColumn($table, 'slug', 'string', ['length' => 255, 'notnull' => true]);
            $addColumn($table, 'file_path', 'string', ['length' => 1024, 'notnull' => true]);
            $addColumn($table, 'visibility', 'string', ['length' => 16, 'notnull' => true, 'default' => 'private']);
            $addColumn($table, 'pinned', 'boolean', ['notnull' => true, 'default' => false]);
            $addColumn($table, 'sort_order', 'integer', ['notnull' => true, 'default' => 0]);
            $addColumn($table, 'created_at', 'bigint', ['notnull' => true]);
            $addColumn($table, 'updated_at', 'bigint', ['notnull' => true]);
            $addColumn($table, 'revision', 'bigint', ['notnull' => true, 'default' => 1]);
            $addColumn($table, 'all_can_edit', 'boolean', ['notnull' => true, 'default' => false]);
            $addColumn($table, 'all_can_delete', 'boolean', ['notnull' => true, 'default' => false]);
            $addColumn($table, 'all_can_share', 'boolean', ['notnull' => true, 'default' => false]);
            $setPrimaryKey($table, ['id']);
            $addIndex($table, ['owner_uid'], 'hc_hw_pages_owner');
            $addIndex($table, ['parent_id'], 'hc_hw_pages_parent');
            $addIndex($table, ['pinned'], 'hc_hw_pages_pinned');
            $addIndex($table, ['owner_uid', 'slug'], 'hc_hw_pages_owner_slug', true);

}

// hc_homewiki_acl
if (!$schema->hasTable($prefix . 'hc_homewiki_acl')) $changed = true;
{
$table = $schema->hasTable($prefix . 'hc_homewiki_acl') ? $schema->getTable($prefix . 'hc_homewiki_acl') : $schema->createTable($prefix . 'hc_homewiki_acl');
            $addColumn($table, 'id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
            $addColumn($table, 'page_id', 'string', ['length' => 36, 'notnull' => true]);
            $addColumn($table, 'subject_type', 'string', ['length' => 8, 'notnull' => true]);
            $addColumn($table, 'subject_id', 'string', ['length' => 64, 'notnull' => true]);
            $addColumn($table, 'can_read', 'boolean', ['notnull' => true, 'default' => true]);
            $addColumn($table, 'can_edit', 'boolean', ['notnull' => true, 'default' => false]);
            $addColumn($table, 'can_delete', 'boolean', ['notnull' => true, 'default' => false]);
            $addColumn($table, 'can_share', 'boolean', ['notnull' => true, 'default' => false]);
            $setPrimaryKey($table, ['id']);
            $addIndex($table, ['page_id'], 'hc_hw_acl_page');
            $addIndex($table, ['page_id', 'subject_type', 'subject_id'], 'hc_hw_acl_unique', true);

}

// hc_homewiki_aliases
if (!$schema->hasTable($prefix . 'hc_homewiki_aliases')) $changed = true;
{
$table = $schema->hasTable($prefix . 'hc_homewiki_aliases') ? $schema->getTable($prefix . 'hc_homewiki_aliases') : $schema->createTable($prefix . 'hc_homewiki_aliases');
            $addColumn($table, 'id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
            $addColumn($table, 'page_id', 'string', ['length' => 36, 'notnull' => true]);
            $addColumn($table, 'owner_uid', 'string', ['length' => 64, 'notnull' => true]);
            $addColumn($table, 'alias', 'string', ['length' => 512, 'notnull' => true]);
            $setPrimaryKey($table, ['id']);
            $addIndex($table, ['page_id'], 'hc_hw_alias_page');
            $addIndex($table, ['owner_uid', 'alias'], 'hc_hw_alias_owner_alias', true);

}

// hc_homewiki_links
if (!$schema->hasTable($prefix . 'hc_homewiki_links')) $changed = true;
{
$table = $schema->hasTable($prefix . 'hc_homewiki_links') ? $schema->getTable($prefix . 'hc_homewiki_links') : $schema->createTable($prefix . 'hc_homewiki_links');
            $addColumn($table, 'id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
            $addColumn($table, 'source_page_id', 'string', ['length' => 36, 'notnull' => true]);
            $addColumn($table, 'target_page_id', 'string', ['length' => 36, 'notnull' => false]);
            $addColumn($table, 'target_raw', 'string', ['length' => 1024, 'notnull' => true]);
            $addColumn($table, 'link_type', 'string', ['length' => 16, 'notnull' => true]);
            $setPrimaryKey($table, ['id']);
            $addIndex($table, ['source_page_id'], 'hc_hw_links_source');
            $addIndex($table, ['target_page_id'], 'hc_hw_links_target');

}

// hc_homewiki_trash
if (!$schema->hasTable($prefix . 'hc_homewiki_trash')) $changed = true;
{
$table = $schema->hasTable($prefix . 'hc_homewiki_trash') ? $schema->getTable($prefix . 'hc_homewiki_trash') : $schema->createTable($prefix . 'hc_homewiki_trash');
            $addColumn($table, 'id', 'string', ['length' => 36, 'notnull' => true]);
            $addColumn($table, 'owner_uid', 'string', ['length' => 64, 'notnull' => true]);
            $addColumn($table, 'title', 'string', ['length' => 255, 'notnull' => true]);
            $addColumn($table, 'trash_path', 'string', ['length' => 1024, 'notnull' => true]);
            $addColumn($table, 'page_json', 'text', ['notnull' => true]);
            $addColumn($table, 'acl_json', 'text', ['notnull' => true]);
            $addColumn($table, 'aliases_json', 'text', ['notnull' => true]);
            $addColumn($table, 'deleted_at', 'bigint', ['notnull' => true]);
            $addColumn($table, 'deleted_by', 'string', ['length' => 64, 'notnull' => true]);
            $setPrimaryKey($table, ['id']);
            $addIndex($table, ['owner_uid', 'deleted_at'], 'hc_hw_trash_owner_time');

}


// hc_homewiki_tags
if (!$schema->hasTable($prefix . 'hc_homewiki_tags')) $changed = true;
{
$table = $schema->hasTable($prefix . 'hc_homewiki_tags') ? $schema->getTable($prefix . 'hc_homewiki_tags') : $schema->createTable($prefix . 'hc_homewiki_tags');
            $addColumn($table, 'id', 'string', ['length' => 36, 'notnull' => true]);
            $addColumn($table, 'owner_uid', 'string', ['length' => 64, 'notnull' => true]);
            $addColumn($table, 'name', 'string', ['length' => 64, 'notnull' => true]);
            $addColumn($table, 'normalized_name', 'string', ['length' => 64, 'notnull' => true]);
            $addColumn($table, 'created_at', 'bigint', ['notnull' => true]);
            $addColumn($table, 'updated_at', 'bigint', ['notnull' => true]);
            $setPrimaryKey($table, ['id']);
            $addIndex($table, ['owner_uid'], 'hc_hw_tags_owner');
            $addIndex($table, ['owner_uid', 'normalized_name'], 'hc_hw_tags_owner_name', true);
}

// hc_homewiki_page_tags
if (!$schema->hasTable($prefix . 'hc_homewiki_page_tags')) $changed = true;
{
$table = $schema->hasTable($prefix . 'hc_homewiki_page_tags') ? $schema->getTable($prefix . 'hc_homewiki_page_tags') : $schema->createTable($prefix . 'hc_homewiki_page_tags');
            $addColumn($table, 'id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
            $addColumn($table, 'page_id', 'string', ['length' => 36, 'notnull' => true]);
            $addColumn($table, 'tag_id', 'string', ['length' => 36, 'notnull' => true]);
            $addColumn($table, 'created_at', 'bigint', ['notnull' => true]);
            $setPrimaryKey($table, ['id']);
            $addIndex($table, ['page_id'], 'hc_hw_page_tags_page');
            $addIndex($table, ['tag_id'], 'hc_hw_page_tags_tag');
            $addIndex($table, ['page_id', 'tag_id'], 'hc_hw_page_tags_unique', true);
}

$required = [];
foreach (['hc_homewiki_pages', 'hc_homewiki_acl', 'hc_homewiki_aliases', 'hc_homewiki_links', 'hc_homewiki_trash', 'hc_homewiki_tags', 'hc_homewiki_page_tags'] as $logical) {
    $table = $schema->getTable($prefix . $logical);
    $required[$logical] = ['columns' => array_keys($table->getColumns()), 'indexes' => array_diff(array_keys($table->getIndexes()), ['primary'])];
}

if ($changed) {
    $db->migrateToSchema($schema);
}
// Verify from a fresh snapshot; metadata in $schema alone is insufficient.
$verified = $db->createSchema();
foreach ($required as $logical => $parts) {
    $physical = $prefix . $logical;
    if (!$verified->hasTable($physical)) throw new \RuntimeException('Missing table: ' . $physical);
    $table = $verified->getTable($physical);
    foreach ($parts['columns'] as $column) {
        if (!$table->hasColumn($column)) throw new \RuntimeException('Missing column: ' . $physical . '.' . $column);
    }
    foreach ($parts['indexes'] as $index) {
        if (!$table->hasIndex($index)) throw new \RuntimeException('Missing index: ' . $physical . '.' . $index);
    }
}
echo "Database schema: OK (" . count($required) . " tables)\n";
