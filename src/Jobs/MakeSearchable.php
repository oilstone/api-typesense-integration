<?php

namespace Oilstone\ApiTypesenseIntegration\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Collection;

class MakeSearchable implements ShouldQueue
{
    use Queueable;

    /**
     * @var Collection
     */
    public Collection $models;

    /**
     * @param string $type
     * @param mixed $models
     * @return void
     */
    public function __construct(Collection $models)
    {
        $this->models = $models;
    }

    /**
     * Handle the job.
     *
     * @return void
     */
    public function handle()
    {
        if ($this->models->isEmpty()) {
            return;
        }

        $this->models->first()->searchableUsing()->update($this->models);
    }
}
