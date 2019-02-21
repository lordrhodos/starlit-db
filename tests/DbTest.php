<?php declare(strict_types=1);

namespace Starlit\Db;

use PHPUnit\Framework\TestCase;

use DateTime;
use PDO;
use PDOException;
use PDOStatement;
use Starlit\Db\Exception\ConnectionException;

class DbTest extends TestCase
{
    /**
     * @var Db
     */
    private $db;

    /**
     * @var \PHPUnit_Framework_MockObject_MockObject
     */
    private $mockPdo;

    /**
     * @var \PHPUnit_Framework_MockObject_MockObject
     */
    private $mockPdoFactory;

    protected function setUp()
    {
        $this->mockPdo = $this->createMock(PDO::class);
        $this->mockPdoFactory = $this->createMock(PdoFactory::class);
        $this->db = new Db($this->mockPdo, null, null, 'database', [], $this->mockPdoFactory);
    }

    public function testCreationWithPdoTriggersDeprecationError()
    {
        $expectedErrorStack = [
            [
                'message'   => 'Support to pass a PDO instance to the constructor is deprecated and '
                                . 'will be removed in version 1.0.0.'
                                . ' You need to pass in a PDO dsn in the future as first parameter.',
                'type'      => E_USER_DEPRECATED
            ]
        ];
        $this->assertEquals($expectedErrorStack, ErrorStackTestHelper::$errors);
    }

    public function testCreationWithHostAndDatabaseTriggersDeprecationError()
    {
        ErrorStackTestHelper::$errors = [];
        $this->getMockBuilder(Db::class)
            ->setConstructorArgs(['localhost', null, null, 'database', []])
            ->getMock();

        $expectedErrorStack = [
            [
                'message'   => 'Support to pass a host and database to the constructor is deprecated and will be '
                                . 'removed in version 1.0.0. '
                                . 'You need to pass in a PDO dsn in the future as first parameter.',
                'type'      => E_USER_DEPRECATED
            ]
        ];
        $this->assertEquals($expectedErrorStack, ErrorStackTestHelper::$errors);
    }

    public function testConnectFromDsnCreationCallsFactory()
    {
        $this->mockPdoFactory
            ->expects($this->once())
            ->method('createPdo')
            ->willReturn($this->mockPdo);

        $db = new Db('sqlite::memory', null, null, 'database', [], $this->mockPdoFactory);

        $this->assertFalse($db->isConnected());
        $this->assertNull($db->getPdo());
        $db->connect();
        $this->assertTrue($db->isConnected());
        $this->assertInstanceOf(PDO::class, $db->getPdo());
    }

    public function testRetryConnectOnPdoCreationFailureThrowsConnectionException()
    {
        $this->mockPdoFactory
            ->expects($this->exactly(2))
            ->method('createPdo')
            ->willThrowException(new PDOException('Can not connect to mysql server', 2002));

        $db = new Db(
            'mysql:hostname=localhost',
            null,
            null,
            'database',
            ['connectRetries' => 1],
            $this->mockPdoFactory
        );

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('Can not connect to mysql server');
        $db->connect();
    }

    public function testRetryConnectWithFailureOnFirstPdoCreation()
    {
        $this->mockPdoFactory
            ->expects($this->exactly(2))
            ->method('createPdo')
            ->will(
                $this->onConsecutiveCalls(
                    $this->throwException(new PDOException('Can not connect to mysql server', 2002)),
                    $this->mockPdo
                )
            );

        $db = new Db(
            'mysql:hostname=localhost',
            null,
            null,
            'database',
            ['connectRetries' => 1],
            $this->mockPdoFactory
        );

        $this->assertFalse($db->isConnected());
        $this->assertNull($db->getPdo());
        $db->connect();
        $this->assertTrue($db->isConnected());
        $this->assertInstanceOf(PDO::class, $db->getPdo());
    }

    public function testDisconnectClearsPdo(): void
    {
        $this->db->disconnect();
        $this->assertEmpty($this->db->getPdo());
    }

    public function testReconnect(): void
    {
        $mockDb = $this->createPartialMock(Db::class, ['disconnect', 'connect']);
        $mockDb->expects($this->once())->method('disconnect');
        $mockDb->expects($this->once())->method('connect');

        $mockDb->reconnect();
    }

    public function testIsConnectedReturnsTrue(): void
    {
        $this->assertTrue($this->db->isConnected());
    }

    public function testGetPdoIsPdoInstance(): void
    {
        $this->assertInstanceOf(PDO::class, $this->db->getPdo());
    }

    public function testExecCallsPdoWithSqlAndParams(): void
    {
        $sql = 'UPDATE `test_table` SET `test_column` = ? AND `other_column` = ?';
        $sqlParameters = [1, 2.3, true, false, 'abc', null];
        $rowCount = 5;

        $mockPdoStatement = $this->createMock(PDOStatement::class);

        $this->mockPdo->expects($this->once())
            ->method('prepare')
            ->with($sql)
            ->willReturn($mockPdoStatement);

        $mockPdoStatement->expects($this->once())
            ->method('execute')
            ->with([1, 2.3, 1, 0, 'abc', null]);

        $mockPdoStatement->expects($this->once())
            ->method('rowCount')
            ->willReturn($rowCount);

        $result = $this->db->exec($sql, $sqlParameters);
        $this->assertEquals($rowCount, $result);
    }

    public function testPrepareParameters(): void
    {
        $dateTime = new \DateTime('2000-01-01 00:00:00');
        $parameters = [$dateTime, 12, 'foo'];

        $preparedParameters = $this->invokeInternalMethod($this->db, 'prepareParameters', $parameters);

        $this->assertSame('2000-01-01 00:00:00', $preparedParameters[0]);
        $this->assertSame(12, $preparedParameters[1]);
        $this->assertSame('foo', $preparedParameters[2]);
    }

    public function testPrepareParametersWithInvalidParametersShouldThrowException(): void
    {
        $parameters = [new \stdClass()];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid db parameter type "object"');
        $this->invokeInternalMethod($this->db, 'prepareParameters', $parameters);
    }

    public function testExecFailThrowsQueryException(): void
    {
        $this->mockPdo
            ->method('prepare')
            ->willThrowException(new PDOException());

        $this->expectException(\Starlit\Db\Exception\QueryException::class);
        $this->db->exec('NO SQL');
    }

    public function testExecThrowsExceptionWithInvalidParameterTypes(): void
    {
        $mockPdoStatement = $this->createMock(PDOStatement::class);
        $this->mockPdo->method('prepare')->willReturn($mockPdoStatement);

        $this->expectException(\InvalidArgumentException::class);

        $sqlParameters = [3, ['a', 'b', 'c']];
        $this->db->exec('', $sqlParameters);
    }

    public function testFetchRowCallsPdoWithSqlAndParams(): void
    {
        $sql = 'SELECT * FROM `test_table` WHERE id = ? LIMIT 1';
        $sqlParameters = [1];
        $tableData = ['id' => 5];

        $mockPdoStatement = $this->createMock(PDOStatement::class);
        $this->mockPdo->expects($this->once())
            ->method('prepare')
            ->with($sql)
            ->willReturn($mockPdoStatement);

        $mockPdoStatement->expects($this->once())
            ->method('execute')
            ->with($sqlParameters);

        $mockPdoStatement->expects($this->once())
            ->method('fetch')
            ->willReturn($tableData);

         $this->assertEquals($tableData, $this->db->fetchRow($sql, $sqlParameters));
    }

    public function testFetchRowsCallsPdoWithSqlAndParams(): void
    {
        $sql = 'SELECT * FROM `test_table` WHERE id < ?';
        $sqlParameters = [3];
        $tableData = [['id' => 1], ['id' => 2]];

        $mockPdoStatement = $this->createMock(PDOStatement::class);
        $this->mockPdo->expects($this->once())
            ->method('prepare')
            ->with($sql)
            ->willReturn($mockPdoStatement);

        $mockPdoStatement->expects($this->once())
            ->method('execute')
            ->with($sqlParameters);

        $mockPdoStatement->expects($this->once())
            ->method('fetchAll')
            ->willReturn($tableData);

        $this->assertEquals($tableData, $this->db->fetchRows($sql, $sqlParameters));
    }

    public function testFetchOneCallsPdoWithSqlAndParams(): void
    {
        $sql = 'SELECT COUNT(*) FROM `test_table` WHERE id < ?';
        $sqlParameters = [10];
        $result = 5;

        $mockPdoStatement = $this->createMock(PDOStatement::class);
        $this->mockPdo->expects($this->once())
            ->method('prepare')
            ->with($sql)
            ->willReturn($mockPdoStatement);

        $mockPdoStatement->expects($this->once())
            ->method('execute')
            ->with($sqlParameters);

        $mockPdoStatement->expects($this->once())
            ->method('fetchColumn')
            ->willReturn($result);

        $this->assertEquals($result, $this->db->fetchValue($sql, $sqlParameters));
    }

    public function testQuoteCallPdo(): void
    {
        $this->mockPdo->expects($this->once())
            ->method('quote');

        $this->db->quote(1);
    }

    public function testGetLastInsertIdCallsPdo(): void
    {
        $this->mockPdo->expects($this->once())
            ->method('lastInsertId');

        $this->db->getLastInsertId();
    }

    public function testBeginTransactionCallsPdoAndReturnsTrue(): void
    {
        $this->mockPdo->expects($this->once())
            ->method('beginTransaction');

        $this->assertTrue($this->db->beginTransaction());
    }

    public function testBeginTransactionReturnsFalse(): void
    {
        $this->db->beginTransaction();

        $this->assertFalse($this->db->beginTransaction(true));
    }

    public function testCommitCallsPdo(): void
    {
        $this->mockPdo->expects($this->once())
            ->method('commit');

        $this->db->commit();
    }

    public function testRollBackCallsPdo(): void
    {
        $this->mockPdo->expects($this->once())
            ->method('rollBack');

        $this->db->rollBack();
    }

    public function testHasActiveTransactionReturnsTrue(): void
    {
        $this->db->beginTransaction();

        $this->assertTrue($this->db->hasActiveTransaction());
    }

    public function testHasActiveTransactionReturnsFalse(): void
    {
        $this->assertFalse($this->db->hasActiveTransaction());

        $this->db->beginTransaction();
        $this->db->commit();
        $this->assertFalse($this->db->hasActiveTransaction());

        $this->db->beginTransaction();
        $this->db->rollBack();
        $this->assertFalse($this->db->hasActiveTransaction());
    }

    public function testInsertCallsExecWithSqlAndParams(): void
    {
        $table = 'test_table';
        $insertData = ['id' => 1, 'name' => 'one'];
        $expectedSql = "INSERT INTO `" . $table . "` (`id`, `name`)\nVALUES (?, ?)\n";
        $expectedAffectedRows = 1;

        $mockDb = $this->createPartialMock(Db::class, ['exec']);
        $mockDb->expects($this->once())
            ->method('exec')
            ->with($expectedSql, array_values($insertData))
            ->willReturn($expectedAffectedRows);


        $this->assertEquals($expectedAffectedRows, $mockDb->insert($table, $insertData));
    }

    public function testInsertWithoutDataCallsExecWithSql(): void
    {
        $table = 'test_table';
        $insertData = [];
        $expectedSql = "INSERT INTO `" . $table . "`\nVALUES ()";

        $mockDb = $this->createPartialMock(Db::class, ['exec']);
        $mockDb->expects($this->once())
            ->method('exec')
            ->with($expectedSql, array_values($insertData));

        $mockDb->insert($table, $insertData);
    }

    public function testInsertWithUpdateOnDuplicateDataCallsExecWithSql(): void
    {
        $table = 'test_table';
        $insertData = ['id' => 1, 'name' => 'one'];
        $expectedSql = "INSERT INTO `" . $table . "` (`id`, `name`)\nVALUES (?, ?)\n"
            . 'ON DUPLICATE KEY UPDATE `id` = ?, `name` = ?';
        $expectedParameters = array_merge(array_values($insertData), array_values($insertData));

        $mockDb = $this->createPartialMock(Db::class, ['exec']);
        $mockDb->expects($this->once())
            ->method('exec')
            ->with($expectedSql, $expectedParameters);

        $mockDb->insert($table, $insertData, true);
    }

    public function testUpdateCallsExecWithSqlAndParams(): void
    {
        $table = 'test_table';
        $updateData = ['name' => 'ONE'];
        $whereSql = '`name` = ?';
        $whereParameters = [1];

        $expectedSql = "UPDATE `" . $table . "`\nSET `name` = ?\nWHERE `name` = ?";
        $expectedAffectedRows = 1;

        $mockDb = $this->createPartialMock(Db::class, ['exec']);
        $mockDb->expects($this->once())
            ->method('exec')
            ->with($expectedSql, array_merge(array_values($updateData), $whereParameters))
            ->willReturn($expectedAffectedRows);


        $this->assertEquals(
            $expectedAffectedRows,
            $mockDb->update($table, $updateData, $whereSql, $whereParameters)
        );

    }

    /**
     * @param array $parameters
     *
     * @return mixed
     * @throws \ReflectionException
     */
    private function invokeInternalMethod(Db $db, string $method, array $parameters)
    {
        $dbReflection = new \ReflectionClass($db);
        $prepareParametersMethod = $dbReflection->getMethod($method);
        $prepareParametersMethod->setAccessible(true);
        $preparedParameters = $prepareParametersMethod->invoke($db, $parameters);

        return $preparedParameters;
    }
}
