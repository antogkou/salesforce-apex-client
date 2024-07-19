# ApexClient

ApexClient is a Laravel package for interacting with Salesforce Apex API.

## Installation

You can install the package via composer:

```bash
composer require antogkou/salesforce-apex-client
```

## Usage

```php
use antogkou\ApexClient\Facades\ApexClient;

// Get data from Salesforce
$response = Salesforce::get('your/api/endpoint');

// Post data to Salesforce
$response = Salesforce::post('your/api/endpoint', ['key' => 'value']);
```

## Configuration

Publish the config file:

```bash
php artisan vendor:publish --provider="antogkou\ApexClient\ApexClientServiceProvider"
```

Then, update the `config/salesforce.php` file with your Salesforce credentials.

## Testing

```bash
composer test
```

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
