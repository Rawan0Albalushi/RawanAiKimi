<?php

namespace App\Services\Documents;

/**
 * A conservative stand-in for the model tokenizer.
 *
 * Arabic characters are counted as slightly more than one token. Other
 * characters use about one token per three characters. The estimate is high
 * on purpose so a prompt stays inside the worker context.
 */
class TokenEstimator
{
    public function estimate(string $text): int
    {
        if ($text === '') {
            return 0;
        }

        $characters = mb_strlen($text);
        $arabic = preg_match_all('/\p{Arabic}/u', $text);
        $arabic = is_int($arabic) ? $arabic : 0;
        $other = max(0, $characters - $arabic);

        return (int) ceil(($arabic * 1.1) + ($other / 3)) + 2;
    }
}
