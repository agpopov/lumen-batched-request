<?php

namespace Dvanderburg\BatchedRequest;

use Flow\JSONPath\JSONPath;
use Flow\JSONPath\JSONPathException;
use Illuminate\Http\Request;
use JetBrains\PhpStorm\ArrayShape;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Represents a batch of requests to execute
 * Given an array describing requests, executes each request and builds an array of responses
 * The original request initiating the batch can be used to share headers, cookies, files, etc.
 *
 * See BatchedRequestServiceProvider for implementation details
 *
 */
class BatchedRequest
{

    // array describing the request to create for the batch
    private array $batch = [];

    // the original request initiating the batch
    private ?Request $request = null;

    // array of responses from the batch (http status code, response body)
    private array $responses = [];

    /**
     * Create the batch request
     * @param  array  $batch  Array of batched requests
     *                                                relative_url => The endpoint (/user/1234, /item/2345, etc.)
     *                                                method => HTTP method (post, get, options, etc.)
     *                                                name => A name to give the request if using dependencies
     * @param  Request|null  $request  The original request initiating the batch, used to share headers, files, cookies, etc.
     */
    public function __construct(array $batch, Request $request = null)
    {
        $this->batch = $batch;
        $this->request = is_null($request) ? Request::capture() : $request;
    }

    /**
     * Executes the batch of requests
     */
    public function execute(): void
    {
        foreach ($this->batch as $index => $batchedRequest) {
            if (!isset($batchedRequest['relative_url'])) {
                throw new HttpException(400, "A relative URL was not provided for the request at index ".$index.".");
            }

            $requestName = !empty($batchedRequest['name']) ? $batchedRequest['name'] : count($this->responses);

            $this->parseURLTokens($batchedRequest);

            $this->responses[$requestName] = $this->handleBatchedRequest($batchedRequest);
        }
    }

    /**
     * Accessor for the array of responses generated when the batch is executed
     * @return array    Indexed by request name, or numerically if no names were provided
     *                     request-one => [
     *                        code: HTTP status code (200, 404, etc.)
     *                        body: The response body of the text (html, json, etc.)
     *                     ]
     *
     */
    public function getResponses(): array
    {
        return $this->responses;
    }

    /**
     * Handles an individual batched request
     * @param  array  $batchedRequest  Single request from the "batch" array
     * @return array                 Response for the request
     */
    private function handleBatchedRequest(array $batchedRequest): array
    {
        $method = $batchedRequest['method'] ?? "GET";
        $parameters = $this->getParameters($batchedRequest);

        $subRequest = SymfonyRequest::create(
            $batchedRequest['relative_url'],
            $method,
            $parameters,
            // cookies, files, and headers are all shared from the parent request which initiated the batch
            // this allows sub-requests to access uploaded files, access authentication cookies, headers, etc.
            $this->request->cookies->all(),
            $this->request->files->all(),
            $this->request->server->all()
        );

        $subRequestResponse = app()->handle($subRequest);

        $response = array();
        $response['code'] = $subRequestResponse->getStatusCode();
        $response['body'] = $subRequestResponse->getContent();

        // json responses should remain as arrays until the final response from the ENTIRE batch is encoded
        if ($subRequestResponse->headers->get('Content-Type') === 'application/json') {
            $response['body'] = json_decode($response['body'], true);
        }

        return $response;
    }

    /**
     * @throws JSONPathException
     */
    private function parseURLTokens(&$batchedRequest): void
    {
        // retrieve any tokens in the relative URL
        $urlTokens = $this->getRelativeURLTokens($batchedRequest['relative_url']);

        // if there were tokens in the url, parse/replace them based on responses to other requests in the batch
        foreach ($urlTokens as $urlToken) {
            // ensure the dependency request exists
            if (!isset($this->responses[$urlToken['dependency']])) {
                throw new HttpException(400,
                    "The request to '".$batchedRequest['relative_url']."' is dependant on the request '".$urlToken['dependency']."', but is not present in the batch.");
            }

            // load the dependency's response
            $dependencyResponse = $this->responses[$urlToken['dependency']];

            // ensure the dependency request was successful
            if ($dependencyResponse['code'] !== 200) {
                throw new HttpException(400,
                    "The request to '".$batchedRequest['relative_url']."' could not be completed because its dependant request '".$urlToken['dependency']."' failed.");
            }

            $jsonPath = new JSONPath($dependencyResponse['body']);
            $result = $jsonPath->find($urlToken['json_path']);

            // parse the tokens in the relative url using the result of the json path expression
            $batchedRequest['relative_url'] = str_replace($urlToken['url_token'], implode(',', $result->getData()), $batchedRequest['relative_url']);
        }
    }

    private function getParameters($batchedRequest): array
    {
        $parameters = array();

        $parameters = array_merge($parameters, $this->getPayloadParameters($batchedRequest));
        return array_merge($parameters, $this->getQueryParameters($batchedRequest));
    }

    private function getQueryParameters($batchedRequest): array
    {
        $parameters = array();

        // divide the relative url into sections within an array
        //	the first element will be the resource, the second the query string
        $urlSections = explode('?', $batchedRequest['relative_url']);

        // check if a valid, non-empty query string was sent
        if (count($urlSections) == 2 && !empty($urlSections[1])) {
            // retrieve the query string portion of the relative url
            $queryString = array_pop($urlSections);

            // manually parse the query string into an associative array of variables
            //	avoids using PHP's parse_str function due to potential security concerns
            //	parse_str sets the query string parameters as variables in local scope, allowing malicious users to access global vars
            //	for example: sending ?parameters=broken would change what this function returns
            foreach (explode('&', $queryString) as $queryStringVariable) {
                $queryStringVariableParts = explode('=', $queryStringVariable);
                $parameters[$queryStringVariableParts[0]] = $queryStringVariableParts[1];
            }
        }

        return $parameters;
    }

    private function getPayloadParameters($batchedRequest): array
    {
        $parameters = array();

        if (isset($batchedRequest["body"]) && isset($batchedRequest["content-type"])) {
            // Check the content-type and see how it should be parsed
            if ($batchedRequest["content-type"] == "application/json" && is_array($batchedRequest["body"])) {
                // If the body is in json it would automatically be parsed when being passed through Request
                return $batchedRequest["body"];
            } else {
                if ($batchedRequest["content-type"] == "application/x-www-form-urlencoded" && is_string($batchedRequest["body"])) {
                    // Parse the body by exploding it into chunks
                    $explodedParameters = explode('&', $batchedRequest["body"]);
                    foreach ($explodedParameters as $parameterChunk) {
                        $parameter = explode('=', $parameterChunk);
                        $name = urldecode($parameter[0]);
                        $value = (isset ($parameter[1]) ? urldecode($parameter[1]) : null);
                        $parameters[$name] = $value;
                    }
                }
            }
        }

        return $parameters;
    }

    private function getRelativeURLTokens($relativeURL): array
    {
        // array to return all tokens with
        //	populated by parsing the relative URL for tokens and then formatted with this.getTokenDataFromTokenString
        $tokens = array();

        // loop until all tokens have been parsed
        //	tokens are contained within braces, look for an opening brace to identify a token
        //	$relativeURL is modified within the loot to remove each token as it is found
        do {
            // find an occurrence of a token
            //	this could be improved if nested tokens are ever desired, such as: {result=something:$.*.{result=something-else:$.id_attribute}}
            //	for now, it will not parse the nested token, resulting in fewer tokens with some containing the '}' character inside the token
            $urlTokenStart = strpos($relativeURL, '{');
            $urlTokenEnd = strpos($relativeURL, '}');
            $urlTokenLength = $urlTokenEnd - $urlTokenStart + 1;
            $urlToken = substr($relativeURL, $urlTokenStart, $urlTokenLength);

            if ($urlTokenStart !== false) {
                // remove the occurrence of this url token to continue parsing
                $relativeURL = str_replace($urlToken, "", $relativeURL);

                // add token data to return
                $tokens[] = $this->getTokenDataFromTokenString($urlToken);
            }
        } while ($urlTokenStart !== false);

        // final array of tokens
        //	will be blank if there were no tokens
        //	populated with arrays containing token data, as formatted by this.getTokenDataFromTokenString
        return $tokens;
    }

    #[ArrayShape(['url_token' => "", 'type' => "string", 'dependency' => "string", 'json_path' => "string"])]
    private function getTokenDataFromTokenString($urlToken): array
    {
        // remove the braces from the token (remove first and last character)
        $tokenBody = substr($urlToken, 1, strlen($urlToken) - 2);

        // position of the colon, delimiter for the token
        $colonPosition = strpos($tokenBody, ':');

        // position of the equals sign, delimiter for the token type and request name
        $equalsPotions = strpos($tokenBody, '=');

        // get the type of the token
        //	everything from the beginning of the token body up to the position of the equals, example: "result=named-query:$.data.*.id" yields "result"
        $tokenType = substr($tokenBody, 0, $equalsPotions);

        // name of the dependency request, example: "result=named-query:$.data.*.id" yields "named-query"
        $dependencyName = substr($tokenBody, $equalsPotions + 1, $colonPosition - strlen($tokenType) - 1);

        // get the JSON path portion of the token
        //	everything past the position of the colon until the end of the token, example: "result=named-query:$.data.*.id" yields "$.data.*.id"
        $tokenJSONPath = substr($tokenBody, $colonPosition + 1);

        // format the token data and return
        return array(
            'url_token' => $urlToken,
            'type' => $tokenType,
            'dependency' => $dependencyName,
            'json_path' => $tokenJSONPath,
        );
    }

}