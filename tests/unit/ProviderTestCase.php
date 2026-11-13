<?php

namespace pixelwerft\calendarsync\tests\unit;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use pixelwerft\calendarsync\models\Connection;
use pixelwerft\calendarsync\providers\BaseProvider;
use Psr\Http\Message\RequestInterface;

/**
 * Runs a provider against canned answers and keeps the requests it sent.
 */
abstract class ProviderTestCase extends TestCase
{
    /** @var array<int, array{request: RequestInterface}> */
    protected array $history = [];

    /**
     * @param array<int, Response> $responses
     */
    protected function mock(BaseProvider $provider, array $responses): void
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));
        $provider->setClient(new Client(['handler' => $stack]));
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function json(array $data, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], (string)json_encode($data));
    }

    protected function request(int $index): RequestInterface
    {
        return $this->history[$index]['request'];
    }

    /**
     * @return array<string, mixed>
     */
    protected function body(int $index): array
    {
        return json_decode((string)$this->request($index)->getBody(), true);
    }

    /**
     * @return array<string, string>
     */
    protected function query(int $index): array
    {
        parse_str($this->request($index)->getUri()->getQuery(), $query);

        return $query;
    }

    /**
     * A connection that is signed in, with a token that is valid for an hour.
     */
    protected function connection(string $provider): Connection
    {
        $connection = new Connection();
        $connection->provider = $provider;
        $connection->credentials = ['accessToken' => 'token-1', 'refreshToken' => 'refresh-1', 'expires' => time() + 3600];

        return $connection;
    }
}
