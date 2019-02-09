<?php

/*
 * This file is part of the Predis\Async package.
 *
 * (c) Daniele Alessandri <suppakilla@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Predis\Async\PubSub;

use Predis\Async\Client;
use Predis\Command\Command;
use Predis\Command\CommandInterface;
use Predis\Response\ResponseInterface;
use RuntimeException;

/**
 * Redis PUB/SUB consumer abstraction.
 *
 * @author Daniele Alessandri <suppakilla@gmail.com>
 */
class Consumer
{
    const SUBSCRIBE = 'subscribe';
    const UNSUBSCRIBE = 'unsubscribe';
    const PSUBSCRIBE = 'psubscribe';
    const PUNSUBSCRIBE = 'punsubscribe';
    const MESSAGE = 'message';
    const PMESSAGE = 'pmessage';
    const PONG = 'pong';

    protected $client;
    protected $callback;
    protected $closing;

    /**
     * @param Client $client Client instance.
     * @param callable $callback Callback invoked on each received message.
     */
    public function __construct(Client $client, callable $callback)
    {
        $this->client = $client;
        $this->callback = $callback;
        $this->closing = false;
    }

    /**
     * Closes the underlying connection to the server.
     */
    public function quit()
    {
        $this->closing = true;
        $this->client->quit();
    }

    /**
     * Subscribes to one or more channels.
     *
     * @param mixed $channels List of channels.
     * @return \React\Promise\PromiseInterface
     */
    public function subscribe(...$channels)
    {
        return $this->writeRequest('subscribe', $channels);
    }

    /**
     * Writes a Redis command on the underlying connection.
     *
     * @param string $method Command ID.
     * @param array $arguments Arguments for the command.
     * @return \React\Promise\PromiseInterface
     */
    protected function writeRequest($method, $arguments)
    {
        $arguments = Command::normalizeArguments($arguments ?: []);
        $command = $this->client->createCommand($method, $arguments);

        return $this->client->executeCommand($command);
    }

    /**
     * Subscribes to one or more channels by pattern.
     *
     * @param mixed $channels List of pattenrs.
     * @return \React\Promise\PromiseInterface
     */
    public function psubscribe(...$channels)
    {
        return $this->writeRequest('psubscribe', $channels);
    }

    /**
     * Unsubscribes from one or more channels.
     *
     * @param mixed $channels List of channels.
     * @return \React\Promise\PromiseInterface
     */
    public function unsubscribe(...$channels)
    {
        return $this->writeRequest('unsubscribe', $channels);
    }

    /**
     * Unsubscribes from one or more channels by pattern.
     *
     * @param mixed $channels List of patterns..
     * @return \React\Promise\PromiseInterface
     */
    public function punsubscribe(...$channels)
    {
        return $this->writeRequest('punsubscribe', $channels);
    }

    /**
     * PING the server with an optional payload that will be echoed as a
     * PONG message in the pub/sub loop.
     *
     * @param string $payload Optional PING payload.
     * @return \React\Promise\PromiseInterface
     */
    public function ping($payload = null)
    {
        return $this->writeRequest('ping', [$payload]);
    }

    /**
     * Wraps the user-provided callback to process payloads returned by the server.
     *
     * @param string $payload Payload returned by the server.
     * @param Client $client Associated client instance.
     * @param CommandInterface $command Command instance (always NULL in case of streaming contexts).
     */
    public function __invoke($payload, $client, $command)
    {
        $parsedPayload = $this->parsePayload($payload);

        if ($this->closing) {
            $this->client->disconnect();
            $this->closing = false;

            return;
        }

        if (isset($parsedPayload)) {
            call_user_func($this->callback, $parsedPayload, $this);
        }
    }

    /**
     * Parses the response array returned by the server into an object.
     *
     * @param array $response Message payload.
     *
     * @return object
     */
    protected function parsePayload($response)
    {
        if ($response instanceof ResponseInterface) {
            return $response;
        }

        // TODO: I don't exactly like how we are handling this condition.
        if ($this->closing) {
            return null;
        }

        switch ($response[0]) {
            case self::SUBSCRIBE:
            case self::UNSUBSCRIBE:
            case self::PSUBSCRIBE:
            case self::PUNSUBSCRIBE:
                if ($response[2] === 0) {
                    $this->closing = true;
                }

                return null;

            case self::MESSAGE:
                return (object)[
                    'kind' => $response[0],
                    'channel' => $response[1],
                    'payload' => $response[2],
                ];

            case self::PMESSAGE:
                return (object)[
                    'kind' => $response[0],
                    'pattern' => $response[1],
                    'channel' => $response[2],
                    'payload' => $response[3],
                ];

            case self::PONG:
                return (object)[
                    'kind' => $response[0],
                    'payload' => $response[1],
                ];

            default:
                throw new RuntimeException(
                    "Received an unknown message type {$response[0]} inside of a pubsub context"
                );
        }
    }

    /**
     * Returns the underlying client instance.
     *
     * @return Client
     */
    public function getClient()
    {
        return $this->client;
    }
}
