<?php
declare(strict_types=1);

namespace estuaries_modules\ContactForm\Tests;

use PHPUnit\Framework\TestCase;

/**
 * 1.0.1/1.0.3: cf_submissions gains spam_reason only when it has none, asked of
 * information_schema. ADD COLUMN IF NOT EXISTS is MariaDB's alone; MySQL
 * refused it on kevinbruce.com (2026-10-23). Moved from the framework's
 * PortableMigrationSqlTest when ContactForm left the release.
 */
final class SpamReasonMigrationTest extends TestCase
{
    private const MARIADB_ONLY = '/\b(?:ADD|DROP|MODIFY|CHANGE)\s+(?:COLUMN\s+)?IF\s+(?:NOT\s+)?EXISTS\b'
        . '|\b(?:ADD|DROP)\s+(?:UNIQUE\s+)?(?:INDEX|KEY)\s+IF\s+(?:NOT\s+)?EXISTS\b'
        . '|\bCREATE\s+(?:UNIQUE\s+)?INDEX\s+IF\s+NOT\s+EXISTS\b/i';

    public function testContactFormAddsSpamReasonOnlyWhenMissing(): void
    {
        require_once dirname(__DIR__) . '/updates.php';

        foreach ([true => 0, false => 1] as $exists => $alters) {
            $db = new class((bool) $exists) {
                public array $statements = [];
                public function __construct(private bool $exists) {}
                public function column_exists($table, $column): bool { return $this->exists; }
                public function update($sql): bool { $this->statements[] = $sql; return true; }
            };
            $module = (object) ['db' => $db];

            contactform_add_spam_reason($module);

            $this->assertCount($alters, $db->statements);
            if ($alters) {
                $this->assertStringContainsString('ADD COLUMN spam_reason', $db->statements[0]);
                $this->assertDoesNotMatchRegularExpression(self::MARIADB_ONLY, $db->statements[0]);
            }
        }
    }
}
