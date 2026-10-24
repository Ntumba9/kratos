<?php

namespace App\Domain\Eye;

enum ProcessOutcome
{
    /** This delivery processed the event. */
    case Processed;

    /** Already processed: a duplicate delivery, nothing to do. */
    case AlreadyProcessed;

    /** Another worker holds a fresh claim; retry later in case it dies. */
    case InProgressElsewhere;
}
