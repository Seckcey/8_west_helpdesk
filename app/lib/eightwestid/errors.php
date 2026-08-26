<?php
/** Exceptions exposed by the zero-dependency 8 West ID client. */
declare(strict_types=1);

namespace EightWest\Id;

class EightWestIdException extends \RuntimeException
{
}

final class ConfigurationException extends EightWestIdException
{
}

class ProtocolException extends EightWestIdException
{
}

final class PolicyException extends ProtocolException
{
    public function __construct(private readonly string $reason)
    {
        parent::__construct('8 West ID rejected the identity policy.');
    }

    public function reason(): string
    {
        return $this->reason;
    }
}

final class RevocationUnavailableException extends EightWestIdException
{
}
