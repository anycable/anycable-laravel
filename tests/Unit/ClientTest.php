<?php

namespace AnyCable\Laravel\Tests\Unit;

use AnyCable\Laravel\Client;
use AnyCable\Laravel\Tests\TestCase;
use Exception;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Mockery;

class ClientTest extends TestCase
{
    protected array $config = [
        'secret' => 'testing_secret',
        'http_broadcast_url' => 'http://localhost:8090/_broadcast',
    ];

    protected array $container = [];
    protected MockHandler $mockHandler;
    protected HttpClient $mockHttpClient;

    protected function setUp(): void
    {
        parent::setUp();

        $this->container = [];
        $this->mockHandler = new MockHandler;
        $history = Middleware::history($this->container);

        $handlerStack = HandlerStack::create($this->mockHandler);
        $handlerStack->push($history);

        $this->mockHttpClient = new HttpClient(['handler' => $handlerStack]);
    }

    public function test_sign_stream()
    {
        $client = new Client($this->config);
        $streamName = 'private-channel';

        $signed = $client->signStream($streamName);

        // Should be a base64 encoded string followed by -- and a digest
        $this->assertStringContainsString('--', $signed);

        // Split the string to verify parts
        [$encoded, $digest] = explode('--', $signed);

        // Verify encoded is valid base64
        $decoded = base64_decode($encoded, true);
        $this->assertNotFalse($decoded);

        // Verify decoded is the original stream name
        $this->assertEquals($streamName, json_decode($decoded));

        // Verify digest has correct length (sha256 produces 64 character hex strings)
        $this->assertEquals(64, strlen($digest));
    }

    public function test_sign_stream_fails_without_secret()
    {
        $client = new Client(['broadcast_url' => 'http://localhost:8090/_broadcast']);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('AnyCable secret is not configured');

        $client->signStream('private-channel');
    }

    public function test_broadcast()
    {
        // Mock a successful response
        $this->mockHandler->append(new Response(200, [], '{"status":"ok"}'));

        $client = new Client($this->config, $this->mockHttpClient);
        $result = $client->broadcast('test-channel', ['foo' => 'bar']);

        // Verify response
        $this->assertTrue($result['success']);
        $this->assertEquals(200, $result['status']);
        $this->assertEquals('{"status":"ok"}', $result['response']);

        // Verify request
        $this->assertCount(1, $this->container);
        $request = $this->container[0]['request'];

        // Check URL
        $this->assertEquals('http://localhost:8090/_broadcast', (string) $request->getUri());

        // Check method
        $this->assertEquals('POST', $request->getMethod());

        // Check headers
        $this->assertEquals('application/json', $request->getHeaderLine('Content-Type'));
        $this->assertEquals('application/json', $request->getHeaderLine('Accept'));

        // Check bearer token is set with correct value
        $expectedToken = hash_hmac('sha256', 'broadcast-cable', 'testing_secret');
        $this->assertEquals('Bearer ' . $expectedToken, $request->getHeaderLine('Authorization'));

        // Check payload
        $payload = json_decode($this->container[0]['request']->getBody(), true);
        $this->assertEquals('test-channel', $payload['stream']);
        $this->assertEquals(json_encode(['foo' => 'bar']), $payload['data']);
    }

    public function test_broadcast_event()
    {
        // Mock a successful response
        $this->mockHandler->append(new Response(200, [], '{"status":"ok"}'));

        $client = new Client($this->config, $this->mockHttpClient);
        $result = $client->broadcastEvent('test-channel', 'test-event', ['foo' => 'bar']);

        // Verify response
        $this->assertTrue($result['success']);

        // Verify request
        $this->assertCount(1, $this->container);

        // Check payload
        $payload = json_decode($this->container[0]['request']->getBody(), true);
        $this->assertEquals('test-channel', $payload['stream']);

        // Decode the data field which should contain event and data
        $data = json_decode($payload['data'], true);
        $this->assertEquals('test-event', $data['event']);
        $this->assertEquals(['foo' => 'bar'], $data['data']);
    }

    public function test_broadcast_to_many()
    {
        // Mock multiple successful responses
        $this->mockHandler->append(
            new Response(200, [], '{"status":"ok"}'),
            new Response(200, [], '{"status":"ok"}')
        );

        $client = new Client($this->config, $this->mockHttpClient);
        $result = $client->broadcastToMany(
            ['channel-1', 'channel-2'],
            ['foo' => 'bar']
        );

        // Verify response structure
        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertArrayHasKey('channel-1', $result);
        $this->assertArrayHasKey('channel-2', $result);

        // Verify each channel response
        $this->assertTrue($result['channel-1']['success']);
        $this->assertTrue($result['channel-2']['success']);

        // Verify request count
        $this->assertCount(2, $this->container);

        // Check payloads
        $payload1 = json_decode($this->container[0]['request']->getBody(), true);
        $payload2 = json_decode($this->container[1]['request']->getBody(), true);

        $this->assertEquals('channel-1', $payload1['stream']);
        $this->assertEquals('channel-2', $payload2['stream']);
    }

    public function test_broadcast_with_failure()
    {
        // Mock a failure response
        $this->mockHandler->append(new \GuzzleHttp\Exception\RequestException(
            'Client error: `POST http://localhost:8090/_broadcast` resulted in a `400 Bad Request` response: {"error":"Bad request"}',
            new \GuzzleHttp\Psr7\Request('POST', 'http://localhost:8090/_broadcast'),
            new Response(400, [], '{"error":"Bad request"}')
        ));

        $client = new Client($this->config, $this->mockHttpClient);
        $result = $client->broadcast('test-channel', ['foo' => 'bar']);

        // Verify response
        $this->assertFalse($result['success']);
        $this->assertEquals(400, $result['status']);
        $this->assertEquals('{"error":"Bad request"}', $result['response']);
    }

    public function test_broadcast_with_connection_failure()
    {
        // Mock the client directly using Mockery
        $mockHttpClient = Mockery::mock(HttpClient::class);
        $mockHttpClient->shouldReceive('post')
            ->once()
            ->andThrow(new \GuzzleHttp\Exception\ConnectException(
                'Connection refused',
                new \GuzzleHttp\Psr7\Request('POST', 'http://localhost:8090/_broadcast')
            ));

        $client = new Client($this->config, $mockHttpClient);

        $this->expectException(Exception::class);

        // No need to check the exact message format - just make sure it throws the exception
        $client->broadcast('test-channel', ['foo' => 'bar']);
    }

    public function test_custom_broadcast_key()
    {
        $customConfig = [
            'broadcast_key' => 'custom_key',
            'broadcast_url' => 'http://localhost:8090/_broadcast',
        ];

        $this->mockHandler->append(new Response(200, [], '{"status":"ok"}'));

        $client = new Client($customConfig, $this->mockHttpClient);
        $client->broadcast('test-channel', ['foo' => 'bar']);

        // Verify custom broadcast key was used
        $this->assertCount(1, $this->container);
        $request = $this->container[0]['request'];
        $this->assertEquals('Bearer custom_key', $request->getHeaderLine('Authorization'));
    }

    public function test_custom_streams_key()
    {
        $customConfig = [
            'streams_key' => 'custom_streams_key',
            'broadcast_url' => 'http://localhost:8090/_broadcast',
        ];

        $client = new Client($customConfig);
        $signed = $client->signStream('private-channel');

        // Verify signed string contains the correct signature
        [$encoded, $digest] = explode('--', $signed);

        // Re-create the expected digest
        $expectedDigest = hash_hmac('sha256', $encoded, 'custom_streams_key');

        $this->assertEquals($expectedDigest, $digest);
    }
}
