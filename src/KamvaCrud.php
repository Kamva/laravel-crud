<?php

namespace Kamva\Crud;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\Facade as BaseFacade;

/**
 * Class KamvaCrud
 * @package Kamva\Crud
 * @method static void      addExtension($type, \Closure $callable)
 * @method static void      addColumnType(string $name, \Closure $callback)
 * @method static bool      hasColumnType(string $name)
 * @method static Response  apiResponse($data, $code = 200)
 * @method static mixed     set($key, $value)
 * @method static mixed     get($key)
 * @method static void      flushRequestState(?object $request = null)
 * @method static void      setDefaultACLMethod($callable)
 * @method static void      setActionAclMode(string $mode)
 * @method static string    getActionAclMode()
 * @method static bool      isApi()

 */
class KamvaCrud extends BaseFacade
{
    /**
     * The name of the binding in the IoC container.
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return 'kamva-crud';
    }
}
