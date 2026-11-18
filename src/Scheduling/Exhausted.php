<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Scheduling;

/** @implements AdmissionDecision<never> */
final readonly class Exhausted implements AdmissionDecision {}
