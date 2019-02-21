<?php declare(strict_types=1);

namespace Starlit\Db\Migration;

use PHPUnit\Framework\TestCase;
use Starlit\Db\Db;

class AbstractMigrationTest extends TestCase
{
    /**
     * @var TestMigration15
     */
    protected $migration;

    /**
     * @var \PHPUnit_Framework_MockObject_MockObject
     */
    protected $mockDb;

    public function setUp(): void
    {
        $this->mockDb = $this->createMock(Db::class);
        $this->migration = new TestMigration15($this->mockDb);
    }

    public function testGetNumber(): void
    {
        $this->assertEquals(15, $this->migration->getNumber());
    }

    public function testGetNumberException(): void
    {
        $migration = new TestInvalidMigration($this->mockDb);

        $this->expectException(\LogicException::class);
        $migration->getNumber();
    }

    public function testUp(): void
    {
        $this->mockDb->expects($this->once())->method('exec');
        $this->migration->up();
    }

    public function testDown(): void
    {
        $this->mockDb->expects($this->once())->method('exec');
        $this->migration->down();
    }

    public function testDownDefault(): void
    {
        $this->mockDb->expects($this->never())->method('exec');

        $this->migration = new TestMigration16WithDefaultDown($this->mockDb);
        $this->migration->down();
    }
}

class TestMigration15 extends AbstractMigration
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

class TestMigration16WithDefaultDown extends AbstractMigration
{
    public function up(): void
    {
        $this->db->exec('SOME SQL');
    }
}

class TestInvalidMigration extends AbstractMigration
{
    public function up(): void
    {
    }
}
