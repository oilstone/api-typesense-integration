<?php

namespace Oilstone\ApiTypesenseIntegration;

use Illuminate\Pagination\LengthAwarePaginator;

class Paginator extends LengthAwarePaginator
{
    /**
     * The total number of items before filtering.
     *
     * @var int
     */
    protected $outOf;

    /**
     * Create a new paginator instance.
     *
     * @param  mixed  $items
     * @param  int  $total
     * @param  int  $outOf
     * @param  int  $perPage
     * @param  int|null  $currentPage
     * @param  array  $options  (path, query, fragment, pageName)
     * @return void
     */
    public function __construct($items, $total, $outOf, $perPage, $currentPage = null, array $options = [])
    {
        $this->outOf = $outOf;

        parent::__construct($items, $total, $perPage, $currentPage, $options);
    }

    /**
     * Get the instance as an array.
     *
     * @return array
     */
    public function toArray()
    {
        return [
            'current_page' => $this->currentPage(),
            'data' => $this->items->toArray(),
            'from' => $this->firstItem(),
            'out_of' => $this->outOf(),
            'per_page' => $this->perPage(),
            'to' => $this->lastItem(),
            'total' => $this->total(),
        ];
    }

    /**
     * Get the total number of items before being filtered or paginated.
     *
     * @return int
     */
    public function outOf()
    {
        return $this->outOf;
    }
}
