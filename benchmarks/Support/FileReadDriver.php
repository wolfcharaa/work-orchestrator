<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Benchmark\Support;

/** Filesystem implementations available only to the isolated read benchmark. */
enum FileReadDriver: string
{
    case Auto = 'auto';
    case Native = 'native';
    case Blocking = 'blocking';
    case Uv = 'uv';
    case Eio = 'eio';
}
