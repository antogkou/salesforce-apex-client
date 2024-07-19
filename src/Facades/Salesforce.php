<?php


namespace YourVendorName\ApexClient\Facades;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Facade;

/**
 * @method static Response get(string $url, array $query = [], array $additionalHeaders = [])
 * @method static Response post(string $url, array $data, array $additionalHeaders = [])
 * @method static Response put(string $url, array $data, array $additionalHeaders = [])
 * @method static Response patch(string $url, array $data, array $additionalHeaders = [])
 * @method static Response delete(string $url, array $additionalHeaders = [])
 * @method static self setEmail(string $email)
 *
 * @see \App\Http\Integrations\ApexClient
 */
class Salesforce extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'salesforce';
    }
}
