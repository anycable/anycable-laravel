<?php

namespace AnyCable\Laravel;

use Exception;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\RequestException;

class Client
{
    /**
     * The HTTP client instance.
     */
    protected HttpClient $httpClient;

    /**
     * The AnyCable configuration.
     */
    protected array $config;

    protected string $streamsKey;

    protected string $broadcastUrl;

    protected string $broadcastKey;

    /**
     * Create a new AnyCable client instance.
     */
    public function __construct(array $config, ?HttpClient $httpClient = null)
    {
        $this->config = $config;
        $this->httpClient = $httpClient ?? new HttpClient;

        if (isset($config['streams_key'])) {
            $this->streamsKey = $config['streams_key'];
        } elseif (isset($config['secret'])) {
            $this->streamsKey = $config['secret'];
        }

        if (isset($config['broadcast_key'])) {
            $this->broadcastKey = $config['broadcast_key'];
        } elseif (isset($config['secret'])) {
            $this->broadcastKey = hash_hmac('sha256', 'broadcast-cable', $config['secret']);
        }

        // Make sure the defaults match the AnyCable server's defaults
        if (isset($config['http_broadcast_url'])) {
            $this->broadcastUrl = $config['http_broadcast_url'];
        } elseif (empty($this->broadcastKey)) {
            $this->broadcastUrl = 'http://localhost:8090/_broadcast';
        } else {
            $this->broadcastUrl = 'http://localhost:8080/_broadcast';
        }
    }

    /**
     * Sign a stream name for authentication.
     */
    public function signStream(string $streamName): string
    {
        if (empty($this->streamsKey)) {
            throw new Exception('AnyCable secret is not configured');
        }

        $encoded = base64_encode(json_encode($streamName));
        $digest = hash_hmac('sha256', $encoded, $this->streamsKey);

        return $encoded.'--'.$digest;
    }

    /**
     * Broadcast data to a specific stream.
     */
    public function broadcast(string $stream, array $data, array $options = []): array
    {
        $broadcastUrl = $this->broadcastUrl;
        $headers = $this->getHeaders();
        $timeout = $options['timeout'] ?? $this->config['timeout'] ?? 5;

        $payload = [
            'stream' => $stream,
            'data' => is_string($data) ? $data : json_encode($data),
        ];

        try {
            $response = $this->httpClient->post($broadcastUrl, [
                'headers' => $headers,
                'json' => $payload,
                'timeout' => $timeout,
            ]);

            $statusCode = $response->getStatusCode();
            $responseBody = $response->getBody()->getContents();

            return [
                'status' => $statusCode,
                'success' => $statusCode === 200,
                'response' => $responseBody,
            ];
        } catch (RequestException $e) {
            if ($e->hasResponse() && $e->getResponse()->getStatusCode() === 400) {
                return [
                    'status' => 400,
                    'success' => false,
                    'response' => $e->getResponse()->getBody()->getContents(),
                ];
            }

            throw new Exception('Failed to broadcast to AnyCable: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Broadcast an event with data to a specific stream.
     */
    public function broadcastEvent(string $stream, string $event, array $payload = [], array $options = []): array
    {
        $data = [
            'event' => $event,
            'data' => $payload,
        ];

        return $this->broadcast($stream, $data, $options);
    }

    /**
     * Broadcast to multiple streams at once.
     */
    public function broadcastToMany(array $streams, array $data, array $options = []): array
    {
        $results = [];

        foreach ($streams as $stream) {
            $results[$stream] = $this->broadcast($stream, $data, $options);
        }

        return $results;
    }

    /**
     * Get the headers for the broadcast request.
     */
    protected function getHeaders(): array
    {
        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];

        if (! empty($this->broadcastKey)) {
            $headers['Authorization'] = 'Bearer '.$this->broadcastKey;
        }

        return $headers;
    }
}
