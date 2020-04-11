<?php

namespace Illuminate\Events;

use Illuminate\Collections\Collection;
use Illuminate\Contracts\Events\TransactionalEvent;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use loophp\phptree\Node\ValueNode;
use loophp\phptree\Node\ValueNodeInterface;

trait HandlesTransactions
{
    /**
     * The current prepared transaction.
     *
     * @var \loophp\phptree\Node\ValueNodeInterface
     */
    protected $currentTransaction;

    /**
     * All pending events in order.
     *
     * @var array
     */
    protected $events = [];

    /**
     * Next position for event storing.
     *
     * @var int
     */
    protected $nextEventIndex = 0;

    /**
     * Setup listeners for transaction events.
     *
     * @return void
     */
    protected function setupDatabaseListeners(): void
    {
        $this->listen(TransactionBeginning::class, function () {
            $this->onTransactionBegin();
        });

        $this->listen(TransactionCommitted::class, function () {
            $this->onTransactionCommit();
        });

        $this->listen(TransactionRolledBack::class, function () {
            $this->onTransactionRollback();
        });
    }

    /**
     * Prepare a new transaction.
     *
     * @return void
     */
    protected function onTransactionBegin(): void
    {
        $transactionNode = new ValueNode(new Collection());

        $this->currentTransaction = $this->isTransactionRunning()
            ? $this->currentTransaction->add($transactionNode)
            : $transactionNode;

        $this->currentTransaction = $transactionNode;
    }

    /**
     * Add a pending transactional event to the current transaction.
     *
     * @param  string|object $event
     * @param  mixed $payload
     * @return void
     */
    protected function addPendingEvent($event, $payload): void
    {
        $eventData = [
            'event' => $event,
            'payload' => is_object($payload) ? clone $payload : $payload,
        ];

        $this->currentTransaction->getValue()->push($eventData);
        $this->events[$this->nextEventIndex++] = $eventData;
    }

    /**
     * Handle transaction commit.
     *
     * @return void
     */
    protected function onTransactionCommit(): void
    {
        if (! $this->isTransactionRunning()) {
            return;
        }

        $committedTransaction = $this->finishTransaction();

        if (! $committedTransaction->isRoot()) {
            return;
        }

        $this->dispatchPendingEvents();
    }

    /**
     * Clear enqueued events for the rollbacked transaction.
     *
     * @return void
     */
    protected function onTransactionRollback(): void
    {
        if (! $this->isTransactionRunning()) {
            return;
        }

        $rolledBackTransaction = $this->finishTransaction();

        if ($rolledBackTransaction->isRoot()) {
            $this->resetEvents();

            return;
        }

        $this->nextEventIndex -= $rolledBackTransaction->getValue()->count();
    }

    /**
     * Check whether there is at least one transaction running.
     *
     * @return bool
     */
    protected function isTransactionRunning(): bool
    {
        if ($this->currentTransaction) {
            return true;
        }

        return false;
    }

    /**
     * Flush all pending events.
     *
     * @return void
     */
    protected function dispatchPendingEvents(): void
    {
        $events = $this->events;
        $eventsCount = $this->nextEventIndex;
        $this->resetEvents();

        for ($i = 0; $i < $eventsCount; $i++) {
            $event = $events[$i];
            $this->dispatchEvent($event['event'], $event['payload']);
        }
    }

    /**
     * Check whether an event is a transactional event or not.
     *
     * @param  string|object $event
     * @return bool
     */
    protected function isTransactionalEvent($event): bool
    {
        if (! $this->isTransactionRunning()) {
            return false;
        }

        return $this->shouldHandleTransaction($event);
    }

    /**
     * Finish current transaction.
     *
     * @return \loophp\phptree\Node\ValueNodeInterface
     */
    protected function finishTransaction(): ValueNodeInterface
    {
        $finished = $this->currentTransaction;
        $this->currentTransaction = $finished->getParent();

        return $finished;
    }

    /**
     * Reset events list.
     *
     * @return void
     */
    protected function resetEvents(): void
    {
        $this->events = [];
        $this->nextEventIndex = 0;
    }

    /**
     * Check whether an event should be handled by this layer or not.
     *
     * @param  string|object  $event
     * @return bool
     */
    protected function shouldHandleTransaction($event): bool
    {
        return $event instanceof TransactionalEvent;
    }
}
