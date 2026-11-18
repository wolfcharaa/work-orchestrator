<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Contract;

use Fedor\WorkOrchestrator\ChildEventRoute;
use Fedor\WorkOrchestrator\Event\EventEnvelope;

/**
 * Optional primary-local consumer for application events from child tasks.
 *
 * @template TPayload
 * @template-covariant TResult
 * @extends Strategy<TPayload, TResult>
 */
interface RoutesChildApplicationEvents extends Strategy
{
    public function routeChildApplicationEvent(EventEnvelope $event): ChildEventRoute;
}
