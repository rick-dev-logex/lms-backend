<?php

namespace App\Http\Middleware;

use Illuminate\Cookie\Middleware\EncryptCookies as BaseEncryptCookies;

class EncryptCookies extends BaseEncryptCookies
{
    /**
     * Las cookies que deben estar exentas del cifrado.
     *
     * @var array
     */
    protected $except = [
        'jwt-token',
    ];
}
