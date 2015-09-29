<?php

namespace Jenky\LaravelApiHelper;

use Illuminate\Config\Repository as Config;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;

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
     * List all params and its value from request.
     * 
     * @var array
     */
    protected $params = [];

    /**
     * Selected fields.
     * 
     * @var array
     */
    protected $fields = [];

    /**
     * Nested, relation sort params.
     * 
     * @var array
     */
    protected $additionalSorts = [];

    /**
     * Additional fields for filter.
     * 
     * @var array
     */
    protected $additionalFields = [];

    /**
     * Query Builder.
     * 
     * @var \Illuminate\Database\Query\Builder
     */
    protected $query;

    /**
     * Eloquent Builder.
     * 
     * @var \Illuminate\Database\Eloquent\Builder
     */
    protected $builder;

    /**
     * The relations to eager load on every query.
     *
     * @var array
     */
    protected $with = [];

    public function __construct(Request $request, Config $config)
    {
        $this->request = $request;
        $this->config = $config;
    }

    protected function isEloquentBuilder()
    {
        return !is_null($this->builder) && $this->builder instanceof EloquentBuilder;
    }

    /**
     * Get the value from apihelper.php.
     * 
     * @param string $config
     * @param mixed  $default
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
     * @var string
     *
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
        if ($this->isEloquentBuilder()) {
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

            $sort = preg_replace('/^-/', '', $sort);

            // Only add the sorts that are on the base resource
            if (!Str::contains($sort, '.')) {
                $this->getHandler()->orderBy($sort, $direction);
            } else {
                $this->additionalSorts[$sort] = $direction;
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
                $this->additionalFields[] = trim($field);
            }
        }
    }

    /**
     * Parse the limit parameter.
     *
     * @return void
     */
    protected function parseLimit($param)
    {
        $this->getHandler()->limit(intval($param));
    }

    /**
     * Parse the with parameter.
     *
     * @return void
     */
    protected function parseWith($param)
    {
        $with = explode(',', $param);

        foreach ($this->additionalSorts as $sort => $direction) {
            $parts = explode('.', $sort);
            $realKey = array_pop($parts);
            $relation = implode('.', $parts);

            if (in_array($relation, $with)) {
                $this->builder->with([$relation => function ($query) use ($realKey, $direction) {
                    $query->orderBy($realKey, $direction);
                }]);

                if (($key = array_search($relation, $with)) !== false) {
                    unset($with[$key]);
                }
            }
        }

        if (!empty($with)) {
            $this->builder->with($with);
        }

        $this->with = $this->builder->getEagerLoads();
    }

    /**
     * Parse all the paramenters for query builder.
     * 
     * @return void
     */
    protected function parseFilter()
    {
        if (!$params = $this->getParams()) {
            return;
        }

        foreach ($params as $key => $value) {
            if ($this->isEloquentBuilder() && Str::contains($key, '~')) {
                $this->filterRelation($key, $value);
            } else {
                $this->filter($key, $value);
            }
        }
    }

    /**
     * Format the paramenter for query builder.
     * 
     * @param string $key
     * @param string $value
     * 
     * @return array
     */
    protected function formatParam($key, $value)
    {
        $supportedFixes = [
            'lt'     => '<',
            'gt'     => '>',
            'lte'    => '<=',
            'gte'    => '>=',
            'lk'     => 'LIKE',
            'not-lk' => 'NOT LIKE',
            'in'     => 'IN',
            'not-in' => 'NOT IN',
            'not'    => '!=',
        ];

        $prefixes = implode('|', $supportedFixes);
        $suffixes = implode('|', array_keys($supportedFixes));

        $matches = [];

        // Matches every parameter with an optional prefix and/or postfix
        // e.g. not-title-lk, title-lk, not-title, title
        $regex = '/^(?:('.$prefixes.')-)?(.*?)(?:-('.$suffixes.')|$)/';

        preg_match($regex, $key, $matches);

        if (!isset($matches[3])) {
            if (Str::lower(trim($value)) == 'null') {
                $comparator = 'NULL';
            } else {
                $comparator = '=';
            }
        } else {
            if (Str::lower(trim($value)) == 'null') {
                $comparator = 'NOT NULL';
            } else {
                $comparator = $supportedFixes[$matches[3]];
            }
        }

        $column = $matches[2];

        return compact('comparator', 'column', 'matches');
    }

    /**
     * Apply the filter to query builder.
     * 
     * @param string $key
     * @param string $value
     * 
     * @return void
     */
    protected function filter($key, $value)
    {
        extract($this->formatParam($key, $value));

        if ($comparator == 'IN') {
            $values = explode(',', $value);
            $this->getHandler()->whereIn($column, $values);
        } elseif ($comparator == 'NOT IN') {
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

    /**
     * Apply the filter to relationship query builder.
     * 
     * @param string $key
     * @param string $value
     * 
     * @return void
     */
    protected function filterRelation($key, $value)
    {
        $key = str_replace('~', '.', $key);
        $parts = explode('.', $key);

        $realKey = array_pop($parts);
        $relation = implode('.', $parts);

        if (!in_array($relation, array_keys($this->with))) {
            return;
        }

        extract($this->formatParam($realKey, $value));

        $this->builder->whereHas($relation, function ($q) use ($column, $comparator, $value) {
            if ($comparator == 'IN') {
                $values = explode(',', $value);
                $q->whereIn($column, $values);
            } elseif ($comparator == 'NOT IN') {
                $values = explode(',', $value);
                $q->whereNotIn($column, $values);
            } else {
                $values = explode('|', $value);
                if (count($values) > 1) {
                    $q->where(function ($query) use ($column, $comparator, $values) {
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
                        $q->whereNull($column, 'and', $comparator == 'NOT NULL');
                    } else {
                        $q->where($column, $comparator, $value);
                    }
                }
            }
        });
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
     * Get the query builder.
     * 
     * @return \Illuminate\Database\Query\Builder
     */
    public function getQuery()
    {
        return $this->query;
    }

    /**
     * Set the Eloquent builder.
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
     * Get the Eloquent builder.
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
     * @param array $keys
     *
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
     * @param array $keys
     *
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
            $this->config('prefix', '').'page',
        ];

        return $this->params ? $this->params : $this->request->except($reserved);
    }

    /**
     * @param int $id
     * @param array $column
     * 
     * @return mixed
     */
    public function find($id, $columns = ['*'])
    {
        $this->parse();

        $columns = !empty($this->fields) ? $this->fields : $columns;

        return $this->getHandler()->find($id);
    }

    /**
     * @param int $id
     * @param array $column
     * 
     * @return mixed
     */
    public function item($columns = ['*'])
    {
        $this->parse();

        $columns = !empty($this->fields) ? $this->fields : $columns;

        return $this->getHandler()->first($columns);
    }

    /**
     * @param int $id
     * @param array $column
     * 
     * @return mixed
     */
    public function collection($columns = ['*'])
    {
        $this->parse();

        $columns = !empty($this->fields) ? $this->fields : $columns;

        if ($this->input('page')) {
            $results = $this->getHandler()->paginate($this->input('limit', 20), $columns, $this->config('prefix', '').'page');
        } else {
            $results = $this->getHandler()->get($columns);
        }

        return is_array($results) ? Collection::make($results) : $results;
    }

    protected function getHandler()
    {
        if ($this->builder) {
            return $this->builder;
        }

        if ($this->query) {
            return $this->query;
        };

        throw new InvalidArgumentException('Missing query builder');
    }
}
