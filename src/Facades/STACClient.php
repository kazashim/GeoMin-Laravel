<?php

namespace GeoMin\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * STAC Client Facade
 * 
 * Provides a static interface to the STAC client for
 * accessing satellite imagery from various providers.
 * 
 * @see \GeoMin\Clients\STACClient
 * 
 * @author Kazashim Kuzasuwat
 */
class STACClient extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \GeoMin\Clients\STACClient::class;
    }
}
