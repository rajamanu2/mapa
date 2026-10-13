<?php
namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Illuminate\Http\Request;

// Cloud image only. The API has no published ports; the private gateway replaces
// forwarding headers. Do not use this class on an API exposed directly to the Internet.
class TrustProxies extends Middleware
{
    protected $proxies = '*';
    protected $headers = Request::HEADER_X_FORWARDED_FOR
        | Request::HEADER_X_FORWARDED_HOST
        | Request::HEADER_X_FORWARDED_PORT
        | Request::HEADER_X_FORWARDED_PROTO;
}
