<?php

declare(strict_types=1);

namespace HDSSolutions\Laravel\AppSyncEvents\Auth;

use RuntimeException;

/**
 * Thrown when an AppSync Events authorization token cannot be verified.
 */
final class InvalidTokenException extends RuntimeException {}
