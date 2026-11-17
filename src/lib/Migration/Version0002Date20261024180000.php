<?php

declare(strict_types=1);

namespace OCA\HcHomeWiki\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Add persistent subtree-sharing policy without altering existing pages. */
final class Version0002Date20261024180000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();
        if ($schema->hasTable('hc_homewiki_pages')) {
            $pages = $schema->getTable('hc_homewiki_pages');
            if (!$pages->hasColumn('inherit_sharing')) {
                $pages->addColumn('inherit_sharing', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
                return $schema;
            }
        }
        return null;
    }
}
