<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator;

final readonly class CompleteAs implements FailureAction
{
    public function __construct(public mixed $value) {}
}
