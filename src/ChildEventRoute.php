<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator;

enum ChildEventRoute: string
{
    case Relay = 'relay';
    case Consume = 'consume';
}
