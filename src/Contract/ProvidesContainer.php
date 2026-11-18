<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Contract;

use Psr\Container\ContainerInterface;

/**
 * Strategy capability exposing its already-bootstrapped primary container.
 *
 * @template TPayload
 * @template-covariant TResult
 * @extends Strategy<TPayload, TResult>
 */
interface ProvidesContainer extends Strategy
{
    public function container(): ContainerInterface;
}
