<?php

namespace App\Domain\Tenancy;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class MembershipConflict extends ConflictHttpException {}
