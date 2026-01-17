<?php

namespace GeoMin\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * Mineralogy Facade
 * 
 * Provides a static interface to advanced mineralogy algorithms
 * for mapping hydrothermal alteration and mineral deposits.
 * 
 * @see \GeoMin\Algorithms\Mineralogy\AdvancedMineralogy
 * 
 * @author Kazashim Kuzasuwat
 */
class Mineralogy extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \GeoMin\Algorithms\Mineralogy\AdvancedMineralogy::class;
    }
}
