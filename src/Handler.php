<?php

namespace Jenky\LaravelApiHelper;

use ArrayObject;
use InvalidArgumentException;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Config\Repository as Config;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;

class Handler
{
    /**
     * @var Illuminate\Http\Request
     */
    protected $request;

    /**
     * @var \Illuminate\Contracts\Config
     */
    protected $config;

    /**
     * @var array
     */
    protected $params = [];

    /**
     * @var array
     */
    protected $fields = [];

    /**
     * Query Builder
     * 
     * @var \Illuminate\Database\Query\Builder
     */
    protected $query;

    /**
     * Eloquent Builder
     * 
     * @var \Illuminate\Database\Eloquent\Builder
     */
    protected $builder;

    public function __construct(Request $request, Config $config)
    {
        $this->request = $request;
        $this->config = $config;
    }

    /**
     * Get the value from apihelper.php
     * 
     * @param string $config
     * @param mixed $default
     * 
     * @return mixed
     */
    protected function config($config, $default = null)
    {
        return $this->config->get("apihelper.{$config}", $default);
    }

    /**
     * Get the param from request.
     * 
     * @var string $param
     * @param mixed $default
     * 
     * @return mixed
     */
    protected function input($param, $default = null)
    {
        return $this->request->input($this->config('prefix', '').$param, $default);
    }

    /**
     * Parse the data from the request.
     * 
     * @return void
     */
    protected function parse()
    {
        $this->parseParam('sort');
        $this->parseParam('fields');
        $this->parseParam('limit');
        if ($this->builder instanceof EloquentBuilder
            || is_subclass_of($builder, Relation::class)) {
            $this->parseParam('with');
        }
        $this->parseFilter();
    }

    protected function parseParam($param)
    {
        $method = 'parse'.ucfirst($param);

        if (method_exists($this, $method)) {
            if ($params = $this->input($param)) {
                return $this->$method($params);
            } else {
                return;
            }
        }

        throw new InvalidArgumentException("Param [$param] not supported.");
    }

    /**
     * Parse the sort parameter.
     *
     * @return void
     */
    protected function parseSort($params)
    {
        foreach (explode(',', $params) as $sort) {
            // Check if ascending or descending(-) sort
            if (preg_match('/^-.+/', $sort)) {
                $direction = 'desc';
            } else {
                $direction = 'asc';
            }
            
            $pair = [preg_replace('/^-/', '', $sort), $direction];
            
            // Only add the sorts that are on the base resource
            if (!Str::contains($sort, '.')) {
                call_user_func_array([$this->getHandler(), 'orderBy'], $pair);
            } else {
                // $this->additionalSorts[] = $pair;
            }
        }
    }

    /**
     * Parse the fields parameter.
     *
     * @return void
     */
    protected function parseFields($params)
    {
        foreach (explode(',', $params) as $field) {
            //Only add the fields that are on the base resource
            if (!Str::contains($field, '.')) {
                $this->fields[] = trim($field);
            } else {
                // $this->additionalFields[] = trim($field);
            }
        }
    }

    /**
     * Parse limit fields parameter.
     *
     * @return void
     */
    protected function parseLimit($param)
    {
        $this->getHandler()->limit(intval($param));
    }

    /**
     * Parse limit with parameter.
     *
     * @return void
     */
    protected function parseWith($param)
    {
        $this->builder->with(explode(',', $param));
    }

    protected function parseFilter()
    {
        if (!$params = $this->getParams()) {
            return;
        }

        $supportedFixes = [
            'lt' => '<',
            'gt' => '>',
            'lte' => '<=',
            'gte' => '>=',
            'lk' => 'LIKE',
            'not-lk' => 'NOT LIKE',
            'in' => 'IN',
            'not-in' => 'NOT IN',
            'not' => '!=',
        ];

        $prefixes = implode('|', $supportedFixes);
        $suffixes = implode('|', array_keys($supportedFixes));

        foreach ($params as $key => $value) {
            $matches = [];

            // Matches every parameter with an optional prefix and/or postfix
            // e.g. not-title-lk, title-lk, not-title, title
            $regex = '/^(?:(' . $prefixes . ')-)?(.*?)(?:-(' . $suffixes . ')|$)/';

            preg_match($regex, $key, $matches);

            if (!isset($matches[3])) {
                if (strtolower(trim($value)) == 'null') {
                    $comparator = 'NULL';
                } else {
                    $comparator = '=';
                }
            } else {
                if (strtolower(trim($value)) == 'null') {
                    $comparator = 'NOT NULL';
                } else {
                    $comparator = $supportedFixes[$matches[3]];
                }
            }

            $column = $matches[2];

            if ($comparator == 'IN') {
                $values = explode(',', $value);
                $this->getHandler()->whereIn($column, $values);
            } else if ($comparator == 'NOT IN') {
                $values = explode(',', $value);
                $this->getHandler()->whereNotIn($column, $values);
            } else {
                $values = explode('|', $value);
                if (count($values) > 1) {
                    $this->getHandler()->where(function ($query) use ($column, $comparator, $values) {
                        foreach ($values as $value) {
                            if ($comparator == 'LIKE' || $comparator == 'NOT LIKE') {
                                $value = preg_replace('/(^\*|\*$)/', '%', $value);
                            }
                            // Link the filters with AND of there is a "not" and with OR if there's none
                            if ($comparator == '!=' || $comparator == 'NOT LIKE') {
                                $query->where($column, $comparator, $value);
                            } else {
                                $query->orWhere($column, $comparator, $value);
                            }
                        }
                    });
                } else {
                    $value = $values[0];
                    if ($comparator == 'LIKE' || $comparator == 'NOT LIKE') {
                        $value = preg_replace('/(^\*|\*$)/', '%', $value);
                    }
                    if ($comparator == 'NULL' || $comparator == 'NOT NULL') {
                        $this->getHandler()->whereNull($column, 'and', $comparator == 'NOT NULL');
                    } else {
                        $this->getHandler()->where($column, $comparator, $value);
                    }
                }
            }
        }
    }

    /**
     * Set the query builder.
     * 
     * @param $query
     * 
     * @return void
     */
    public function setQuery($query)
    {
        $this->query = $query;
    }

    /**
     * Get the query builder
     * 
     * @return \Illuminate\Database\Query\Builder
     */
    public function getQuery()
    {
        return $this->query;
    }

    /**
     * Set the Eloquent builder
     * 
     * @param $builder
     * 
     * @return void
     */
    public function setBuilder($builder)
    {
        $this->builder = $builder;        
    }

    /**
     * Get the Eloquent builder
     * 
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function getBuilder()
    {
        return $this->builder;
    }

    /**
     * Get a subset of the items from the input data.
     *
     * @param  array  $keys
     * @return \Jenky\LaravelApiHelper\Handler
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
     * @return \Jenky\LaravelApiHelper\Handler
     */
    public function except($keys)
    {
        $this->params = $this->request->except($key);

        return $this;
    }

    /**
     * Get the params from the request.
     * 
     * @return array
     */
    protected function getParams()
    {
        $reserved = [
            $this->config('prefix', '').'sort',
            $this->config('prefix', '').'fields',
            $this->config('prefix', '').'limit',
            $this->config('prefix', '').'with',
        ];

        return $this->params ? $this->params : $this->request->except($reserved);
    }

    public function item($columns = ['*'])
    {
        $this->parse();

        $columns = !empty($this->fields) ? $this->fields : $columns;

        return $this->getHandler()->first($columns);
    }

    public function collection($columns = ['*'])
    {
        $this->parse();

        $columns = !empty($this->fields) ? $this->fields : $columns;

        $results = $this->getHandler()->get($columns);

        return is_array($results) ? Collection::make($results) : $results;
    }

    /**
     * Dynamically call the query builder.
     *
     * @param  string  $method
     * @param  array   $parameters
     * @return mixed
     */
    /*public function __call($method, $parameters)
    {
        if ($this->builder) {
            return call_user_func_array([$this->builder, $method], $parameters);
        }

        return call_user_func_array([$this->query, $method], $parameters);
    }*/
    protected function getHandler()
    {
        if ($this->builder) {
            return $this->builder;
        }

        if ($this->query) {
            return $this->query;
        };

        throw new InvalidArgumentException("Missing query builder");
    }

    /**
     * Check if there exists a method marked with the "@Relation"
     * annotation on the given model.
     *
     * @param  Illuminate\Database\Eloquent\Model  $model
     * @param  string                              $relationName
     * @return boolean
     */
    protected function isRelation($model, $relationName)
    {
        if (!method_exists($model, $relationName)) {
            return false;
        }
        $reflextionObject = new ReflectionObject($model);
        $doc = $reflextionObject->getMethod($relationName)->getDocComment();
        if ($doc && Str::contains($doc, '@Relation')) {
            return true;
        } else {
            return false;
        }
    }
}