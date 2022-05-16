<?php

namespace Oilstone\ApiTypesenseIntegration\Interfaces;

/**
 * Interface TypesenseSearch
 *
 * @package Oilstone\ApiTypesenseIntegration\Interfaces
 */
interface TypesenseDocument
{
    public function typesenseQueryBy(): array;

    public function getCollectionSchema(): array;
}
