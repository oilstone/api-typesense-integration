<?php

namespace Oilstone\ApiTypesenseIntegration\Api\Bridge;

use Api\Queries\Expression;
use Oilstone\ApiTypesenseIntegration\Query as BaseQuery;
use Api\Queries\Relations as RequestRelations;
use Oilstone\ApiTypesenseIntegration\Api\Record;
use Oilstone\ApiTypesenseIntegration\Api\ResultSet;

class Query
{
    /**
     * @var BaseQuery
     */
    protected BaseQuery $baseQuery;

    protected const OPERATOR_MAP = [
        'IS NULL' => '=',
        'IS NOT NULL' => '!='
    ];

    protected const VALUE_MAP = [
        'IS NULL' => null,
        'IS NOT NULL' => null
    ];

    /**
     * Query constructor.
     * @param BaseQuery $baseQuery
     */
    public function __construct(BaseQuery $baseQuery)
    {
        $this->baseQuery = $baseQuery;
    }

    /**
     * @return BaseQuery
     */
    public function getBaseQuery(): BaseQuery
    {
        return $this->baseQuery;
    }

    /**
     * @return ResultSet
     */
    public function get(): ResultSet
    {
        return $this->baseQuery->get();
    }

    /**
     * @return ResultSet
     */
    public function page(int $page): ResultSet
    {
        return $this->baseQuery->page($page);
    }

    /**
     * @return null|Record
     */
    public function first(): ?Record
    {
        return $this->baseQuery->first();
    }

    /**
     * @param RequestRelations $relations
     * @return self
     */
    public function include(RequestRelations $relations): self
    {
        foreach ($relations->collapse() as $relation) {
            $this->baseQuery->with($relation->path());
        }

        return $this;
    }

    /**
     * @param array $fields
     * @return self
     */
    public function select(array $fields): self
    {
        if ($fields) {
            $this->baseQuery->select(...$fields);
        }

        return $this;
    }

    /**
     * @param Expression $expression
     * @return self
     */
    public function where(Expression $expression): self
    {
        return $this->applyExpression($this->baseQuery, $expression);
    }

    /**
     * @param array $orders
     * @return self
     */
    public function orderBy(array $orders): self
    {
        foreach ($orders as $order) {
            $this->baseQuery->orderBy($order->getPath()->getEntity()?->getName() ?? $order->getPropertyName(), $order->getDirection());
        }

        return $this;
    }

    /**
     * @param $limit
     * @return self
     */
    public function limit($limit): self
    {
        if ($limit) {
            $this->baseQuery->limit($limit);
        }

        return $this;
    }

    /**
     * @param $search
     * @return self
     */
    public function search($search): self
    {
        if ($search) {
            $this->baseQuery->search($search);
        }

        return $this;
    }

    /**
     * @param $query
     * @param Expression $expression
     * @return self
     */
    protected function applyExpression($query, Expression $expression): self
    {
        foreach ($expression->getItems() as $item) {
            $method = $item['operator'] === 'OR' ? 'orWhere' : 'where';
            $constraint = $item['constraint'];

            if ($constraint instanceof Expression) {
                $query->{$method}(function ($query) use ($constraint)
                {
                    $this->applyExpression($query, $constraint);
                });
            } else {
                $operator = $constraint->getOperator();

                $query->{$method}(
                    $constraint->getPath()?->getEntity()?->getName() ?? $constraint->getPropertyName(),
                    $this->resolveConstraintOperator($operator),
                    $this->resolveConstraintValue($operator, $constraint->getValue())
                );
            }
        }

        return $this;
    }

    /**
     * @param $operator
     * @return mixed
     * @noinspection PhpMissingReturnTypeInspection
     */
    protected function resolveConstraintOperator($operator)
    {
        if (array_key_exists($operator, $this::OPERATOR_MAP)) {
            $operator = $this::OPERATOR_MAP[$operator];
        }

        return $operator;
    }

    /**
     * @param $operator
     * @param $value
     * @return mixed
     */
    protected function resolveConstraintValue($operator, $value)
    {
        if (array_key_exists($operator, $this::VALUE_MAP)) {
            $value = $this::VALUE_MAP[$operator];
        }

        return $value;
    }
}
