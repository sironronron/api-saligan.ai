<?php

namespace App\Support;

final class Vector
{
    /**
     * Generate a random unit-ish vector for testing/factories.
     *
     * @return array<int, float>
     */
    public static function random(int $dimensions = 768): array
    {
        $vector = [];

        for ($i = 0; $i < $dimensions; $i++) {
            $vector[] = round(mt_rand(-1000000, 1000000) / 1000000, 6);
        }

        return $vector;
    }
}
