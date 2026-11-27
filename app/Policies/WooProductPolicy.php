<?php

namespace App\Policies;

/** The mirror of the shop is written by fetches and webhooks only. */
class WooProductPolicy extends ReadOnlyPolicy {}
