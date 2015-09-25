<?php

namespace Jenky\LaravelApiHelper\Contracts;

interface Factory
{
    /**
     * Set the notification drivers.
     * 
     * @param mixed $builder
     *
     * @return \Jenky\LaravelApiHelper\Factory
     */
    public function make($builder);
}
