<?php

namespace Dvanderburg\BatchedRequest;

use Flow\JSONPath\JSONPathException;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;

/**
 * Service provider to accommodate batched requests/sub-requests
 *
 * Integration
 *        Register the service provider in app.php:
 *            $app->register(Dvanderburg\BatchedRequest\BatchedRequestServiceProvider::class);
 *
 * Implementation
 *            POST /batch HTTP/1.1
 *            Content-Type: application/json
 *            batch: [
 *                { "method": "GET",    "relative_url": "/products/1?one=1&two=2&three=3" },
 *                { "method": "GET",    "relative_url": "/users/?ids=larry,jill,sally" },
 *                { "method": "POST",    "name": "create-user", "relative_url": "/users/?username=john&password=admin" },
 *            ]
 *
 *        The POST request above will respond with an array of JSON responses
 *        The method and relative url attributes are required, however, a name is optional
 *            If a name was provided, the returned response will be indexed with that name
 *        Any headers, files, and cookies send with the request will be shared with all sub-requests
 *
 *
 *        Specifying dependencies with JSON Path syntax
 *            POST /batch HTTP/1.1
 *            Content-Type: application/json
 *            batch: [
 *                { "method": "GET",    "name": "get-user-products", "relative_url": "/user_products/john" },
 *                { "method": "GET",    "relative_url": "/products/?ids={result=get-user-products:$.*.product_id}" },
 *            ]
 *
 *        The POST request above will process the "get-user-products" request first, then supply the result to the second request in the path
 *        The JSON Path syntax will insert an array containing all retrieved product IDs from the first request
 *
 */
class BatchedRequestServiceProvider extends ServiceProvider
{

    /**
     * Attaches the route "batch" to handle batched requests
     * Any HTTP POST requests to /batch will execute as a batched request
     * @throws BindingResolutionException|JSONPathException
     */
    public function boot(): void
    {
        $this->app->make('router')->post('batch', function (Request $request) {
            $batch = $request->get('batch', []);

            $batchedRequest = new BatchedRequest($batch, $request);

            $batchedRequest->execute();

            return $batchedRequest->getResponses();
        });
    }

    public function register(): void
    {
        $this->loadJsonTranslationsFrom(__DIR__.'/../lang');
    }
}
