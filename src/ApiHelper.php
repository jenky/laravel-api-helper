<?php

namespace Jenky\LaravelApiHelper;

trait ApiHelper
{
    /**
     * Get the API searchable attributes for the model.
     *
     * @return array
     */
    public function getApiFilterable()
    {
        return $this->getApiHelperProperty('apiFilterable');
    }

    /**
     * Set the API filterable attributes for the model.
     *
     * @param  array $filterable
     * @return $this
     */
    public function apiFilterable(array $filterable)
    {
        $this->apiFilterable = $filterable;

        return $this;
    }

    /**
     * Get the API searchable attributes for the model.
     *
     * @return array
     */
    public function getApiSortble()
    {
        return $this->getApiHelperProperty('apiSortable');
    }

    /**
     * Set the API sortable attributes for the model.
     *
     * @param  array $sortable
     * @return $this
     */
    public function apiSortable(array $sortable)
    {
        $this->apiSortable = $sortable;

        return $this;
    }

    /**
     * Get the API withable attributes for the model.
     *
     * @return array
     */
    public function getApiWithable()
    {
        return property_exists($this, 'apiWithable') ? (array) $this->apiWithable : [];
    }

    /**
     * Set the API withable attributes for the model.
     *
     * @param  array $withable
     * @return $this
     */
    public function apiWithable(array $withable)
    {
        $this->apiWithable = $withable;

        return $this;
    }

    /**
     * Get the API helper property.
     *
     * @param  string $property
     * @return array
     */
    protected function getApiHelperProperty($property)
    {
        return property_exists($this, $property) ? (array) $this->{$property} : $this->getFillable();
    }
}
