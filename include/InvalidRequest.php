<?php

/**
 * A request the service can't answer with a map.
 */

namespace StaticMaps;

use InvalidArgumentException;

/**
 * Thrown for invalid request parameters. The message is shown to the client
 * with HTTP 400, so it must say what is wrong without revealing anything else.
 */
final class InvalidRequest extends InvalidArgumentException
{
}
