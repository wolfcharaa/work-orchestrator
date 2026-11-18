<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Contract;

/** Explicit assertion that a managed message may repeat after process loss. */
interface IdempotentManagedTask extends ManagedTask {}
