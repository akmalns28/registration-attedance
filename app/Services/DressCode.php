<?php

namespace App\Services;

class DressCode
{
    public const MAX_LENGTH = 1000;

    public static function normalize(?string $value): ?string
    {
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $value ?? ''));
        $text = trim(implode("\n", array_map(trim(...), $lines)));

        return $text === '' ? null : $text;
    }

    /**
     * @return list<array{type: 'p'|'ul'|'ol', start: int, lines: list<string>}>
     */
    public static function blocks(?string $value): array
    {
        $blocks = [];
        $previousType = null;

        foreach (explode("\n", self::normalize($value) ?? '') as $line) {
            if ($line === '') {
                $previousType = null;

                continue;
            }

            $type = 'p';
            $start = 1;
            if (preg_match('/^(?:[•*-]|([1-9][0-9]{0,3})\.)[ \t]+(.+)$/u', $line, $marker, PREG_UNMATCHED_AS_NULL)) {
                $type = $marker[1] === null ? 'ul' : 'ol';
                $start = (int) ($marker[1] ?? 1);
                $line = $marker[2];
            }

            if ($type !== $previousType) {
                $blocks[] = ['type' => $type, 'start' => $start, 'lines' => []];
            }

            $blocks[array_key_last($blocks)]['lines'][] = $line;
            $previousType = $type;
        }

        return $blocks;
    }
}
