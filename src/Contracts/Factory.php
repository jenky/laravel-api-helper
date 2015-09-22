<?php

namespace Jenky\LaravelApiHelper\Contracts;

interface Factory
{
    /**
     * Set the notification drivers
     * 
     * @param mixed $drivers
     * @return \Jenky\LaravelApiHelper\Factory
     */ 
    public function make($builder, $id = null);
}