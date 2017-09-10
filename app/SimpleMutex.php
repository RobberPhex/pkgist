<?php

namespace App;

use Amp\Deferred;
use Amp\Parallel\Sync\Lock;
use Amp\Parallel\Sync\Mutex;
use Amp\Promise;

class SimpleMutex implements Mutex
{
    private $queue = [];
    private $locked = false;

    /**
     * {@inheritdoc}
     */
    public function acquire(): Promise
    {
        $deferred = new Deferred;
        if ($this->locked) {
            $this->queue[] = $deferred;
        } else {
            $deferred->resolve($this->createLock());
        }
        return $deferred->promise();
    }

    private function createLock()
    {
        $this->locked = true;

        return new Lock(function () {
            $this->locked = false;

            if ($this->queue) {
                $deferred = array_shift($this->queue);
                $deferred->resolve($this->createLock());
            }
        });
    }
}
