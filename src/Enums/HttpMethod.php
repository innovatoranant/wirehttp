<?php

declare(strict_types=1);

namespace WireHttp\Enums;

/**
 * HTTP Method Enum
 *
 * A strict, type-safe representation of every standard and extended HTTP method.
 * Using a BackedEnum means you get IDE autocompletion, native comparison, exhaustive
 * match expressions, and instant serialization via ->value. Never pass a raw string
 * like 'GET' again — always use HttpMethod::GET.
 *
 * Covers:
 *  - RFC 7231 (HTTP/1.1 semantics): GET, POST, PUT, PATCH, DELETE, HEAD, OPTIONS, TRACE
 *  - RFC 5789 (PATCH): PATCH
 *  - RFC 4918 (WebDAV): PROPFIND, PROPPATCH, MKCOL, COPY, MOVE, LOCK, UNLOCK
 *  - RFC 3744 (WebDAV ACL): ACL
 *  - RFC 5842 (BIND): BIND, UNBIND, REBIND
 *  - RFC 3253 (DeltaV): REPORT, SEARCH, CHECKOUT, CHECKIN, UNCHECKOUT, MKWORKSPACE,
 *                        LABEL, MERGE, BASELINE-CONTROL, MKACTIVITY
 *  - CONNECT: Used for HTTP tunneling (e.g., HTTPS through a proxy)
 */
enum HttpMethod: string
{
    // ─── Standard Methods (RFC 7231) ─────────────────────────────────────────

    /**
     * Transfer a current representation of the target resource.
     * Safe and idempotent. The most fundamental HTTP method.
     */
    case GET = 'GET';

    /**
     * Perform resource-specific processing on the request payload.
     * Neither safe nor idempotent. Used for form submissions, API mutations etc.
     */
    case POST = 'POST';

    /**
     * Replace all current representations of the target resource with the request payload.
     * Idempotent. The payload completely replaces the existing resource.
     */
    case PUT = 'PUT';

    /**
     * Apply a partial modification to the resource. (RFC 5789)
     * Neither safe nor guaranteed idempotent by spec (but often implemented as such).
     */
    case PATCH = 'PATCH';

    /**
     * Remove all current representations of the target resource.
     * Idempotent.
     */
    case DELETE = 'DELETE';

    /**
     * Identical to GET but the server must not send a message body in the response.
     * Used for checking headers and resource existence without downloading content.
     * Safe and idempotent.
     */
    case HEAD = 'HEAD';

    /**
     * Describe the communication options for the target resource.
     * Used for CORS preflight requests. Safe and idempotent.
     */
    case OPTIONS = 'OPTIONS';

    /**
     * Perform a message loop-back test along the path to the target resource.
     * Safe and idempotent. Never use on a production resource (security risk).
     */
    case TRACE = 'TRACE';

    /**
     * Establish a tunnel to the server identified by the target resource.
     * Used for TLS/SSL tunneling (HTTPS) through HTTP proxies.
     */
    case CONNECT = 'CONNECT';

    // ─── WebDAV Methods (RFC 4918) ────────────────────────────────────────────

    /**
     * Retrieve properties, stored as XML, from a web resource. (WebDAV)
     */
    case PROPFIND = 'PROPFIND';

    /**
     * Process instructions specified in the request body to set/remove properties. (WebDAV)
     */
    case PROPPATCH = 'PROPPATCH';

    /**
     * Create a new collection (directory). (WebDAV)
     */
    case MKCOL = 'MKCOL';

    /**
     * Create a duplicate of the source resource identified by the Request-URI. (WebDAV)
     */
    case COPY = 'COPY';

    /**
     * Move the source resource to the destination URI. (WebDAV)
     */
    case MOVE = 'MOVE';

    /**
     * Take out a lock of any access type on the resource. (WebDAV)
     */
    case LOCK = 'LOCK';

    /**
     * Remove the lock identified by a lock token from the Request-URI. (WebDAV)
     */
    case UNLOCK = 'UNLOCK';

    // ─── WebDAV Search & Versioning (RFC 3253) ────────────────────────────────

    /**
     * Create a working resource from a version-controlled or checkout resource. (DeltaV)
     */
    case CHECKOUT = 'CHECKOUT';

    /**
     * Merge a set of contributions into a version-controlled resource. (DeltaV)
     */
    case CHECKIN = 'CHECKIN';

    /**
     * Cancel the CHECKOUT and remove the working resource. (DeltaV)
     */
    case UNCHECKOUT = 'UNCHECKOUT';

    /**
     * Create an activity or baseline in a version history. (DeltaV)
     */
    case MKWORKSPACE = 'MKWORKSPACE';

    /**
     * Create a new activity resource. (DeltaV)
     */
    case MKACTIVITY = 'MKACTIVITY';

    /**
     * Perform a full or partial baseline report. (DeltaV)
     */
    case BASELINE_CONTROL = 'BASELINE-CONTROL';

    /**
     * Perform a merge of a version-controlled resource. (DeltaV)
     */
    case MERGE = 'MERGE';

    /**
     * Modify the label on a version resource. (DeltaV)
     */
    case LABEL = 'LABEL';

    /**
     * Retrieve a report from a resource. (RFC 3253)
     */
    case REPORT = 'REPORT';

    /**
     * Search a namespace for resources satisfying a given criteria. (RFC 5323)
     */
    case SEARCH = 'SEARCH';

    // ─── WebDAV ACL (RFC 3744) ────────────────────────────────────────────────

    /**
     * Modify the access control list of a principal resource. (RFC 3744)
     */
    case ACL = 'ACL';

    // ─── BIND (RFC 5842) ─────────────────────────────────────────────────────

    /**
     * Add a new binding from a Request-URI to a resource. (RFC 5842)
     */
    case BIND = 'BIND';

    /**
     * Remove a binding from a resource. (RFC 5842)
     */
    case UNBIND = 'UNBIND';

    /**
     * Re-bind a new path to a resource in place of an existing path. (RFC 5842)
     */
    case REBIND = 'REBIND';

    // ─── Utility Methods ─────────────────────────────────────────────────────

    /**
     * Determines whether this method is considered "safe" per RFC 7231.
     *
     * A safe method is one that does not have any side effects on the server.
     * Clients can rely on safe methods never modifying any resource state.
     */
    public function isSafe(): bool
    {
        return match ($this) {
            self::GET, self::HEAD, self::OPTIONS, self::TRACE => true,
            default => false,
        };
    }

    /**
     * Determines whether this method is "idempotent" per RFC 7231.
     *
     * An idempotent method is one where making identical requests multiple times
     * has the same effect as making a single request. All safe methods are
     * idempotent, plus PUT and DELETE.
     */
    public function isIdempotent(): bool
    {
        return match ($this) {
            self::GET, self::HEAD, self::OPTIONS, self::TRACE,
            self::PUT, self::DELETE => true,
            default => false,
        };
    }

    /**
     * Returns true if this method is expected to have a request body.
     *
     * While technically any method can have a body, servers/proxies typically
     * strip bodies from GET and HEAD requests.
     */
    public function hasBody(): bool
    {
        return match ($this) {
            self::GET, self::HEAD, self::OPTIONS, self::TRACE, self::CONNECT => false,
            default => true,
        };
    }

    /**
     * Attempts to create an HttpMethod from a raw string, case-insensitively.
     *
     * @throws \ValueError if the method string is not a known HTTP method.
     */
    public static function fromString(string $method): self
    {
        return self::from(strtoupper($method));
    }

    /**
     * Attempts to create an HttpMethod from a raw string, case-insensitively.
     * Returns null if the string is not a known HTTP method (no exception thrown).
     */
    public static function tryFromString(string $method): ?self
    {
        return self::tryFrom(strtoupper($method));
    }
}
