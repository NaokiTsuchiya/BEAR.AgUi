<?php

declare(strict_types=1);

namespace NaokiTsuchiya\BEARAgUi\Input;

use LogicException;

/**
 * Outcome of a fallible computation: a success value `T` or a failure `E`.
 *
 * Gives the input-parsing pipeline a single vocabulary for "this either
 * produced a value or a {@see ParseError}" instead of ad-hoc `T|ParseError`
 * unions (D24 step B). The error side is uniformly a `list<ParseError>`: the
 * leaf parsers return `Result<T, list<ParseError>>` (each entry aggregates
 * its own independent field errors), and the orchestrator's public boundary
 * returns `Result<RunAgentInput, list<ParseError>>` once the per-sibling
 * lists are merged.
 *
 * Both type parameters are covariant so {@see ok()} / {@see err()} — which
 * only know one side — unify with a declared `Result<T, E>`, and a
 * `Result<UserMessage, list<ParseError>>` satisfies a
 * `Result<Message, list<ParseError>>` return contract.
 *
 * Consumption is by convention: check {@see isOk()} first, then read
 * {@see unwrap()} (success) or {@see unwrapErr()} (failure). Reading the
 * wrong side is a programmer error and throws {@see LogicException}.
 *
 * @template-covariant T
 * @template-covariant E
 *
 * @api
 */
final readonly class Result
{
    /**
     * @param T|null $value
     * @param E|null $error
     */
    private function __construct(
        private mixed $value,
        private mixed $error,
        private bool $ok,
    ) {}

    /**
     * @template V
     *
     * @param V $value
     *
     * @return self<V, never>
     */
    public static function ok(mixed $value): self
    {
        return new self($value, null, true);
    }

    /**
     * @template F
     *
     * @param F $error
     *
     * @return self<never, F>
     */
    public static function err(mixed $error): self
    {
        return new self(null, $error, false);
    }

    public function isOk(): bool
    {
        return $this->ok;
    }

    /**
     * The success value. Throws if this is an error result — check
     * {@see isOk()} first.
     *
     * @return T
     */
    public function unwrap(): mixed
    {
        if (!$this->ok) {
            throw new LogicException('Result::unwrap() called on an error result.');
        }

        /** @var T */
        return $this->value;
    }

    /**
     * The failure value. Throws if this is a success result — check
     * {@see isOk()} first.
     *
     * @return E
     */
    public function unwrapErr(): mixed
    {
        if ($this->ok) {
            throw new LogicException('Result::unwrapErr() called on a success result.');
        }

        /** @var E */
        return $this->error;
    }
}
