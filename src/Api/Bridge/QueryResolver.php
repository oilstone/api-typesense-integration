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
        $limit = $parsedQuery->getLimit();
        $offset = ($parsedQuery->getPage() ? (intval($parsedQuery->getPage()) - 1) * intval($limit) : $parsedQuery->getOffset()) ?: 0;
        $requestLimit = !$limit || $limit > static::$hardLimit ? static::$hardLimit : $limit;
        $page = $limit ? (intval(ceil(($offset) / $limit)) + 1) : 1;
        $allRecordRetrieved = false;

        $collection = (new ResultSet())->setMetaData([
            'from' => $offset,
            'hits' => null,
            'outOf' => null,
            'pageNumber' => $parsedQuery->getPage() ?: $page,
            'perPage' => $limit ?: null,
            'to' => $limit ? $offset + $limit : null,
            'total' => null,
        ]);

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
