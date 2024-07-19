<?php

namespace antogkou\ApexClient;

use App\Exceptions\SalesforceApiException;
use Exception;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ApexClient
{
    private const TOKEN_CACHE_KEY = 'salesforceToken';

    private const TOKEN_CACHE_TTL = 28800; // 8 hours

    private ?string $userEmail;

    private ?Request $request;

    public function __construct(?string $userEmail = null, ?Request $request = null)
    {
        $this->userEmail = $userEmail;
        $this->request = $request ?? request();
    }

    public function setEmail(string $email): self
    {
        $this->userEmail = $email;

        return $this;
    }

    /**
     * @throws RequestException
     * @throws SalesforceApiException
     */
    public function get(string $url, array $query = [], array $additionalHeaders = []): Response
    {
        return $this->sendRequest(method: 'get', url: $url, query: $query, additionalHeaders: $additionalHeaders);
    }

    /**
     * @throws RequestException
     * @throws SalesforceApiException
     */
    private function sendRequest(
        string $method,
        string $url,
        array $query = [],
        array $data = [],
        array $additionalHeaders = [],
    ): Response {
        $request = $this->request($additionalHeaders);

        try {
            $fullUrl = $this->buildUrl($url, $query);

            if ($method === 'get') {
                $response = $request->$method($fullUrl);
            } else {
                $response = $request->$method($fullUrl, $data);
            }

            if ($response->unauthorized()) {
                cache()->forget(self::TOKEN_CACHE_KEY);
                $request = $this->request($additionalHeaders); // Get a new request with the new token

                $response = $request->$method($fullUrl, $data);
            }

            if ($response->failed()) {
                $errorBody = $response->json() ?? $response->body();
                $routeInfo = $this->getRouteInfo();

                Log::error('Salesforce API Error', [
                    'method' => $method,
                    'url' => $fullUrl,
                    'data' => $data,
                    'status' => $response->status(),
                    'response' => $errorBody,
                    'laravel_route' => $routeInfo,
                ]);

                throw new SalesforceApiException(
                    ($errorBody['message'] ?? 'Unknown Salesforce API error'),
                    $response->status(),
                    null,
                    [
                        'message' => $errorBody['message'] ?? 'Unknown Salesforce API error',
                        'method' => $method,
                        'url' => $fullUrl,
                        'data' => $data,
                        'status' => $response->status(),
                        'response' => $errorBody,
                        'laravel_route' => $routeInfo,
                    ]
                );
            }

            return $response;
        } catch (Exception $e) {
            if (! $e instanceof SalesforceApiException) {
                $routeInfo = $this->getRouteInfo();
                Log::error('Salesforce API Request Failed', [
                    'method' => $method,
                    'url' => $url,
                    'data' => $data,
                    'error' => $e->getMessage(),
                    'laravel_route' => $routeInfo,
                ]);
            }
            throw $e;
        }
    }

    /**
     * @throws SalesforceApiException
     */
    private function request(array $additionalHeaders = []): PendingRequest
    {
        $headers = [
            'x-app-uuid' => config('salesforce.app_uuid'),
            'x-api-key' => config('salesforce.app_key'),
            'x-user-email' => $this->userEmail ?? auth()->user()->email,
        ];
        $mergedHeaders = array_merge($headers, $additionalHeaders);

        return Http::baseUrl($this->baseUrl())
            ->withToken($this->token())
            ->withHeaders($mergedHeaders)
            ->withOptions($this->options());
    }

    private function baseUrl(): string
    {
        $apexUri = config('salesforce.apex_uri');

        if (! Str::contains($apexUri, '.com:8443') && Arr::exists($this->options(), 'curl')) {
            $apexUri = Str::replaceFirst('.com', '.com:8443', $apexUri);
        }

        return rtrim($apexUri, '/');
    }

    private function options(): array
    {
        if (config('salesforce.certificate') && config('salesforce.certificate_key')) {
            return [
                'curl' => [
                    CURLOPT_SSLCERT => storage_path('certificates').DIRECTORY_SEPARATOR.config('salesforce.certificate'),
                    CURLOPT_SSLKEY => storage_path('certificates').DIRECTORY_SEPARATOR.config('salesforce.certificate_key'),
                    CURLOPT_VERBOSE => config('app.debug'),
                ],
            ];
        }

        return [];
    }

    private function token(): string
    {
        try {
            return cache()->remember(self::TOKEN_CACHE_KEY, self::TOKEN_CACHE_TTL, function () {
                return $this->refreshToken();
            });
        } catch (Exception $e) {
            Log::error('Failed to obtain Salesforce token', ['error' => $e->getMessage()]);
            throw new SalesforceApiException($e->getMessage(), 500, $e);
        }
    }

    /**
     * @throws SalesforceApiException
     */
    private function refreshToken(): string
    {
        $response = Http::asForm()->post(config('salesforce.token_uri'), [
            'grant_type' => 'password',
            'client_id' => config('salesforce.client_id'),
            'client_secret' => config('salesforce.client_secret'),
            'username' => config('salesforce.username'),
            'password' => config('salesforce.password'),
        ]);

        if ($response->successful()) {
            $token = $response->json('access_token');
            if (is_string($token) && ! empty($token)) {
                return $token;
            }
        }

        // If we reach here, either the response was not successful or the token was invalid
        $errorMessage = $response->successful()
            ? 'Invalid token received from Salesforce'
            : 'Failed to refresh token: '.$response->body();

        throw new SalesforceApiException(
            $errorMessage,
            $response->status() ?: 500,
            null,
            ['response' => $response->json()]
        );
    }

    /**
     * @throws SalesforceApiException|RequestException
     */
    public function post(string $url, array $data, array $additionalHeaders = []): Response
    {
        return $this->sendRequest(method: 'post', url: $url, data: $data, additionalHeaders: $additionalHeaders);
    }

    private function buildUrl(string $url, array $query = []): string
    {
        $baseUrl = $this->baseUrl();
        $fullUrl = Str::startsWith($url, ['http://', 'https://']) ? $url : $baseUrl.'/'.ltrim($url, '/');

        $parsedUrl = parse_url($fullUrl);

        $existingQuery = [];
        if (isset($parsedUrl['query'])) {
            parse_str($parsedUrl['query'], $existingQuery);
        }

        $mergedQuery = array_merge($existingQuery, $query);

        $scheme = isset($parsedUrl['scheme']) ? $parsedUrl['scheme'].'://' : '';
        $host = $parsedUrl['host'] ?? '';
        $path = $parsedUrl['path'] ?? '';
        $newQuery = ! empty($mergedQuery) ? '?'.http_build_query($mergedQuery) : '';

        return $scheme.$host.$path.$newQuery;
    }

    private function getRouteInfo(): array
    {
        $route = $this->request->route();

        return [
            'uri' => $this->request->path(),
            'name' => $route ? $route->getName() : null,
            'action' => $route ? $route->getActionName() : null,
        ];
    }

    /**
     * @throws RequestException
     * @throws SalesforceApiException
     */
    public function put(string $url, array $data, array $additionalHeaders = []): Response
    {
        return $this->sendRequest(method: 'put', url: $url, data: $data, additionalHeaders: $additionalHeaders);
    }

    /**
     * @throws RequestException
     * @throws SalesforceApiException
     */
    public function patch(string $url, array $data, array $additionalHeaders = []): Response
    {
        return $this->sendRequest(method: 'patch', url: $url, data: $data, additionalHeaders: $additionalHeaders);
    }

    /**
     * @throws RequestException
     * @throws SalesforceApiException
     */
    public function delete(string $url, array $additionalHeaders = []): Response
    {
        return $this->sendRequest(method: 'delete', url: $url, data: [], additionalHeaders: $additionalHeaders);
    }
}
