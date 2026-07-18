<?php

declare(strict_types=1);

namespace Example\Bear\Resource\App;

use BEAR\Resource\ResourceObject;
use BEAR\ToolUse\Attribute\Tool;

use function levenshtein;
use function round;
use function similar_text;

/**
 * Pure computation over PHP's built-in string-comparison functions — no
 * canned data, no I/O, just similar_text()/levenshtein() doing real work.
 * A crowd-pleaser for a PHP audience: most people know these functions
 * exist but have never seen an LLM reach for them mid-conversation.
 *
 * ALPS marks `word_similarity_get` as `safe`, so it's confirm-free and
 * joins the parallel wave alongside rot13_get/package_search.
 */
final class Similarity extends ResourceObject
{
    /**
     * @param string $a First word or phrase
     * @param string $b Second word or phrase
     *
     * similar_text()'s third parameter is a by-ref out-param the analyzer's
     * native stub types as `mixed` regardless of the seeded value, so the
     * write and the later cast both read as unsafe even though the runtime
     * value is always a float.
     *
     * @mago-expect analysis:mixed-assignment
     * @mago-expect analysis:invalid-type-cast
     */
    #[Tool(
        name: 'word_similarity_get',
        description: "Compare two words or phrases using PHP's built-in similar_text() and levenshtein() functions",
        confirm: false,
    )]
    public function onGet(string $a, string $b): static
    {
        $percent = 0.0;
        similar_text($a, $b, $percent);

        $this->body = [
            'a' => $a,
            'b' => $b,
            'similarity_percent' => round((float) $percent, 1),
            'levenshtein_distance' => levenshtein($a, $b),
        ];

        return $this;
    }
}
