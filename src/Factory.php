<?php

namespace Jenky\LaravelApiHelper;

use Illuminate\Http\Request;
use Illuminate\Config\Repository as Config;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;

class Factory implements Contracts\Factory
{
    /**
     * @var \Illuminate\Http\Request
     */
    protected $request;

    /**
     * @var \Illuminate\Contracts\Config
     */
    protected $config;

    public function __construct(Request $request, Config $config)
    {
        $this->request = $request;
        $this->config = $config;   
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
        /*if ($builder instanceof EloquentBuilder)
        {
            return $this->createEloquentBuilder($builder);
        }

        if (is_subclass_of($builder, Relation::class))
        {
            return $this->createEloquentBuilder($builder);
        }

        if (is_subclass_of($builder, Model::class))
        {
            return $this->createEloquentBuilder($builder->newQuery());
        }

        if ($builder instanceof QueryBuilder)
        {
            return $this->createQueryBuilder($builder);
        }*/
        if ($builder instanceof EloquentBuilder)
        {
            return $this->createHandler($builder->getQuery(), $builder);
        }
        if (is_subclass_of($builder, Relation::class))
        {
            return $this->createHandler($builder->getBaseQuery(), $builder->getQuery());
        }
        if (is_subclass_of($builder, Model::class))
        {
            return $this->createHandler($builder->getQuery(), $builder->newQuery());
        }
        if ($builder instanceof QueryBuilder)
        {
            return $this->createHandler($builder, $builder);
        }
    }

    protected function createHandler($query, $builder = null)
    {
        $handler = new Handler($this->request, $this->config);

        $handler->setQuery($query);

        if (!is_null($builder)) {
            $handler->setBuilder($builder);
        }        

        return $handler;
    }

    protected function createQueryBuilder($query)
    {
        $handler = new Builder\Query($this->request, $this->config);
        $handler->setQuery($query);

        return $handler;
    }

    protected function createEloquentBuilder($builder)
    {
        $handler = new Builder\Eloquent($this->request, $this->config);
        $handler->setBuilder($builder);

        return $handler;
    }
}