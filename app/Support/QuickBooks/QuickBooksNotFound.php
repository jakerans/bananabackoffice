<?php

namespace App\Support\QuickBooks;

/**
 * QuickBooks answered that what was asked for is not there: an estimate
 * deleted in QuickBooks since Backoffice made it (slice 38). Not a failure
 * to answer, so it is never mistaken for one.
 */
class QuickBooksNotFound extends QuickBooksUnavailable {}
