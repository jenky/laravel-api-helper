<?php

if (! function_exists('apihelper')) {
    /**
     * Get the api helper.
     *
     * @param mixed $builder
     *
     * @return \Jenky\LaravelApiHelper\Handler
     */
    function apihelper($builder = null)
    {
        $factory = app('apihelper');

        if (is_null($builder)) {
            return $factory;
        }

        return $factory->make($builder);
    }
}
