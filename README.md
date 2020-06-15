# phpish/shopify

Simple [Shopify API](http://api.shopify.com/) client in PHP


## Requirements

* PHP 5.3+ with [cURL support](http://php.net/manual/en/book.curl.php).

## Usage

```php
use phpish\shopify;

$shop_url    = example.myshopify.com
$api_key     = 'YOUR_APPS_API_KEY'
$oauth_token = 'MERCHANT_OAUTH_TOKEN'
$private_app = false;

$api = new shopify\client($shop_url, $api_key, $oauth_token, $private_app);

$params  = [
  'limit' => 250
];

$products = $api->request('GET /admin/api/2020-04/products.json', $params);
```

## Usage and Quickstart Skeleton Project

See [phpish/shopify_app-skeleton](https://github.com/phpish/shopify_app-skeleton) and [phpish/shopify_private_app-skeleton](https://github.com/phpish/shopify_private_app-skeleton)
