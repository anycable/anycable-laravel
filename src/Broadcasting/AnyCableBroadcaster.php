<?php

namespace AnyCable\Laravel\Broadcasting;

use AnyCable\Laravel\Client as AnyCableClient;
use GuzzleHttp\Client;
use Illuminate\Broadcasting\Broadcasters\PusherBroadcaster;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Support\Facades\Log;

class AnyCableBroadcaster extends PusherBroadcaster
{
    /**
     * The AnyCable client instance.
     */
    protected AnyCableClient $client;

    /**
     * The AnyCable broadcasting configuration.
     */
    protected array $config;

    /**
     * Create a new AnyCable broadcaster instance.
     */
    public function __construct(Client $httpClient, array $config)
    {
        $this->config = $config;
        $this->client = new AnyCableClient($config, $httpClient);
    }

    /**
     * Return the valid authentication response.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  mixed  $result
     * @return mixed
     */
    public function validAuthenticationResponse($request, $result)
    {
        if (str_starts_with($request->channel_name, 'private') && isset($this->config['secret']) && $this->config['secret']) {
            $signed_stream_name = $this->client->signStream($request->channel_name);

            return ['auth' => $signed_stream_name];
        }

        return [];
    }

    /**
     * Broadcast the given event.
     */
    public function broadcast(array $channels, $event, array $payload = [])
    {
        try {
            foreach ($channels as $channel) {
                $result = $this->client->broadcastEvent($channel, $event, $payload);

                if (! $result['success']) {
                    Log::error('AnyCable broadcast failed', [
                        'channel' => $channel,
                        'event' => $event,
                        'status' => $result['status'],
                        'response' => $result['response'],
                    ]);
                }
            }
        } catch (\Exception $e) {
            Log::error('AnyCable broadcast request failed', [
                'channels' => $channels,
                'event' => $event,
                'error' => $e->getMessage(),
            ]);

            throw new BroadcastException('Failed to broadcast to AnyCable: ' . $e->getMessage());
        }
    }
}
