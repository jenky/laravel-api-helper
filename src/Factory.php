<?php

namespace Jenky\LaravelApiHelper;

use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;

class Factory implements Contracts\Factory
{
    /**
     * @var Illuminate\Http\Request
     */
    protected $request;

    public function __construct(Request $request)
    {
        $this->request = $request;        
    }

    /**
     * Create the builder.
     * 
     * @param mixed $item
     * @param int $id
     * 
     * @return \Jenky\LaravelApiHelper\Builder
     */
    public function make($builder, $id = null)
    {
        if (!is_null($id)) {
            if (is_numeric($id)) {
                $id = intval($id);
            }
        }

        return $this->parseBuilder($builder);
    }

    /**
     * Parse the builder
     * 
     * @param mixed $builder
     */
    protected function parseBuilder($builder)
    {
        if ($builder instanceof EloquentBuilder)
        {
            return $this->createHandler(null, $builder->getQuery());
        }

        if (is_subclass_of($builder, Relation::class))
        {
            return $this->createHandler($builder->getQuery(), $builder->getBaseQuery());
        }

        if (is_subclass_of($builder, Model::class))
        {
            return $this->createHandler($builder->newQuery(), $builder->getQuery());
        }

        if ($builder instanceof QueryBuilder)
        {
            return $this->createHandler(null, $builder);
        }
    }

    protected function createHandler($builder = null, $query = null)
    {
        $handler = new Handler($this->request);

        if (!is_null($builder)) {
            $handler->setBuilder($builder);
        }

        if (!is_null($query)) {
            $handler->setQuery($query);
        }

        return $handler;
    }
}