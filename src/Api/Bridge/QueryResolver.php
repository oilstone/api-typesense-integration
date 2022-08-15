<?php

namespace Oilstone\ApiTypesenseIntegration\Api\Bridge;

use Aggregate\Set;
use Api\Pipeline\Pipes\Pipe;
use Api\Result\Contracts\Record;
use Api\Schema\Schema;
use Oilstone\ApiTypesenseIntegration\Api\ResultSet;
use Oilstone\ApiTypesenseIntegration\Query as QueryBuilder;
use Psr\Http\Message\ServerRequestInterface;

class QueryResolver
{
    protected static int $hardLimit = 250;

    /**
     * @var string
     */
    protected string $contentType;

    /**
     * @var Schema
     */
    protected Schema $schema;

    /**
     * @var Pipe
     */
    protected Pipe $pipe;

    /**
     * @param string $contentType
     * @param Schema $schema
     * @param Pipe $pipe
     * @return void
     */
    public function __construct(string $contentType, Schema $schema, Pipe $pipe)
    {
        $this->contentType = $contentType;
        $this->schema = $schema;
        $this->pipe = $pipe;
    }

    /**
     * @return null|Record
     */
    public function byKey(): ?Record
    {
        return $this->keyedQuery()->first();
    }

    /**
     * @param ServerRequestInterface $request
     * @return null|Record
     */
    public function record(ServerRequestInterface $request): ?Record
    {
        return $this->resolve($this->keyedQuery(), $request)->first();
    }

    /**
     * @param ServerRequestInterface $request
     * @return ResultSet
     */
    public function collection(ServerRequestInterface $request): ResultSet
    {
        $parsedQuery = $request->getAttribute('parsedQuery');
        $applyRandomSort = false;

        // If sorting by random, remove any applied limit and remove the sort value from the request
        if (($parsedQuery->getSort()[0] ?? null)?->getPropertyName() === 'random') {
            $applyRandomSort = true;
            $parsedQuery->setSort([]);

            $limit = null;
            $page = 1;

            $collection = (new ResultSet())->setMetaData([
                'from' => 0,
                'hits' => null,
                'outOf' => null,
                'pageNumber' => 1,
                'perPage' => $parsedQuery->getLimit() ?: null,
                'to' => $parsedQuery->getLimit() ?: null,
                'total' => null,
            ]);
        } else {
            $limit = $parsedQuery->getLimit();
            $offset = ($parsedQuery->getPage() ? (intval($parsedQuery->getPage()) - 1) * intval($limit) : $parsedQuery->getOffset()) ?: 0;
            $page = $limit ? (intval(ceil(($offset) / $limit)) + 1) : 1;

            $collection = (new ResultSet())->setMetaData([
                'from' => $offset,
                'hits' => null,
                'outOf' => null,
                'pageNumber' => $parsedQuery->getPage() ?: $page,
                'perPage' => $limit ?: null,
                'to' => $limit ? $offset + $limit : null,
                'total' => null,
            ]);
        }

        $requestLimit = !$limit || $limit > static::$hardLimit ? static::$hardLimit : $limit;
        $allRecordRetrieved = false;

        do {
            $batch = $this->resolve($this->baseQuery(), $request)->limit($requestLimit)->page($page);

            if (count($batch) < $requestLimit) {
                $allRecordRetrieved = true;
            }

            $batchMeta = $batch->getMetaData();

            $collection->addMetaData('outOf', $batchMeta['outOf']);
            $collection->addMetaData('total', $batchMeta['total']);

            if (!$limit) {
                $collection->addMetaData('perPage', $batchMeta['total']);
                $collection->addMetaData('to', $batchMeta['total']);
            }

            $collection->append($batch);

            $collection->addMetaData('hits', $collection->count());

            $page++;
        } while (!$allRecordRetrieved && (!$limit || $collection->count() < $limit));

        if ($applyRandomSort) {
            $results = (array) $collection->getItems();

            shuffle($results);

            if ($limit = $parsedQuery->getLimit()) {
                $results = array_slice($results, 0, $limit);

                $collection->addMetaData('hits', count($results));
                $collection->addMetaData('perPage', $limit);
                $collection->addMetaData('to', $limit - 1);
            }

            $collection->setItems($results);
        }

        return $collection;
    }

    /**
     * @param QueryBuilder $queryBuilder
     * @param ServerRequestInterface $request
     * @return Query
     */
    public function resolve(QueryBuilder $queryBuilder, ServerRequestInterface $request): Query
    {
        $parsedQuery = $request->getAttribute('parsedQuery');

        return (new Query($queryBuilder))->include($parsedQuery->getRelations())
            ->select($parsedQuery->getFields())
            ->where($parsedQuery->getFilters())
            ->orderBy($parsedQuery->getSort())
            ->search($parsedQuery->getSearch());
    }

    /**
     * @return QueryBuilder
     */
    public function keyedQuery(): QueryBuilder
    {
        return $this->baseQuery()->where('id', $this->pipe->getKey());
    }

    /**
     * @return QueryBuilder
     */
    public function baseQuery(string $search = '*'): QueryBuilder
    {
        $this->queryBuilder = new QueryBuilder($this->contentType, $this->schema, $search);

        if ($this->pipe->isScoped()) {
            $scope = $this->pipe->getScope();

            return $this->queryBuilder->where($scope->getKey(), $scope->getValue());
        }

        return $this->queryBuilder;
    }
}
