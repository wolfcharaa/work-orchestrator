<?php

declare(strict_types=1);

namespace Fedor\WorkOrchestrator\Example\RecordImport;

use UnexpectedValueException;

final readonly class RecordNormalizer
{
    /**
     * @param array<string, mixed> $record
     *
     * @return array{id: string, name: string}
     */
    public function normalize(array $record): array
    {
        $id = $record['id'] ?? null;
        $name = $record['name'] ?? null;

        if ((!\is_string($id) && !\is_int($id)) || !\is_string($name)) {
            throw new UnexpectedValueException('Each record must contain a string/integer id and string name.');
        }

        $normalizedName = \trim($name);
        if ($normalizedName === '') {
            throw new UnexpectedValueException('Record name must not be empty after normalization.');
        }

        return [
            'id' => (string) $id,
            'name' => $normalizedName,
        ];
    }
}
