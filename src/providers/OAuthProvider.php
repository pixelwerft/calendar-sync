<?php

namespace pixelwerft\calendarsync\providers;

use pixelwerft\calendarsync\CalendarSync;
use pixelwerft\calendarsync\errors\ProviderException;
use pixelwerft\calendarsync\models\Connection;

/**
 * A provider people sign in to (OAuth 2, authorization code). The plugin keeps a refresh token and gets a new
 * access token whenever the old one runs out.
 */
abstract class OAuthProvider extends BaseProvider
{
    abstract protected function clientId(): string;

    abstract protected function clientSecret(): string;

    abstract protected function authorizeEndpoint(): string;

    abstract protected function tokenEndpoint(): string;

    /**
     * What the plugin asks the account for. A connection that only reads asks for no right to write.
     *
     * @return string[]
     */
    abstract protected function scopes(bool $write): array;

    /**
     * The e-mail address of the account a token belongs to.
     */
    abstract protected function account(Connection $connection): ?string;

    /**
     * Extra parameters of the sign-in address, e.g. to always get a refresh token.
     *
     * @return array<string, string>
     */
    protected function authorizeParams(): array
    {
        return [];
    }

    /**
     * @inheritdoc
     */
    public static function usesOAuth(): bool
    {
        return true;
    }

    /**
     * @inheritdoc
     */
    public function isConfigured(): bool
    {
        return $this->clientId() !== '' && $this->clientSecret() !== '';
    }

    /**
     * Where to send someone to sign in.
     */
    public function getAuthorizationUrl(string $state, string $redirectUri, bool $write = true): string
    {
        $params = [
            'client_id' => $this->clientId(),
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => implode(' ', $this->scopes($write)),
            'state' => $state,
        ] + $this->authorizeParams();

        return $this->authorizeEndpoint() . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Trades the code the provider sent back for tokens and puts them on the connection (not saved yet).
     *
     * @throws ProviderException
     */
    public function completeAuthorization(Connection $connection, string $code, string $redirectUri): void
    {
        $tokens = $this->token([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
        ]);
        if (empty($tokens['refresh_token']) && empty($connection->credentials['refreshToken'])) {
            throw new ProviderException(CalendarSync::t('{provider} did not hand out a permanent sign-in (refresh token). Remove the access of this app in your account there and connect again.', [
                'provider' => static::displayName(),
            ]));
        }
        $this->storeTokens($connection, $tokens);
        $connection->status = Connection::STATUS_CONNECTED;
        $connection->lastError = null;
        $connection->settings['account'] = $this->account($connection);
        // what this sign-in was asked for; writing later needs a new one
        $connection->settings['grantedAccess'] = $connection->canWrite() ? Connection::ACCESS_READ_WRITE : Connection::ACCESS_READ;
    }

    /**
     * @inheritdoc
     */
    public function test(Connection $connection): ?string
    {
        return $this->account($connection);
    }

    /**
     * A valid access token, renewed if the stored one runs out within a minute.
     *
     * @throws ProviderException
     */
    protected function accessToken(Connection $connection, bool $forceRefresh = false): string
    {
        $credentials = $connection->credentials;
        $valid = !empty($credentials['accessToken']) && (int)($credentials['expires'] ?? 0) > time() + 60;
        if ($valid && !$forceRefresh) {
            return (string)$credentials['accessToken'];
        }
        if (empty($credentials['refreshToken'])) {
            throw new ProviderException(CalendarSync::t('The connection «{name}» is not signed in. Connect it again.', ['name' => $connection->name]), 401);
        }

        try {
            $tokens = $this->token([
                'grant_type' => 'refresh_token',
                'refresh_token' => $credentials['refreshToken'],
            ]);
        } catch (ProviderException $e) {
            // 400/401 here means the refresh token was revoked or ran out: only signing in again helps
            if (in_array($e->statusCode, [400, 401], true) && $connection->id && isset(CalendarSync::$plugin)) {
                CalendarSync::$plugin->connections->markError($connection, CalendarSync::t('The sign-in is no longer valid. Connect again.'));
            }
            throw $e;
        }

        $this->storeTokens($connection, $tokens);
        if ($connection->id && isset(CalendarSync::$plugin)) {
            CalendarSync::$plugin->connections->saveCredentials($connection);
        }

        return (string)$connection->credentials['accessToken'];
    }

    /**
     * A JSON request in the name of the connection. A token the provider no longer accepts is renewed once.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     * @throws ProviderException
     */
    protected function api(Connection $connection, string $method, string $url, array $options = []): array
    {
        $headers = $options['headers'] ?? [];
        $attempt = function(bool $forceRefresh) use ($connection, $method, $url, $options, $headers) {
            $options['headers'] = $headers + [
                'Authorization' => 'Bearer ' . $this->accessToken($connection, $forceRefresh),
                'Accept' => 'application/json',
            ];

            return $this->send($method, $url, $options);
        };

        try {
            $response = $attempt(false);
        } catch (ProviderException $e) {
            if (!$e->isUnauthorized() || empty($connection->credentials['refreshToken'])) {
                throw $e;
            }
            $response = $attempt(true);
        }

        $body = (string)$response->getBody();
        if ($body === '') {
            return [];
        }
        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new ProviderException(sprintf('%s: %s', static::displayName(), CalendarSync::t('The answer could not be read.')));
        }

        return $data;
    }

    /**
     * @param array<string, string> $params
     * @return array<string, mixed>
     * @throws ProviderException
     */
    private function token(array $params): array
    {
        $response = $this->send('POST', $this->tokenEndpoint(), [
            'form_params' => $params + [
                'client_id' => $this->clientId(),
                'client_secret' => $this->clientSecret(),
            ],
            'headers' => ['Accept' => 'application/json'],
        ]);
        $tokens = json_decode((string)$response->getBody(), true);
        if (!is_array($tokens) || empty($tokens['access_token'])) {
            throw new ProviderException(sprintf('%s: %s', static::displayName(), CalendarSync::t('The answer could not be read.')));
        }

        return $tokens;
    }

    /**
     * @param array<string, mixed> $tokens
     */
    private function storeTokens(Connection $connection, array $tokens): void
    {
        $connection->credentials['accessToken'] = (string)$tokens['access_token'];
        $connection->credentials['expires'] = time() + (int)($tokens['expires_in'] ?? 3600);
        // Google sends the refresh token once, Microsoft a new one with every renewal
        if (!empty($tokens['refresh_token'])) {
            $connection->credentials['refreshToken'] = (string)$tokens['refresh_token'];
        }
    }
}
