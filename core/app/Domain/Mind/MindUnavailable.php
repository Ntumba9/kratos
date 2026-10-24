<?php

namespace App\Domain\Mind;

use RuntimeException;

/**
 * KRATOS MIND could not be reached or failed internally (transient).
 */
final class MindUnavailable extends RuntimeException {}
