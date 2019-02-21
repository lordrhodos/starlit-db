<?php declare(strict_types=1);

namespace Starlit\Db\Exception;

use PHPUnit\Framework\TestCase;

class QueryExceptionTest extends TestCase
{
    /**
     * @var QueryException
     */
    private $exception;

    private $sql = 'SELECT * FROM `table` WHERE `column_1` = ? AND `column_2` = ?';
    private $parameters = ['a', 'b'];

    public function setUp(): void
    {
        $mockPdoException = $this->createMock(\PDOException::class);

        $this->exception = new QueryException($mockPdoException, $this->sql, $this->parameters);
    }

    public function testGetMessage(): void
    {
        $message = $this->exception->getMessage();

        $this->assertContains($this->sql, $message);
        $this->assertContains($this->parameters[0], $message);
        $this->assertContains($this->parameters[1], $message);
    }

    public function testGetSql(): void
    {
        $this->assertEquals($this->sql, $this->exception->getSql());
    }

    public function testGetDbParameters(): void
    {
        $this->assertEquals($this->parameters, $this->exception->getDbParameters());
    }
}
