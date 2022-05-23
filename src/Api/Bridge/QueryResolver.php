<?php

namespace Oilstone\ApiTypesenseIntegration\Api\Bridge;

use Aggregate\Set;
use Api\Pipeline\Pipes\Pipe;
use Api\Result\Contracts\Record;
use Api\Schema\Schema;
use Oilstone\ApiTypesenseIntegration\Query as QueryBuilder;
use Psr\Http\Message\ServerRequestInterface;

class QueryResolver
{
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
     * @return Set
     */
    public function collection(ServerRequestInterface $request): Set
    {
        $parsedQuery = $request->getAttribute('parsedQuery');
        $limit = $parsedQuery->limit();
        $offset = $parsedQuery->offset();

        if ($limit && $offset) {
            $page = intval(ceil($offset / $limit)) + 1;

            return $this->resolve($this->baseQuery(), $request)->page($page);
        }

        return $this->resolve($this->baseQuery(), $request)->get();
    }

    /**
     * @param QueryBuilder $queryBuilder
     * @param ServerRequestInterface $request
     * @return Query
     */
    public function resolve(QueryBuilder $queryBuilder, ServerRequestInterface $request): Query
    {
        $parsedQuery = $request->getAttribute('parsedQuery');

        return (new Query($queryBuilder))->include($parsedQuery->relations())
            ->limit($parsedQuery->limit())
            ->select($parsedQuery->fields())
            ->where($parsedQuery->filters())
            ->orderBy($parsedQuery->sort())
            ->search($parsedQuery->search());
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
