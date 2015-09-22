<?php

namespace Jenky\LaravelApiHelper;

use Illuminate\Http\Request;

class Handler
{
    /**
     * @var Illuminate\Http\Request
     */
    protected $request;

    /**
     * @var array
     */
    protected $params = [];

    /**
     * Query Builder
     */
    protected $query;

    /**
     * Eloquent Builder
     */
    protected $builder;

    public function __construct(Request $request)
    {
        $this->request = $request;        
    }

    /**
     * 
     */
    public function setQuery($query)
    {
        $this->query = $query;
    }

    /**
     * 
     */
    public function getQuery()
    {
        return $this->query;
    }

    /**
     * @return void
     */
    public function setBuilder($builder)
    {
        $this->builder = $builder;
    }

    /**
     * @return void
     */
    public function getBuilder()
    {
        return $this->builder;
    }

    /**
     * Get a subset of the items from the input data.
     *
     * @param  array  $keys
     * @return \Jenky\LaravelApiHelper\ApiHelper
     */
    public function only($key)
    {
        $this->params = $this->request->only($key);

        return $this;
    }

    /**
     * Get all of the input except for a specified array of items.
     *
     * @param  array  $keys
     * @return \Jenky\LaravelApiHelper\ApiHelper
     */
    public function except($keys)
    {
        $this->params = $this->request->except($key);

        return $this;
    }
}