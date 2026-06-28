<?php

declare(strict_types=1);

namespace WireHttp\Exceptions;

use WireHttp\Http\Request;
use WireHttp\Http\Response;

/**
 * TooManyRedirectsException — Redirect Loop or Limit Exceeded
 *
 * Thrown by WireHTTP's `RedirectInterceptor` when the number of HTTP redirects
 * followed for a single request exceeds the configured `max_redirects` limit,
 * or when a redirect loop is detected (i.e., the client is being sent in circles).
 *
 * This is a transport-level concern but it does produce a partial response
 * (the last redirect response), so we attach it here for debugging.
 *
 * WireHTTP's default redirect limit is 10, matching the behaviour of most browsers.
 *
 * Example scenarios that trigger this exception:
 *  1. Server keeps issuing 301 redirects more times than the configured max.
 *  2. A cycle is detected: A → B → C → A (infinite loop).
 *
 * Usage:
 *   try {
 *       Wire::get('/redirects/forever')->maxRedirects(5)->send();
 *   } catch (TooManyRedirectsException $e) {
 *       echo "Followed {$e->getRedirectCount()} redirects before giving up.";
 *       echo "History: " . implode(' → ', $e->getRedirectHistory());
 *       if ($e->isLoop()) {
 *           echo "A redirect loop was detected!";
 *       }
 *   }
 */
class TooManyRedirectsException extends WireHttpException
{
    /**
     * How many redirects were followed before this exception was thrown.
     */
    private readonly int $redirectCount;

    /**
     * The maximum number of redirects allowed (the configured limit).
     */
    private readonly int $maxRedirects;

    /**
     * The ordered list of URLs that were visited during the redirect chain.
     * Includes the original URL and all redirect targets, in order.
     *
     * @var string[]
     */
    private readonly array $redirectHistory;

    /**
     * Whether a redirect loop was detected (i.e., a URL appeared more than once
     * in the redirect chain) as opposed to simply exceeding the max limit.
     */
    private readonly bool $isLoop;

    /**
     * @param string[] $redirectHistory Ordered list of visited URLs
     */
    public function __construct(
        int $redirectCount,
        int $maxRedirects,
        array $redirectHistory = [],
        bool $isLoop = false,
        ?Request $request = null,
        ?Response $response = null,
        ?\Throwable $previous = null,
    ) {
        $this->redirectCount   = $redirectCount;
        $this->maxRedirects    = $maxRedirects;
        $this->redirectHistory = $redirectHistory;
        $this->isLoop          = $isLoop;

        $message = $isLoop
            ? sprintf(
                'Infinite redirect loop detected after %d redirects. Chain: %s',
                $redirectCount,
                implode(' → ', $redirectHistory)
            )
            : sprintf(
                'Too many redirects: followed %d of max %d. Last URL: %s',
                $redirectCount,
                $maxRedirects,
                end($redirectHistory) ?: 'unknown'
            );

        parent::__construct(
            message: $message,
            code: 0,
            previous: $previous,
            request: $request,
            response: $response,
            context: [
                'redirect_count'   => $redirectCount,
                'max_redirects'    => $maxRedirects,
                'is_loop'          => $isLoop,
                'redirect_history' => $redirectHistory,
            ],
        );
    }

    /**
     * Returns the number of redirects that were followed before giving up.
     */
    public function getRedirectCount(): int
    {
        return $this->redirectCount;
    }

    /**
     * Returns the configured maximum number of redirects allowed.
     */
    public function getMaxRedirects(): int
    {
        return $this->maxRedirects;
    }

    /**
     * Returns the ordered list of URLs that were visited during the redirect chain,
     * including the original request URL.
     *
     * @return string[]
     */
    public function getRedirectHistory(): array
    {
        return $this->redirectHistory;
    }

    /**
     * Returns true if a cycle was detected in the redirect chain.
     * Returns false if the exception was thrown because the max redirect count was reached.
     */
    public function isLoop(): bool
    {
        return $this->isLoop;
    }

    /**
     * Returns the final URL in the redirect chain before the exception was thrown.
     */
    public function getLastUrl(): ?string
    {
        if (empty($this->redirectHistory)) {
            return null;
        }

        return end($this->redirectHistory) ?: null;
    }
}
