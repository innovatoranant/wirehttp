<?php

declare(strict_types=1);

namespace WireHttp\Enums;

/**
 * HTTP Status Code Enum
 *
 * A comprehensive BackedEnum of all registered and widely-used HTTP status codes.
 * Sources:
 *  - RFC 9110 (HTTP Semantics — the modern consolidated RFC)
 *  - RFC 6585 (Additional HTTP Status Codes)
 *  - RFC 7538 (308 Permanent Redirect)
 *  - RFC 8297 (103 Early Hints)
 *  - RFC 2518 / RFC 4918 (WebDAV)
 *  - IANA HTTP Status Code Registry
 *  - Unofficial but widely deployed codes (418, 420, 430, 509, etc.)
 */
enum StatusCode: int
{
    // ─── 1xx Informational ───────────────────────────────────────────────────

    /** The server has received the request headers and the client should proceed to send the body. */
    case CONTINUE                        = 100;

    /** The server is switching protocols as requested by the client. */
    case SWITCHING_PROTOCOLS             = 101;

    /** (WebDAV) The server has received the request and is processing it, no response available yet. */
    case PROCESSING                      = 102;

    /** (RFC 8297) Used to return some response headers before final HTTP message. */
    case EARLY_HINTS                     = 103;

    // ─── 2xx Success ─────────────────────────────────────────────────────────

    /** The request succeeded. */
    case OK                              = 200;

    /** The request succeeded and a new resource was created as a result. */
    case CREATED                         = 201;

    /** The request has been accepted for processing, but processing has not been completed. */
    case ACCEPTED                        = 202;

    /** The returned metadata is not exactly the same as the origin server's. */
    case NON_AUTHORITATIVE_INFORMATION   = 203;

    /** There is no content to send for this request, headers are meaningful. */
    case NO_CONTENT                      = 204;

    /** Tell the client to reset the document which sent this request. */
    case RESET_CONTENT                   = 205;

    /** Only part of the resource is returned, used with Range header. */
    case PARTIAL_CONTENT                 = 206;

    /** (WebDAV) The message body contains multiple status codes. */
    case MULTI_STATUS                    = 207;

    /** (WebDAV) Members of a DAV binding have already been enumerated. */
    case ALREADY_REPORTED                = 208;

    /** The server has fulfilled a GET request and the response is a representation of the result. */
    case IM_USED                         = 226;

    // ─── 3xx Redirection ─────────────────────────────────────────────────────

    /** Multiple choices — the request has more than one possible response. */
    case MULTIPLE_CHOICES                = 300;

    /** The URL of the requested resource has been changed permanently. */
    case MOVED_PERMANENTLY               = 301;

    /** The URI of the requested resource has been changed temporarily. */
    case FOUND                           = 302;

    /** The server sent this response to direct the client to get the requested at another URI with GET. */
    case SEE_OTHER                       = 303;

    /** The response has not been modified since last request (used with ETags). */
    case NOT_MODIFIED                    = 304;

    /** (Deprecated) The requested resource is only available through a proxy. */
    case USE_PROXY                       = 305;

    /** (Unused — formerly Switch Proxy) */
    case UNUSED                          = 306;

    /** The server is redirecting to a different URI. The method and body will not be changed. */
    case TEMPORARY_REDIRECT              = 307;

    /** (RFC 7538) The resource has moved permanently. The method and body will not be changed. */
    case PERMANENT_REDIRECT              = 308;

    // ─── 4xx Client Errors ───────────────────────────────────────────────────

    /** The server cannot or will not process the request due to a client error. */
    case BAD_REQUEST                     = 400;

    /** The client must authenticate itself to get the requested response. */
    case UNAUTHORIZED                    = 401;

    /** Payment required (reserved, experimental). */
    case PAYMENT_REQUIRED                = 402;

    /** The client does not have access rights to the content. */
    case FORBIDDEN                       = 403;

    /** The server cannot find the requested resource. */
    case NOT_FOUND                       = 404;

    /** The request method is known but not supported by the target resource. */
    case METHOD_NOT_ALLOWED              = 405;

    /** The server cannot produce a response matching the criteria given by the client. */
    case NOT_ACCEPTABLE                  = 406;

    /** The client must first authenticate itself with the proxy. */
    case PROXY_AUTHENTICATION_REQUIRED   = 407;

    /** The server would like to shut down this unused connection (timeout). */
    case REQUEST_TIMEOUT                 = 408;

    /** The request conflicts with the current state of the server. */
    case CONFLICT                        = 409;

    /** The requested content has been permanently deleted from server. */
    case GONE                            = 410;

    /** Server rejected the request because Content-Length was not defined. */
    case LENGTH_REQUIRED                 = 411;

    /** The client has indicated preconditions which the server does not meet. */
    case PRECONDITION_FAILED             = 412;

    /** Request entity is larger than limits defined by server. */
    case CONTENT_TOO_LARGE               = 413;

    /** The URI requested by the client is longer than the server is willing to interpret. */
    case URI_TOO_LONG                    = 414;

    /** The media format of the requested data is not supported by the server. */
    case UNSUPPORTED_MEDIA_TYPE          = 415;

    /** The ranges specified by the Range header cannot be fulfilled. */
    case RANGE_NOT_SATISFIABLE           = 416;

    /** The expectation indicated in the Expect request header cannot be met. */
    case EXPECTATION_FAILED              = 417;

    /** The server refuses to brew coffee because it is, permanently, a teapot. (RFC 2324) */
    case IM_A_TEAPOT                     = 418;

    /** The request was directed at a server that is not able to produce a response. */
    case MISDIRECTED_REQUEST             = 421;

    /** (WebDAV) The request was well-formed but was unable to be followed due to semantic errors. */
    case UNPROCESSABLE_CONTENT           = 422;

    /** (WebDAV) The resource that is being accessed is locked. */
    case LOCKED                          = 423;

    /** (WebDAV) The request failed because it depended on another request which failed. */
    case FAILED_DEPENDENCY               = 424;

    /** Indicates that the server is unwilling to risk processing a request that might be replayed. */
    case TOO_EARLY                       = 425;

    /** The server refuses to perform the request using the current protocol. */
    case UPGRADE_REQUIRED                = 426;

    /** The origin server requires the request to be conditional. (RFC 6585) */
    case PRECONDITION_REQUIRED           = 428;

    /** The user has sent too many requests in a given amount of time. (RFC 6585) */
    case TOO_MANY_REQUESTS               = 429;

    /** The server is unwilling to process the request because its header fields are too large. (RFC 6585) */
    case REQUEST_HEADER_FIELDS_TOO_LARGE = 431;

    /** The user agent requested a resource that cannot be served due to legal reasons. (RFC 7725) */
    case UNAVAILABLE_FOR_LEGAL_REASONS   = 451;

    // ─── 5xx Server Errors ───────────────────────────────────────────────────

    /** The server has encountered a situation it does not know how to handle. */
    case INTERNAL_SERVER_ERROR           = 500;

    /** The request method is not supported by the server and cannot be handled. */
    case NOT_IMPLEMENTED                 = 501;

    /** The server, while working as a gateway, got an invalid response. */
    case BAD_GATEWAY                     = 502;

    /** The server is not ready to handle the request (maintenance, overloaded, etc.). */
    case SERVICE_UNAVAILABLE             = 503;

    /** The server, while acting as a gateway, did not get a response in time. */
    case GATEWAY_TIMEOUT                 = 504;

    /** The HTTP version used in the request is not supported by the server. */
    case HTTP_VERSION_NOT_SUPPORTED      = 505;

    /** The server has an internal configuration error: transparent content negotiation loops. */
    case VARIANT_ALSO_NEGOTIATES         = 506;

    /** (WebDAV) The server is unable to store the representation needed to complete the request. */
    case INSUFFICIENT_STORAGE            = 507;

    /** (WebDAV) The server detected an infinite loop while processing the request. */
    case LOOP_DETECTED                   = 508;

    /** Further extensions to the request are required for the server to fulfil it. */
    case NOT_EXTENDED                    = 510;

    /** The client needs to authenticate to gain network access. (RFC 6585) */
    case NETWORK_AUTHENTICATION_REQUIRED = 511;

    // ─── Utility Methods ─────────────────────────────────────────────────────

    /** Returns true if the status code is 1xx Informational. */
    public function isInformational(): bool
    {
        return $this->value >= 100 && $this->value < 200;
    }

    /** Returns true if the status code is 2xx Success. */
    public function isSuccess(): bool
    {
        return $this->value >= 200 && $this->value < 300;
    }

    /** Returns true if the status code is 3xx Redirection. */
    public function isRedirect(): bool
    {
        return $this->value >= 300 && $this->value < 400;
    }

    /** Returns true if the status code is 4xx Client Error. */
    public function isClientError(): bool
    {
        return $this->value >= 400 && $this->value < 500;
    }

    /** Returns true if the status code is 5xx Server Error. */
    public function isServerError(): bool
    {
        return $this->value >= 500 && $this->value < 600;
    }

    /** Returns true if the status code represents any error (4xx or 5xx). */
    public function isError(): bool
    {
        return $this->isClientError() || $this->isServerError();
    }

    /**
     * Returns true if a redirect of this status code should preserve the original
     * HTTP method (e.g., POST should remain POST, not become GET).
     *
     * 301 and 302 redirects historically caused browsers to change POST to GET.
     * 307 and 308 explicitly require preserving the original method.
     */
    public function isMethodPreservingRedirect(): bool
    {
        return match ($this) {
            self::TEMPORARY_REDIRECT, self::PERMANENT_REDIRECT => true,
            default => false,
        };
    }

    /**
     * Returns the standard reason phrase for this status code per RFC 9110.
     */
    public function reasonPhrase(): string
    {
        return match ($this) {
            self::CONTINUE                        => 'Continue',
            self::SWITCHING_PROTOCOLS             => 'Switching Protocols',
            self::PROCESSING                      => 'Processing',
            self::EARLY_HINTS                     => 'Early Hints',
            self::OK                              => 'OK',
            self::CREATED                         => 'Created',
            self::ACCEPTED                        => 'Accepted',
            self::NON_AUTHORITATIVE_INFORMATION   => 'Non-Authoritative Information',
            self::NO_CONTENT                      => 'No Content',
            self::RESET_CONTENT                   => 'Reset Content',
            self::PARTIAL_CONTENT                 => 'Partial Content',
            self::MULTI_STATUS                    => 'Multi-Status',
            self::ALREADY_REPORTED                => 'Already Reported',
            self::IM_USED                         => 'IM Used',
            self::MULTIPLE_CHOICES                => 'Multiple Choices',
            self::MOVED_PERMANENTLY               => 'Moved Permanently',
            self::FOUND                           => 'Found',
            self::SEE_OTHER                       => 'See Other',
            self::NOT_MODIFIED                    => 'Not Modified',
            self::USE_PROXY                       => 'Use Proxy',
            self::UNUSED                          => 'Switch Proxy',
            self::TEMPORARY_REDIRECT              => 'Temporary Redirect',
            self::PERMANENT_REDIRECT              => 'Permanent Redirect',
            self::BAD_REQUEST                     => 'Bad Request',
            self::UNAUTHORIZED                    => 'Unauthorized',
            self::PAYMENT_REQUIRED                => 'Payment Required',
            self::FORBIDDEN                       => 'Forbidden',
            self::NOT_FOUND                       => 'Not Found',
            self::METHOD_NOT_ALLOWED              => 'Method Not Allowed',
            self::NOT_ACCEPTABLE                  => 'Not Acceptable',
            self::PROXY_AUTHENTICATION_REQUIRED   => 'Proxy Authentication Required',
            self::REQUEST_TIMEOUT                 => 'Request Timeout',
            self::CONFLICT                        => 'Conflict',
            self::GONE                            => 'Gone',
            self::LENGTH_REQUIRED                 => 'Length Required',
            self::PRECONDITION_FAILED             => 'Precondition Failed',
            self::CONTENT_TOO_LARGE               => 'Content Too Large',
            self::URI_TOO_LONG                    => 'URI Too Long',
            self::UNSUPPORTED_MEDIA_TYPE          => 'Unsupported Media Type',
            self::RANGE_NOT_SATISFIABLE           => 'Range Not Satisfiable',
            self::EXPECTATION_FAILED              => 'Expectation Failed',
            self::IM_A_TEAPOT                     => "I'm a teapot",
            self::MISDIRECTED_REQUEST             => 'Misdirected Request',
            self::UNPROCESSABLE_CONTENT           => 'Unprocessable Content',
            self::LOCKED                          => 'Locked',
            self::FAILED_DEPENDENCY               => 'Failed Dependency',
            self::TOO_EARLY                       => 'Too Early',
            self::UPGRADE_REQUIRED                => 'Upgrade Required',
            self::PRECONDITION_REQUIRED           => 'Precondition Required',
            self::TOO_MANY_REQUESTS               => 'Too Many Requests',
            self::REQUEST_HEADER_FIELDS_TOO_LARGE => 'Request Header Fields Too Large',
            self::UNAVAILABLE_FOR_LEGAL_REASONS   => 'Unavailable For Legal Reasons',
            self::INTERNAL_SERVER_ERROR           => 'Internal Server Error',
            self::NOT_IMPLEMENTED                 => 'Not Implemented',
            self::BAD_GATEWAY                     => 'Bad Gateway',
            self::SERVICE_UNAVAILABLE             => 'Service Unavailable',
            self::GATEWAY_TIMEOUT                 => 'Gateway Timeout',
            self::HTTP_VERSION_NOT_SUPPORTED      => 'HTTP Version Not Supported',
            self::VARIANT_ALSO_NEGOTIATES         => 'Variant Also Negotiates',
            self::INSUFFICIENT_STORAGE            => 'Insufficient Storage',
            self::LOOP_DETECTED                   => 'Loop Detected',
            self::NOT_EXTENDED                    => 'Not Extended',
            self::NETWORK_AUTHENTICATION_REQUIRED => 'Network Authentication Required',
        };
    }

    /**
     * Returns a short human-readable label combining status code and reason.
     * e.g., "404 Not Found"
     */
    public function label(): string
    {
        return $this->value . ' ' . $this->reasonPhrase();
    }

    /**
     * Returns a StatusCode instance from a raw integer status code.
     * Returns null if the code is not in the enum (allows graceful handling of
     * non-standard codes returned by some servers).
     */
    public static function tryFromCode(int $code): ?self
    {
        return self::tryFrom($code);
    }

    /**
     * Returns a StatusCode from a raw integer, or throws for unknown codes.
     *
     * @throws \ValueError if the code is not a known status code.
     */
    public static function fromCode(int $code): self
    {
        return self::from($code);
    }
}
