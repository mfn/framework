<?php

namespace Illuminate\Contracts\Events;

/**
 * Any Event implementing this (marker) interface will respect
 * the currently ongoing database transactions and will:
 *
 * - purge the events in case of a rollback
 * - dispatch them once the transaction is commited
 */
interface TransactionalEvent
{
}
