<?php

namespace App\Support\QuickBooks;

/**
 * QuickBooks answered and said no: a connection's keys were refused, so it
 * must be connected again (docs/slice-28-quickbooks-plan.md, step 28a).
 * Being unreachable for a moment is not this.
 */
class QuickBooksRefused extends QuickBooksUnavailable {}
