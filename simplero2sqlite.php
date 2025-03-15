<?php
//
// Pull down invoice and product information from Simplero and store in SQLite database
// Requires a Simplero API key
// Requires a file products_accounts_products.csv exported from your list of Simplero products (export csv, all fields)
//
require_once('vendor/autoload.php');

error_reporting(E_ERROR | E_WARNING | E_PARSE);

$shortopts = "k:";
$longopts = array("key:");
$options = getopt($shortopts, $longopts);
if (isset($options['k']))
{
    $apiKey = $options['k'];
}
else if (isset($options['key']))
{
    $apiKey = $options['key'];
}
else
{
    echo "Usage: php simplero2sqlite.php --key=<api_key>\n";
    exit;
}

if (!file_exists('products_accounts_products.csv'))
{
    echo "products_accounts_products.csv not found\n";
    echo "Export this file from Simplero by going to Sales->...->CSV - all columns\n";
    exit;
}

$byCategories = [];
if (($handle = fopen("products_accounts_products.csv", "r")) !== FALSE) {
    while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
        $num = count($data);
        if ($data[4] == null)
        {
            $data[4] = 'Other';
        }
		$byCategories[$data[0]] = $data[4];
    }
    fclose($handle);
}

$client = new \GuzzleHttp\Client();

$response = $client->request('GET', 'https://simplero.com/api/v1/products.json', [
	'auth' => [
		$apiKey,
		''
	],
	'headers' => [
		'User-Agent' => 'simplero2sqlite',
		'accept' => 'application/json'
	],
]);

$products = json_decode($response->getBody(), true);

$db = new SQLite3('simplero.sqlite', SQLITE3_OPEN_CREATE | SQLITE3_OPEN_READWRITE);

// Errors are emitted as warnings by default, enable proper error handling.
$db->enableExceptions(true);

$db->query('DROP TABLE IF EXISTS "products"');

// Create a table.
$db->query('CREATE TABLE IF NOT EXISTS "products" (
    "id" INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
    "product_id" INTEGER,
    "name" VARCHAR,
    "amount" DECIMAL,
	"category" VARCHAR
)');

$db->exec('BEGIN');
foreach ($products as $product)
{
    if (!isset($byCategories[$product["id"]]))
    {
        // Default to Other, doesn't work for everyone
        $byCategories[$product["id"]] = 'Other';
    }
    $db->query('INSERT INTO "products" ("product_id", "name", "amount", "category")
    VALUES (' . $product["id"] . ', "' . $product["name"] . '", ' . $product["received_price_cents"]/100 . ', "' . $byCategories[$product["id"]] . '")');
}
$db->exec('COMMIT');

$done = 0;
$page = 0;

$data = [];

while (!$done)
{
    $response = $client->request('GET', 'https://simplero.com/api/v1/invoices.json', [
            'auth' => [
                    $apiKey,
                    ''
            ],
            'form_params' => [
                'page' => $page
            ],
            'headers' => [
                    'User-Agent' => 'simple2sqlite',
                    'accept' => 'application/json'
            ],
    ]);

    $invoices = json_decode($response->getBody(), true);
    $i = 0;
    while ($i < count($invoices))
    {
        $data[] = $invoices[$i];
        $i++;
    }
    if (count($invoices))
    {
        $page++;
    }
    else
    {
        $done = 1;
        break;
    }
}

$db->query('DROP TABLE IF EXISTS "invoices"');

$db->query('CREATE TABLE IF NOT EXISTS "invoices" (
    "id" INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
    "user_id" INTEGER,
    "product_id" INTEGER,
    "email" VARCHAR,
    "name" VARCHAR,
    "amount" DECIMAL,
    "time" DATETIME
)');

$db->exec('BEGIN');
foreach ($data as $invoice)
{
    $db->query('INSERT INTO "invoices" ("user_id", "product_id", "email", "name", "amount", "time")
        VALUES (' . $invoice["purchase"]["id"] . ', ' . $invoice["product"]["id"] . ', "' . 
        $invoice["purchase"]["email"] . '", "' . $invoice["purchase"]["name"] . '", ' . 
        $invoice["their_price"] . ', "' . $invoice["created_at"] . '")');
}
$db->exec('COMMIT');

$db->close();