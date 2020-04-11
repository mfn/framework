<?php

namespace Illuminate\Tests\Events;

use Closure;
use Exception;
use Illuminate\Contracts\Events\TransactionalEvent;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Connection;
use Illuminate\Events\Dispatcher;
use Mockery as m;
use PDO;
use PHPUnit\Framework\TestCase;

class TransactionalDispatcherTest extends TestCase
{
    /** @var \Illuminate\Events\Dispatcher */
    protected $dispatcher;

    public function setUp(): void
    {
        parent::setUp();

        unset($_SERVER['__events.test']);

        $this->dispatcher = new Dispatcher;
    }

    protected function tearDown(): void
    {
        m::close();
    }

    public function testImmediatelyDispatchesEventOutOfTransactions()
    {
        $this->dispatcher->listen('foo', function () {
            $_SERVER['__events.test'] = 'bar';
        });

        $this->dispatcher->dispatch('foo');

        $this->assertSame('bar', $_SERVER['__events.test']);
    }

    public function testImmediatelyDispatchesNonTransactionalEvent()
    {
        $this->dispatcher->listen('foo', function () {
            $_SERVER['__events.test'] = 'bar';
        });

        [$connection, $pdoMock] = $this->getConnectionAndPdoMock();
        $pdoMock->shouldReceive('beginTransaction', 'commit')->once();

        $connection->transaction(function () {
            $this->dispatcher->dispatch('foo');
            $this->assertSame('bar', $_SERVER['__events.test']);
        });

        $this->assertSame('bar', $_SERVER['__events.test']);
    }

    public function testDispatchesEventsOnlyAfterTransactionCommits()
    {
        $this->dispatcher->listen(CustomEvent1::class, function () {
            $_SERVER['__events.test'] = 'bar';
        });

        [$connection, $pdoMock] = $this->getConnectionAndPdoMock();
        $pdoMock->shouldReceive('beginTransaction', 'commit')->once();

        $connection->transaction(function () {
            $this->dispatcher->dispatch(new CustomEvent1());
            $this->assertArrayNotHasKey('__events.test', $_SERVER);
        });

        $this->assertSame('bar', $_SERVER['__events.test']);
    }

    public function testForgetDispatchedEventsAfterTransactionCommits()
    {
        $this->dispatcher->listen(CustomEvent1::class, function () {
            $_SERVER['__events.test'] = 'bar';
        });
        $this->dispatcher->listen(CustomEvent2::class, function () {
            $_SERVER['__events.test'] = 'zen';
        });

        [$connection, $pdoMock] = $this->getConnectionAndPdoMock();
        $pdoMock->shouldReceive('beginTransaction', 'commit')->twice();

        $connection->transaction(function () {
            $this->dispatcher->dispatch(new CustomEvent1());
            $this->dispatcher->dispatch(new CustomEvent2());
        });

        $connection->transaction(function () {
            unset($_SERVER['__events.test']);
        });

        $this->assertArrayNotHasKey('__events.test', $_SERVER);
    }

    public function testDoNotForgetDispatchedEventsOnSameTransactionLevelAfterRollback()
    {
        $this->dispatcher->listen(CustomEvent1::class, function ($event) {
            $_SERVER['__events.test'] = $_SERVER['__events.test'] ?? [];
            $_SERVER['__events.test'][] = $event->payload;
        });

        [$connection, $pdoMock] = $this->getConnectionAndPdoMock();
        $pdoMock->shouldReceive('beginTransaction', 'commit')->once();
        $pdoMock->shouldReceive('exec')->times(3); // savepoints

        $connection->transaction(function () use ($connection) {
            $this->dispatcher->dispatch(new CustomEvent1('first'));

            $connection->transaction(function () {
                $this->dispatcher->dispatch(new CustomEvent1('second'));
            });

            try {
                $connection->transaction(function () {
                    $this->dispatcher->dispatch(new CustomEvent1('third'));
                    throw new Exception;
                });
            } catch (Exception $e) {
                //
            }
            $this->dispatcher->dispatch(new CustomEvent1('fourth'));
        });

        $this->assertCount(3, $_SERVER['__events.test']);
        $this->assertSame('first', $_SERVER['__events.test'][0]);
        $this->assertSame('second', $_SERVER['__events.test'][1]);
        $this->assertSame('fourth', $_SERVER['__events.test'][2]);
    }

    public function testDoNotDispatchEventsAfterNestedTransactionCommits()
    {
        $this->dispatcher->listen(CustomEvent1::class, function () {
            $_SERVER['__events.test'] = 'bar';
        });

        [$connection, $pdoMock] = $this->getConnectionAndPdoMock();
        $pdoMock->shouldReceive('beginTransaction', 'commit', 'exec')->once();

        $connection->transaction(function () use ($connection) {
            $connection->transaction(function () use ($connection) {
                $this->dispatcher->dispatch(new CustomEvent1());
            });
            $this->assertArrayNotHasKey('__events.test', $_SERVER);
        });

        $this->assertSame('bar', $_SERVER['__events.test']);
    }

    public function testDoNotDispatchEventsAfterNestedTransactionRollbacks()
    {
        $this->dispatcher->listen(CustomEvent1::class, function () {
            $_SERVER['__events.test'] = 'bar';
        });

        [$connection, $pdoMock] = $this->getConnectionAndPdoMock();
        $pdoMock->shouldReceive('beginTransaction')->once();
        $pdoMock->shouldReceive('exec')->twice();

        try {
            $connection->transaction(function () use ($connection) {
                $connection->transaction(function () {
                    $this->dispatcher->dispatch(new CustomEvent1());
                    throw new Exception;
                });
            });
        } catch (Exception $e) {
            //
        }

        $this->assertArrayNotHasKey('__events.test', $_SERVER);
    }

    public function testDoNotDispatchEventsAfterOuterTransactionRollback()
    {
        $this->dispatcher->listen(CustomEvent1::class, function () {
            $_SERVER['__events.test'] = 'bar';
        });

        [$connection, $pdoMock] = $this->getConnectionAndPdoMock();
        $pdoMock->shouldReceive('beginTransaction', 'exec')->once();

        try {
            $connection->transaction(function () use ($connection) {
                $connection->transaction(function () {
                    $this->dispatcher->dispatch(new CustomEvent1());
                });
                throw new Exception;
            });
        } catch (Exception $e) {
            //
        }

        $this->assertArrayNotHasKey('__events.test', $_SERVER);
    }


    /**
     * Regression test: Fix infinite loop caused by TransactionCommitted (#12).
     */
    public function testNestedTransactionsOnDispatchDoesNotCauseInfiniteLoop()
    {
        [$connection, $pdoMock] = $this->getConnectionAndPdoMock();
        $pdoMock->shouldReceive('beginTransaction', 'commit')->twice();

        $count = 0;
        $this->dispatcher->listen(CustomEvent1::class, function () use ($connection, &$count) {
            $connection->transaction(function () use (&$count) {
                if ($count > 1) {
                    $this->fail('Infinite loop while dispatching events.');
                }

                $count++;
            });
        });

        $connection->transaction(function () {
            $this->dispatcher->dispatch(new CustomEvent1());
        });

        $this->assertSame(1, $count);
    }

    public function testWithNonDefaultConnections()
    {
        $this->dispatcher->listen(CustomEvent1::class, function ($event) {
            $_SERVER['__events.test'] = $_SERVER['__events.test'] ?? [];
            $_SERVER['__events.test'][] = $event->payload;
        });

        $manager = new Manager;

        $manager->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);

        $manager->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ], 'other');

        $db = $manager->getDatabaseManager();
        $db->setEventDispatcher($this->dispatcher);

        $connection = $db->connection();

        $connection->transaction(function () use ($db) {
            $this->dispatcher->dispatch(new CustomEvent1('first'));

            $db->connection('other')->transaction(function () {
                $this->dispatcher->dispatch(new CustomEvent1('second'));
            });
        });

        $this->assertCount(2, $_SERVER['__events.test']);
        $this->assertSame('first', $_SERVER['__events.test'][0]);
        $this->assertSame('second', $_SERVER['__events.test'][1]);
    }

    /**
     * This reproduces the use of DatabaseTransactions and RefreshDatabase traits.
     */
    public function testIgnoreCommitsOrRollbacksWhenTransactionsNotRunning()
    {
        [$connection, $pdoMock] = $this->getConnectionAndPdoMock();
        $pdoMock->shouldReceive('beginTransaction', 'rollback')->once();

        $this->withoutTransactionEvents($connection, function () use ($connection) {
            $connection->beginTransaction();
        });

        $this->dispatcher->listen(CustomEvent1::class, function () {
            throw new Exception();
        });

        try {
            $connection->beginTransaction();
            $this->dispatcher->dispatch(new CustomEvent1());
            $connection->commit();
        } catch (Exception $e) {
            $connection->rollBack();
        }

        $this->withoutTransactionEvents($connection, function () use ($connection) {
            $connection->rollBack();
        });

        $this->assertTrue(true);
    }

    protected function withoutTransactionEvents(Connection $connection, Closure $callback)
    {
        $connection->unsetEventDispatcher();

        try {
            $callback();
        } finally {
            $connection->setEventDispatcher($this->dispatcher);
        }
    }

    protected function getConnectionAndPdoMock(): array
    {
        $pdoMock = m::mock(PDO::class);

        $connection = new Connection($pdoMock);
        $connection->setEventDispatcher($this->dispatcher);

        return [$connection, $pdoMock];
    }
}

class CustomEvent1 implements TransactionalEvent
{
    /** @var string|null */
    public $payload;

    /**
     * @param string|null $payload
     */
    public function __construct($payload = null) {
        $this->payload = $payload;
    }
}

class CustomEvent2 implements TransactionalEvent
{
    //
}
