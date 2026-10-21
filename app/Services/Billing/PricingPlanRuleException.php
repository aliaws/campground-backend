<?php

namespace App\Services\Billing;

/** A pricing plan change that breaks a plan rule (returned to the client as 422). */
class PricingPlanRuleException extends \RuntimeException {}
