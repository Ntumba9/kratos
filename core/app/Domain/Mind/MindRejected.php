<?php

namespace App\Domain\Mind;

use RuntimeException;

/**
 * KRATOS MIND rejected the request as malformed (a contract bug, not transient).
 */
final class MindRejected extends RuntimeException {}
