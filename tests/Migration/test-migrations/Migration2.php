<?php declare(strict_types=1);

use Starlit\Db\Migration\AbstractMigration;

class Migration2 extends AbstractMigration
{
    public function up(): void
    {
        $this->db->exec('SOME SQL');
    }

    public function down(): void
    {
        $this->db->exec('SOME SQL');
    }
}
