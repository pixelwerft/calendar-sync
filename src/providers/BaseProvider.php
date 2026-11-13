<?php

namespace pixelwerft\calendarsync\providers;

use Craft;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\GuzzleException;
use pixelwerft\calendarsync\CalendarSync;
use pixelwerft\calendarsync\errors\ProviderException;
use pixelwerft\calendarsync\models\Connection;
use Psr\Http\Message\ResponseInterface;
use yii\base\Component;

/**
 * The HTTP side every provider shares.
 */
abstract class BaseProvider extends Component implements ProviderInterface
{
    private ?Client $client = null;

    /**
     * Another HTTP client, for tests.
     */
    public function setClient(Client $client): void
    {
        $this->client = $client;
    }

    /**
     * @inheritdoc
     */
    public static function canWrite(): bool
    {
        return true;
    }

    /**
     * @inheritdoc
     */
    public function isConfigured(): bool
    {
        return true;
    }

    /**
     * @inheritdoc
     */
    public function validateConnection(Connection $connection): void
    {
    }

    protected function client(): Client
    {
        if ($this->client === null) {
            $timeout = isset(CalendarSync::$plugin) ? CalendarSync::$plugin->getSettings()->requestTimeout : 30;
            $this->client = Craft::createGuzzleClient([
                'timeout' => $timeout,
                'connect_timeout' => 10,
            ]);
        }

        return $this->client;
    }

    /**
     * Sends a request and turns every failure into a ProviderException with a message a person can read.
     *
     * @param array<string, mixed> $options
     * @throws ProviderException
     */
    protected function send(string $method, string $url, array $options = []): ResponseInterface
    {
        try {
            return $this->client()->request($method, $url, $options);
        } catch (BadResponseException $e) {
            $response = $e->getResponse();
            throw new ProviderException(
                sprintf('%s: %s', static::displayName(), $this->errorMessage($response)),
                $response->getStatusCode(),
                $e,
            );
        } catch (GuzzleException $e) {
            throw new ProviderException(sprintf('%s: %s', static::displayName(), $e->getMessage()), 0, $e);
        }
    }

    /**
     * The reason a provider gives for a refusal. JSON APIs say it in `error.message` or `error_description`.
     */
    protected function errorMessage(ResponseInterface $response): string
    {
        $status = $response->getStatusCode() . ' ' . $response->getReasonPhrase();
        $body = (string)$response->getBody();
        $data = json_decode($body, true);
        if (is_array($data)) {
            $error = $data['error'] ?? null;
            $message = is_array($error) ? ($error['message'] ?? null) : ($data['error_description'] ?? $error);
            if (is_string($message) && $message !== '') {
                return $status . ' – ' . $message;
            }
        }

        return $status;
    }
}
