<?php

namespace Jenky\LaravelApiHelper;

use Illuminate\Config\Repository as Config;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;

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
     * @param int   $id
     * 
     * @return \Jenky\LaravelApiHelper\Builder
     */
    public function make($builder)
    {
        return $this->parseBuilder($builder);
    }

    /**
     * Parse the builder.
     * 
     * @param mixed $builder
     */
    protected function parseBuilder($builder)
    {
        if ($builder instanceof EloquentBuilder) {
            return $this->createHandler($builder->getQuery(), $builder);
        }
        if (is_subclass_of($builder, Model::class)) {
            return $this->createHandler($builder->getQuery(), $builder->newQuery());
        }
        if ($builder instanceof QueryBuilder) {
            return $this->createHandler($builder, $builder);
        }
    }

    /**
     * Create the handler.
     * 
     * @param mixed $query
     * @param mixed $builder
     * 
     * @return \Jenky\LaravelApiHelper\Handler
     */
    protected function createHandler($query, $builder = null)
    {
        $handler = new Handler($this->request, $this->config);

        $handler->setQuery($query);

        if (!is_null($builder)) {
            $handler->setBuilder($builder);
        }

        return $handler;
    }
}
