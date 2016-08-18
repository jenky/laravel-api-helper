<?php

namespace Jenky\LaravelApiHelper;

use Illuminate\Contracts\Config\Repository as Config;
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
     * @var \Illuminate\Contracts\Config\Repository
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
     * The relations to eager load.
     *
     * @var array
     */
    protected $with = [];

    /**
     * Create new instance.
     *
     * @param  \Illuminate\Http\Request $request
     * @param  \Illuminate\Contracts\Config\Repository $config
     * @return void
     */
    public function __construct(Request $request, Config $config)
    {
        $this->request = $request;
        $this->config = $config;
    }

    /**
     * Check if builder is eloquent.
     *
     * @return bool
     */
    protected function isEloquentBuilder()
    {
        return ! is_null($this->builder) && $this->builder instanceof EloquentBuilder;
    }

    /**
     * Get the value from apihelper.php.
     *
     * @param  string $config
     * @param  mixed $default
     * @return mixed
     */
    protected function config($config, $default = null)
    {
        return $this->config->get("apihelper.{$config}", $default);
    }

    /**
     * Get the param from request.
     *
     * @param  string $param
     * @param  mixed $default
     * @return mixed
     */
    protected function input($param, $default = null)
    {
        return $this->request->input($this->config('prefix', '').$param, $default);
    }

    /**
     * Get supported params.
     *
     * @param  string $method
     * @return array
     */
    protected function getSupportedParams($method)
    {
        $columns = [];

        if ($this->isEloquentBuilder()) {
            $model = $this->getHandler()->getModel();
            $columns = in_array(ApiHelper::class, class_uses($model))
                ? $model->$method()
                : $model->getFillable();
        }

        return $columns;
    }

    /**
     * Check if the column can be filtered.
     *
     * @param  string $column
     * @return bool
     */
    protected function canFilter($column)
    {
        if (! $column) {
            return false;
        }

        return in_array($column, $this->getSupportedParams('getApiFilterable'));
    }

    /**
     * Check if the column can be sorted.
     *
     * @param  string $column
     * @return bool
     */
    protected function canSort($column)
    {
        if (! $column) {
            return false;
        }

        return in_array($column, $this->getSupportedParams('getApiSortble'));
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

    /**
     * Parse the special param.
     *
     * @param  string $param
     * @return void
     */
    protected function parseParam($param)
    {
        $method = 'parse'.ucfirst($param);

        if (method_exists($this, $method)) {
            if ($params = $this->input($param)) {
                return $this->$method($params);
            }
        }
    }

    /**
     * Parse the sort parameter.
     *
     * @param  string $params
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
            if (! $this->canSort($sort)) {
                return;
            }

            // Only add the sorts that are on the base resource
            if (! Str::contains($sort, '.')) {
                $this->getHandler()->orderBy($sort, $direction);
            } else {
                $this->additionalSorts[$sort] = $direction;
            }
        }
    }

    /**
     * Parse the fields parameter.
     *
     * @param  string $params
     * @return void
     */
    protected function parseFields($params)
    {
        foreach (explode(',', $params) as $field) {
            // Only add the fields that are on the base resource
            if (! Str::contains($field, '.')) {
                $this->fields[] = trim($field);
            } else {
                $this->additionalFields[] = trim($field);
            }
        }
    }

    /**
     * Parse the limit parameter.
     *
     * @param  int $param
     * @return void
     */
    protected function parseLimit($param)
    {
        $this->getHandler()->limit(intval($param));
    }

    /**
     * Parse the with parameter.
     *
     * @param  string $params
     * @return void
     */
    protected function parseWith($params)
    {
        $with = explode(',', $params);
        $withable = $this->getSupportedParams('getApiWithable');

        $with = in_array('*', $withable) ? $with : array_only($with, $withable);

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

        if (! empty($with)) {
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
        if (! $params = $this->getParams()) {
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
     * @param  string $key
     * @param  string $value
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

        if (! isset($matches[3])) {
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

        $column = isset($matches[2]) ? $matches[2] : null;

        return compact('comparator', 'column', 'matches');
    }

    /**
     * Apply the filter to query builder.
     *
     * @param  string $key
     * @param  string $value
     * @return void
     */
    protected function filter($key, $value)
    {
        extract($this->formatParam($key, $value));

        if (! $this->canFilter($column)) {
            return;
        }

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
     * @param  string $key
     * @param  string $value
     * @return void
     */
    protected function filterRelation($key, $value)
    {
        $key = str_replace('~', '.', $key);
        $parts = explode('.', $key);

        $realKey = array_pop($parts);
        $relation = implode('.', $parts);

        if (! in_array($relation, array_keys($this->with))) {
            return;
        }

        extract($this->formatParam($realKey, $value));

        if (! $this->canFilter($column)) {
            return;
        }

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
     * @param  $query
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
     * @param  $builder
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

        return $this->params ?: $this->request->except($reserved);
    }

    /**
     * Get columns name.
     *
     * @param  array $columns
     * @return array
     */
    protected function getColumns(array $columns)
    {
        return $this->fields ?: $columns;
    }

    /**
     * Find a model by its primary key.
     *
     * @param  int $id
     * @param  array $column
     * @return mixed
     */
    public function find($id, $columns = ['*'])
    {
        $this->parse();

        return $this->getHandler()->find($id, $this->getColumns($columns));
    }

    /**
     * Find a model by its primary key or throw an exception.
     *
     * @param  int $id
     * @param  array $column
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     * @return mixed
     */
    public function findOrFail($id, $columns = ['*'])
    {
        $this->parse();

        return $this->getHandler()->findOrFail($id, $this->getColumns($columns));
    }

    /**
     * Execute the query and get the first result.
     *
     * @param  array $columns
     * @return mixed
     */
    public function first($columns = ['*'])
    {
        $this->parse();

        return $this->getHandler()->first($this->getColumns($columns));
    }

    /**
     * Execute the query and get the first result or throw an exception.
     *
     * @param  array $columns
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     * @return mixed
     */
    public function firstOrFail($columns = ['*'])
    {
        $this->parse();

        return $this->getHandler()->firstOrFail($this->getColumns($columns));
    }

    /**
     * @param  array $column
     * @return mixed
     */
    public function item($columns = ['*'])
    {
        $this->parse();

        return $this->getHandler()->first($this->getColumns($columns));
    }

    /**
     * @param  array $column
     * @return mixed
     */
    public function collection($columns = ['*'])
    {
        $this->parse();

        $columns = $this->getColumns($columns);

        if ($this->input('page') || $this->input('limit')) {
            $limit = $this->config('limit', 20);
            $perPage = intval($this->input('limit', $limit));
            $results = $this->getHandler()->paginate($perPage, $columns, $this->config('prefix', '').'page');
            $results->appends($this->config('prefix', '').'limit', $perPage);
        } else {
            $results = $this->getHandler()->get($columns);
        }

        return is_array($results) ? Collection::make($results) : $results;
    }

    /**
     * Paginate the given query.
     *
     * @param  int $perPage
     * @param  array $columns
     * @param  string $pageName
     * @param  int|null $page
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function paginate($perPage = null, $columns = ['*'], $pageName = 'page', $page = null)
    {
        $this->parse();

        return $this->getHandler()->paginate($this->input('limit', $perPage), $this->getColumns($columns), $this->config('prefix', '').$pageName, $page);
    }

    /**
     * Get the handler.
     *
     * @throws \InvalidArgumentException
     * @return \Illuminate\Database\Query\Builder | \Illuminate\Database\Eloquent\Builder
     */
    protected function getHandler()
    {
        if ($this->builder) {
            return $this->builder;
        }

        if ($this->query) {
            return $this->query;
        }

        throw new InvalidArgumentException('Missing query builder');
    }
}
