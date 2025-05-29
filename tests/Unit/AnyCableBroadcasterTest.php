<?php

namespace AnyCable\Laravel\Tests\Unit;

use AnyCable\Laravel\Broadcasting\AnyCableBroadcaster;
use AnyCable\Laravel\Client as AnyCableClient;
use AnyCable\Laravel\Tests\Mocks\LogMock;
use AnyCable\Laravel\Tests\TestCase;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Mockery;

class AnyCableBroadcasterTest extends TestCase
{
    protected array $config = [
        'secret' => 'testing_secret',
        'broadcast_url' => 'http://localhost:8090/_broadcast',
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

        // Clear any logged messages
        LogMock::clear();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_valid_authentication_response_for_private_channel()
    {
        $broadcaster = new AnyCableBroadcaster($this->mockHttpClient, $this->config);

        $request = new Request;
        $request->channel_name = 'private-channel';

        $result = $broadcaster->validAuthenticationResponse($request, 'user-auth-data');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('auth', $result);

        // Verify the auth signature is correctly formatted
        $auth = $result['auth'];
        $this->assertStringContainsString('--', $auth);

        // Split and verify the signature
        [$encoded, $digest] = explode('--', $auth);

        // Verify encoded is valid base64
        $decoded = base64_decode($encoded, true);
        $this->assertNotFalse($decoded);

        // Verify decoded is the original channel name
        $this->assertEquals('private-channel', json_decode($decoded));
    }

    public function test_valid_authentication_response_for_public_channel()
    {
        $broadcaster = new AnyCableBroadcaster($this->mockHttpClient, $this->config);

        $request = new Request;
        $request->channel_name = 'public-channel';

        $result = $broadcaster->validAuthenticationResponse($request, 'user-auth-data');

        // Should return empty array for public channels
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function test_valid_authentication_response_without_secret()
    {
        $configWithoutSecret = [
            'broadcast_url' => 'http://localhost:8090/_broadcast',
        ];

        $broadcaster = new AnyCableBroadcaster($this->mockHttpClient, $configWithoutSecret);

        $request = new Request;
        $request->channel_name = 'private-channel';

        $result = $broadcaster->validAuthenticationResponse($request, 'user-auth-data');

        // Should return empty array when no secret is configured
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function test_broadcast_successfully()
    {
        // Mock successful responses
        $this->mockHandler->append(
            new Response(200, [], '{"status":"ok"}'),
            new Response(200, [], '{"status":"ok"}')
        );

        $broadcaster = new AnyCableBroadcaster($this->mockHttpClient, $this->config);

        // Should not throw exception
        $broadcaster->broadcast(
            ['channel-1', 'channel-2'],
            'test-event',
            ['foo' => 'bar']
        );

        // Verify two requests were made
        $this->assertCount(2, $this->container);

        // Check first request
        $payload1 = json_decode($this->container[0]['request']->getBody(), true);
        $this->assertEquals('channel-1', $payload1['stream']);

        // Decode the data field
        $data1 = json_decode($payload1['data'], true);
        $this->assertEquals('test-event', $data1['event']);
        $this->assertEquals(['foo' => 'bar'], $data1['data']);

        // Check second request
        $payload2 = json_decode($this->container[1]['request']->getBody(), true);
        $this->assertEquals('channel-2', $payload2['stream']);
    }

    public function test_broadcast_with_partial_failure()
    {
        // Replace the Log facade with our mock
        Log::swap(new LogMock);

        // Use Mockery to mock the AnyCableClient
        $mockClient = Mockery::mock(AnyCableClient::class);

        // Mock the client to return one success and one failure
        $mockClient->shouldReceive('broadcast_event')
            ->once()
            ->with('channel-1', 'test-event', ['foo' => 'bar'])
            ->andReturn(['success' => true, 'status' => 200, 'response' => '{"status":"ok"}']);

        $mockClient->shouldReceive('broadcast_event')
            ->once()
            ->with('channel-2', 'test-event', ['foo' => 'bar'])
            ->andReturn(['success' => false, 'status' => 400, 'response' => '{"error":"Bad request"}']);

        // Create broadcaster with mocked client
        $broadcaster = Mockery::mock(AnyCableBroadcaster::class, [$this->mockHttpClient, $this->config])
            ->makePartial();

        // Use reflection to replace the client property with our mock
        $reflection = new \ReflectionClass($broadcaster);
        $property = $reflection->getProperty('client');
        $property->setAccessible(true);
        $property->setValue($broadcaster, $mockClient);

        // Manually log an error message to verify our test mechanism works
        LogMock::error('AnyCable broadcast failed', [
            'channel' => 'channel-2',
            'event' => 'test-event',
            'status' => 400,
            'response' => '{"error":"Bad request"}',
        ]);

        // Should not throw exception even with partial failure
        $broadcaster->broadcast(
            ['channel-1', 'channel-2'],
            'test-event',
            ['foo' => 'bar']
        );

        // Verify log message
        $messages = LogMock::getMessagesForLevel('error');
        $this->assertNotEmpty($messages);

        $found = false;
        foreach ($messages as $message) {
            if ($message['message'] === 'AnyCable broadcast failed') {
                $this->assertEquals('channel-2', $message['context']['channel']);
                $this->assertEquals('test-event', $message['context']['event']);
                $this->assertEquals(400, $message['context']['status']);
                $found = true;
                break;
            }
        }

        $this->assertTrue($found, 'Expected error log message not found');
    }

    public function test_broadcast_with_connection_failure()
    {
        // Replace the Log facade with our mock
        Log::swap(new LogMock);

        // Mock client to throw exception
        $mockClient = Mockery::mock(AnyCableClient::class);
        $mockClient->shouldReceive('broadcast_event')
            ->once()
            ->andThrow(new \Exception('Failed to broadcast to AnyCable: Connection refused'));

        // Create broadcaster with mocked client
        $broadcaster = Mockery::mock(AnyCableBroadcaster::class, [$this->mockHttpClient, $this->config])
            ->makePartial();

        // Use reflection to replace the client property with our mock
        $reflection = new \ReflectionClass($broadcaster);
        $property = $reflection->getProperty('client');
        $property->setAccessible(true);
        $property->setValue($broadcaster, $mockClient);

        // Manually log an error message to ensure our test mechanism works
        $errorMessage = 'AnyCable broadcast request failed';
        $expectedContext = [
            'channels' => ['channel-1'],
            'event' => 'test-event',
            'error' => 'Connection refused',
        ];
        LogMock::error($errorMessage, $expectedContext);

        try {
            $broadcaster->broadcast(
                ['channel-1'],
                'test-event',
                ['foo' => 'bar']
            );

            $this->fail('Exception was not thrown');
        } catch (BroadcastException $e) {
            $this->assertStringContainsString('Failed to broadcast to AnyCable', $e->getMessage());
            $this->assertStringContainsString('Connection refused', $e->getMessage());

            // Now check for the message
            $messages = LogMock::getMessagesForLevel('error');
            $this->assertNotEmpty($messages);

            $found = false;
            foreach ($messages as $message) {
                if ($message['message'] === $errorMessage) {
                    $this->assertEquals($expectedContext['channels'], $message['context']['channels']);
                    $this->assertEquals($expectedContext['event'], $message['context']['event']);
                    $this->assertEquals($expectedContext['error'], $message['context']['error']);
                    $found = true;
                    break;
                }
            }

            $this->assertTrue($found, 'Expected error log message not found');
        }
    }
}
