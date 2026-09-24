<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The record changed state since the user loaded it (e.g. already processed, already received).
 * Rendered as HTTP 409 for JSON requests and as a flash error for Inertia/web requests.
 */
class StateConflictException extends RuntimeException {}
