<?php

namespace App;

use Amp\Deferred;
use Amp\Parallel\Sync\Lock;
use Amp\Parallel\Sync\Semaphore;
use Amp\Promise;

class SimpleSemaphore implements Semaphore
{
    private $queue = [];
    private $locks = 0;
    private $maxConcurrent;

    public function __construct(int $maxConcurrent = 1)
    {
        $this->maxConcurrent = $maxConcurrent;
    }

    /**
     * {@inheritdoc}
     */
    public function acquire(): Promise
    {
        $deferred = new Deferred;
        if ($this->locks < $this->maxConcurrent) {
            $deferred->resolve($this->createLock());
        } else {
            $this->queue[] = $deferred;
        }
        return $deferred->promise();
    }

    private function createLock()
    {
        $this->locks += 1;

        return new Lock(function () {
            $this->locks -= 1;

            if ($this->queue) {
                $deferred = array_shift($this->queue);
                $deferred->resolve($this->createLock());
            }
        });
    }

    /**
     * {@inheritdoc}
     */
    public function count(): int
    {
        return $this->maxConcurrent - $this->locks;
    }

    /**
     * {@inheritdoc}
     */
    public function getSize(): int
    {
        return $this->maxConcurrent;
    }
}
