<?php

namespace anvildev\simpleform\integrations;

/**
 * Raised by {@see AbstractGoogleIntegration} when a Google credential is missing,
 * malformed, or the token exchange fails. Its message is deliberately
 * credential-free so it is safe to surface in a (scrubbed) dispatch-log row.
 */
class GoogleAuthException extends \RuntimeException
{
}
