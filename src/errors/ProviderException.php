<?php

namespace pixelwerft\calendarsync\errors;

use yii\base\Exception;

/**
 * A provider did not answer, refused, or answered something unexpected.
 */
class ProviderException extends Exception
{
    public function __construct(string $message, public int $statusCode = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    /**
     * @inheritdoc
     */
    public function getName(): string
    {
        return 'Calendar provider error';
    }

    /** The event or calendar is gone on the other side. */
    public function isNotFound(): bool
    {
        return $this->statusCode === 404 || $this->statusCode === 410;
    }

    /** The sign-in is no longer accepted; the connection has to be made again. */
    public function isUnauthorized(): bool
    {
        return $this->statusCode === 401;
    }
}
