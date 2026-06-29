<?php

declare(strict_types=1);

/**
 * License Pipeline — Verification Test
 *
 * Tests the full ultra-secure pipeline using real HTTPS servers as mock
 * license servers. All real-network tests use withoutSslVerification()
 * so they work on any PHP dev environment (no CA bundle required).
 */

require __DIR__ . '/vendor/autoload.php';

use WireHttp\Wire;
use WireHttp\Security\SodiumCryptoBox;
use WireHttp\Security\ResponseVerifier;
use WireHttp\Security\LicensePipeline;
use WireHttp\Security\Exception\SecurityException;

$pass = 0;
$fail = 0;

function test(string $name, \Closure $fn): void
{
    global $pass, $fail;
    echo "  Testing: {$name} ... ";
    try {
        $fn();
        echo "\033[32m✓ PASS\033[0m\n";
        $pass++;
    } catch (\Throwable $e) {
        echo "\033[31m✗ FAIL\033[0m\n";
        echo "    → " . get_class($e) . ": " . $e->getMessage() . "\n";
        $fail++;
    }
}

echo "\n";
echo "\033[1m╔══════════════════════════════════════════════════════════════╗\033[0m\n";
echo "\033[1m║     WireHTTP — Ultra-Secure License Pipeline Test Suite      ║\033[0m\n";
echo "\033[1m╚══════════════════════════════════════════════════════════════╝\033[0m\n\n";

// ─────────────────────────────────────────────────────────────────────────────
// Group 1: Basic Pipeline
// ─────────────────────────────────────────────────────────────────────────────
echo "\033[33m▶ Group 1: Basic Pipeline & Isolation\033[0m\n";

test('Wire::license() returns a LicensePipeline instance', function () {
    $pipeline = Wire::license('https://jsonplaceholder.typicode.com/posts');
    assert($pipeline instanceof LicensePipeline, 'Expected LicensePipeline instance');
});

test('Basic pipeline sends a real HTTPS GET request and gets a valid response', function () {
    $result = Wire::license('https://jsonplaceholder.typicode.com/posts/1')
        ->withMethod('GET')
        ->withoutAntiReplay()
        ->withoutSslVerification() // Dev machine — no CA bundle needed
        ->send();

    assert($result->isValid(), "Expected 2xx response, got: " . $result->status());
    assert(is_array($result->json()), 'Expected JSON array response');
    assert($result->get('id') === 1, "Expected id=1, got: " . json_encode($result->get('id')));
});

test('Pipeline injects anti-replay fields (_wire_timestamp, _wire_nonce) by default', function () {
    $result = Wire::license('https://postman-echo.com/post')
        ->withPayload(['license_key' => 'TEST-1234'])
        ->withoutSslVerification()
        ->send();

    assert($result->isValid(), "Expected 200 OK, got: " . $result->status());
    // postman-echo returns the parsed JSON body under the 'json' key
    $json   = $result->json();
    $posted = $json['json'] ?? json_decode($json['data'] ?? '{}', true) ?? [];

    assert(isset($posted['_wire_timestamp']), 'Expected _wire_timestamp in payload');
    assert(isset($posted['_wire_nonce']), 'Expected _wire_nonce in payload');
    assert(is_int($posted['_wire_timestamp']), 'Timestamp should be an integer');
    assert(strlen($posted['_wire_nonce']) === 32, 'Nonce should be 32 hex chars (16 bytes)');
});

test('Pipeline does NOT inject anti-replay fields when withoutAntiReplay() is called', function () {
    $result = Wire::license('https://postman-echo.com/post')
        ->withPayload(['license_key' => 'TEST-1234'])
        ->withoutAntiReplay()
        ->withoutSslVerification()
        ->send();

    assert($result->isValid(), "Expected 200 OK");
    $json   = $result->json();
    $posted = $json['json'] ?? json_decode($json['data'] ?? '{}', true) ?? [];

    assert(!isset($posted['_wire_timestamp']), '_wire_timestamp should NOT be injected');
    assert(!isset($posted['_wire_nonce']), '_wire_nonce should NOT be injected');
});

test('Pipeline returns LicensePipelineResult with correct status() and isValid()', function () {
    $result = Wire::license('https://jsonplaceholder.typicode.com/posts/1')
        ->withMethod('GET')
        ->withoutAntiReplay()
        ->withoutSslVerification()
        ->send();

    assert($result->status() === 200, "Expected status 200, got: " . $result->status());
    assert($result->isValid() === true, 'isValid() should return true for 200');
});

test('Result::get() supports dot-notation for nested keys', function () {
    $result = Wire::license('https://jsonplaceholder.typicode.com/posts/1')
        ->withMethod('GET')
        ->withoutAntiReplay()
        ->withoutSslVerification()
        ->send();

    assert(is_int($result->get('id')), 'Should get id via dot notation');
    $missing = $result->get('totally.missing.key', 'fallback');
    assert($missing === 'fallback', "Expected 'fallback', got: {$missing}");
});

test('Result::throwIfInvalid() does NOT throw on a 2xx response', function () {
    $result = Wire::license('https://jsonplaceholder.typicode.com/posts/1')
        ->withMethod('GET')
        ->withoutAntiReplay()
        ->withoutSslVerification()
        ->send();

    $returned = $result->throwIfInvalid();
    assert($returned === $result, 'throwIfInvalid() should return $this on success');
});

test('Result::throwIfInvalid() throws SecurityException on 4xx response', function () {
    $result = Wire::license('https://jsonplaceholder.typicode.com/posts/99999')
        ->withMethod('GET')
        ->withoutAntiReplay()
        ->withoutSslVerification()
        ->send();

    try {
        $result->throwIfInvalid();
        assert(false, 'Expected SecurityException but none was thrown');
    } catch (SecurityException $e) {
        assert(str_contains($e->getMessage(), '404'), "Expected 404 in message, got: " . $e->getMessage());
    }
});

// ─────────────────────────────────────────────────────────────────────────────
// Group 2: Custom Headers & Methods
// ─────────────────────────────────────────────────────────────────────────────
echo "\n\033[33m▶ Group 2: Custom Headers & Methods\033[0m\n";

test('withHeader() sends custom headers to the server', function () {
    $result = Wire::license('https://postman-echo.com/post')
        ->withPayload(['key' => 'val'])
        ->withHeader('X-Custom-App-ID', 'my-app-v1')
        ->withoutSslVerification()
        ->send();

    assert($result->isValid());
    $headers = $result->json()['headers'] ?? [];
    // Normalize to lowercase for case-insensitive comparison (postman-echo lowercases all)
    $headersLower = array_change_key_case($headers, CASE_LOWER);
    $found = $headersLower['x-custom-app-id'] ?? '';
    assert($found === 'my-app-v1', "Expected 'my-app-v1' in x-custom-app-id, got: " . json_encode($headersLower));
});

test('withMethod("PUT") sends a PUT request to the correct endpoint', function () {
    $result = Wire::license('https://postman-echo.com/put')
        ->withPayload(['update' => 'data'])
        ->withMethod('PUT')
        ->withoutSslVerification()
        ->send();

    assert($result->isValid());
    assert(str_contains($result->json()['url'] ?? '', '/put'), 'Expected PUT endpoint URL');
});

test('withField() merges individual fields into the payload', function () {
    $result = Wire::license('https://postman-echo.com/post')
        ->withField('license_key', 'ABCD-1234')
        ->withField('version', '2.0.1')
        ->withoutAntiReplay()
        ->withoutSslVerification()
        ->send();

    assert($result->isValid());
    $json   = $result->json();
    $posted = $json['json'] ?? json_decode($json['data'] ?? '{}', true) ?? [];
    assert($posted['license_key'] === 'ABCD-1234', 'Expected license_key in payload');
    assert($posted['version'] === '2.0.1', 'Expected version in payload');
});

// ─────────────────────────────────────────────────────────────────────────────
// Group 3: HMAC Request Signing
// ─────────────────────────────────────────────────────────────────────────────
echo "\n\033[33m▶ Group 3: HMAC Request Signing\033[0m\n";

test('signRequestWith() attaches X-Wire-Signature and X-Wire-Timestamp headers', function () {
    $result = Wire::license('https://postman-echo.com/post')
        ->withPayload(['key' => 'ABCD-1234'])
        ->signRequestWith('my-super-secret')
        ->withoutSslVerification()
        ->send();

    assert($result->isValid());
    $headers      = $result->json()['headers'] ?? [];
    $headersLower = array_change_key_case($headers, CASE_LOWER);

    assert(isset($headersLower['x-wire-signature']), 'Expected X-Wire-Signature header, got: ' . json_encode($headersLower));
    assert(isset($headersLower['x-wire-timestamp']), 'Expected X-Wire-Timestamp header');

    $sig = $headersLower['x-wire-signature'];
    assert(str_starts_with($sig, 'sha256='), "Expected 'sha256=' prefix, got: {$sig}");
});

test('HMAC signature is a valid base64-encoded SHA256 hash', function () {
    $result = Wire::license('https://postman-echo.com/post')
        ->withPayload(['key' => 'ABCD-1234'])
        ->signRequestWith('my-secret')
        ->withoutSslVerification()
        ->send();

    $headers      = $result->json()['headers'] ?? [];
    $headersLower = array_change_key_case($headers, CASE_LOWER);
    $sig     = $headersLower['x-wire-signature'] ?? '';
    $hash    = substr($sig, 7); // strip 'sha256='
    $decoded = base64_decode($hash, strict: true);
    assert($decoded !== false && strlen($decoded) === 32, 'SHA256 binary should be 32 bytes, got: ' . strlen((string)$decoded));
});

// ─────────────────────────────────────────────────────────────────────────────
// Group 4: Sodium Encryption
// ─────────────────────────────────────────────────────────────────────────────
echo "\n\033[33m▶ Group 4: Sodium Payload Encryption\033[0m\n";

if (extension_loaded('sodium')) {
    test('encryptWith() encrypts payload — server sees binary, NOT plaintext JSON', function () {
        $result = Wire::license('https://postman-echo.com/post')
            ->withPayload(['license_key' => 'SECRET-KEY-XYZ'])
            ->encryptWith('my-encryption-secret')
            ->withoutSslVerification()
            ->send();

        assert($result->isValid());
        $json = $result->json();
        // postman-echo puts raw body in 'data'; encrypted payload won't parse as JSON
        $data = $json['data'] ?? json_encode($json);

        assert(!str_contains($data, 'SECRET-KEY-XYZ'),
            'Encrypted payload must NOT contain plaintext license key');
        assert(!str_contains($data, '"license_key"'),
            'Encrypted payload must NOT contain plaintext JSON key');
        assert(strlen($data) > 0, 'Encrypted payload should not be empty');
    });

    test('SodiumCryptoBox encrypt/decrypt round-trip is lossless', function () {
        $box       = new SodiumCryptoBox('test-secret-key');
        $plaintext = json_encode(['license_key' => 'ABCD-1234', 'user_id' => 42]);
        $encrypted = $box->encrypt($plaintext);

        assert($encrypted !== $plaintext, 'Encrypted output must differ from plaintext');
        assert(!str_contains($encrypted, 'ABCD-1234'), 'Plaintext must not appear in ciphertext');

        $decrypted = $box->decrypt($encrypted);
        assert($decrypted === $plaintext, "Decrypted text must match original plaintext");
    });

    test('SodiumCryptoBox produces different ciphertext for identical plaintexts (fresh nonce)', function () {
        $box       = new SodiumCryptoBox('test-secret-key');
        $plaintext = '{"key":"same-data"}';

        $enc1 = $box->encrypt($plaintext);
        $enc2 = $box->encrypt($plaintext);

        assert($enc1 !== $enc2, 'Same plaintext must produce different ciphertext (fresh nonce each call)');
    });

    test('SodiumCryptoBox::decrypt() throws SecurityException on a single bit-flip tamper', function () {
        $box       = new SodiumCryptoBox('test-secret-key');
        $encrypted = $box->encrypt('{"key":"value"}');

        // Flip a bit in the middle of the payload
        $raw     = base64_decode($encrypted);
        $raw[30] = chr(ord($raw[30]) ^ 0xFF);
        $tampered = base64_encode($raw);

        try {
            $box->decrypt($tampered, 'https://test.com');
            assert(false, 'Expected SecurityException for tampered ciphertext');
        } catch (SecurityException $e) {
            assert(str_contains(strtolower($e->getMessage()), 'tampered'),
                "Got: " . $e->getMessage());
        }
    });

    test('SodiumCryptoBox can be instantiated when sodium IS loaded', function () {
        $box = new SodiumCryptoBox('any-secret');
        assert($box instanceof SodiumCryptoBox);
    });

} else {
    echo "  \033[33m⚠ Sodium extension not loaded — running sodium-not-loaded tests\033[0m\n";

    test('SecurityException::sodiumMissing() is thrown when sodium is NOT loaded', function () {
        try {
            new SodiumCryptoBox('secret');
            assert(false, 'Expected SecurityException');
        } catch (SecurityException $e) {
            assert(str_contains(strtolower($e->getMessage()), 'sodium'),
                "Expected sodium in message, got: " . $e->getMessage());
        }
    });
}

// ─────────────────────────────────────────────────────────────────────────────
// Group 5: ResponseVerifier
// ─────────────────────────────────────────────────────────────────────────────
echo "\n\033[33m▶ Group 5: Response Signature Verification\033[0m\n";

test('ResponseVerifier::withHmac() passes a valid HMAC-SHA256 response signature', function () {
    $secret    = 'my-server-secret';
    $body      = '{"status":"valid","plan":"pro"}';
    $signature = base64_encode(hash_hmac('sha256', $body, $secret, binary: true));

    $verifier = ResponseVerifier::withHmac($secret);
    $verifier->verify($body, ['X-Wire-Response-Signature' => $signature], 'https://test.com');
    // No exception = pass
});

test('ResponseVerifier::withHmac() throws SecurityException on invalid/forged signature', function () {
    $secret = 'my-server-secret';
    $body   = '{"status":"valid","plan":"pro"}';

    $verifier = ResponseVerifier::withHmac($secret);

    try {
        $verifier->verify($body, ['X-Wire-Response-Signature' => 'forged-garbage'], 'https://test.com');
        assert(false, 'Expected SecurityException');
    } catch (SecurityException $e) {
        assert(str_contains(strtolower($e->getMessage()), 'tampered') || str_contains(strtolower($e->getMessage()), 'signature'),
            "Got: " . $e->getMessage());
    }
});

test('ResponseVerifier throws SecurityException when signature header is missing', function () {
    $verifier = ResponseVerifier::withHmac('secret');

    try {
        $verifier->verify('{"status":"valid"}', [], 'https://test.com');
        assert(false, 'Expected SecurityException');
    } catch (SecurityException $e) {
        // Any SecurityException is acceptable here
        assert(strlen($e->getMessage()) > 0);
    }
});

test('ResponseVerifier rejects stale timestamps outside anti-replay tolerance window', function () {
    $secret    = 'my-server-secret';
    $body      = '{"status":"valid"}';
    $signature = base64_encode(hash_hmac('sha256', $body, $secret, binary: true));
    $stale     = (string)(time() - 600); // 10 minutes ago

    $verifier = ResponseVerifier::withHmac($secret, replayTolerance: 300);

    try {
        $verifier->verify($body, [
            'X-Wire-Response-Signature' => $signature,
            'X-Wire-Response-Timestamp' => $stale,
        ], 'https://test.com');
        assert(false, 'Expected SecurityException for stale timestamp');
    } catch (SecurityException $e) {
        assert(str_contains(strtolower($e->getMessage()), 'replay') || str_contains(strtolower($e->getMessage()), 'timestamp'),
            "Got: " . $e->getMessage());
    }
});

test('ResponseVerifier accepts valid fresh timestamps within tolerance window', function () {
    $secret    = 'my-server-secret';
    $body      = '{"status":"valid"}';
    $signature = base64_encode(hash_hmac('sha256', $body, $secret, binary: true));
    $fresh     = (string)time();

    $verifier = ResponseVerifier::withHmac($secret);
    $verifier->verify($body, [
        'X-Wire-Response-Signature' => $signature,
        'X-Wire-Response-Timestamp' => $fresh,
    ], 'https://test.com');
    // No exception = pass
});

if (extension_loaded('sodium')) {
    test('ResponseVerifier::withEd25519() verifies a valid Ed25519-signed response body', function () {
        $keypair   = sodium_crypto_sign_keypair();
        $publicKey = sodium_crypto_sign_publickey($keypair);
        $privateKey = sodium_crypto_sign_secretkey($keypair);

        $body      = '{"status":"valid","plan":"enterprise"}';
        $sigBytes  = sodium_crypto_sign_detached($body, $privateKey);
        $signature = base64_encode($sigBytes);
        $pubKeyB64 = base64_encode($publicKey);

        $verifier = ResponseVerifier::withEd25519($pubKeyB64);
        $verifier->verify($body, ['X-Wire-Response-Signature' => $signature], 'https://test.com');
        // No exception = pass
    });

    test('ResponseVerifier::withEd25519() rejects a tampered response body', function () {
        $keypair   = sodium_crypto_sign_keypair();
        $publicKey = sodium_crypto_sign_publickey($keypair);
        $privateKey = sodium_crypto_sign_secretkey($keypair);

        $originalBody = '{"status":"valid","plan":"enterprise"}';
        $tamperedBody = '{"status":"valid","plan":"free"}'; // Attacker changed plan

        $sigBytes  = sodium_crypto_sign_detached($originalBody, $privateKey);
        $signature = base64_encode($sigBytes);
        $pubKeyB64 = base64_encode($publicKey);

        $verifier = ResponseVerifier::withEd25519($pubKeyB64);

        try {
            $verifier->verify($tamperedBody, ['X-Wire-Response-Signature' => $signature], 'https://test.com');
            assert(false, 'Expected SecurityException for tampered body');
        } catch (SecurityException $e) {
            // Any SecurityException = tamper was caught
            assert(strlen($e->getMessage()) > 0);
        }
    });
}

// ─────────────────────────────────────────────────────────────────────────────
// Group 6: Pipeline Isolation & Security Context
// ─────────────────────────────────────────────────────────────────────────────
echo "\n\033[33m▶ Group 6: Pipeline Isolation & SecurityException\033[0m\n";

test('Wire::license() pipeline is completely isolated from the global Wire fake transport', function () {
    $mock = Wire::fake();
    $mock->getQueue()->push(fn() => new \WireHttp\Http\Response(999));

    // The license pipeline must NOT consume the queued fake response
    $result = Wire::license('https://jsonplaceholder.typicode.com/posts/1')
        ->withMethod('GET')
        ->withoutAntiReplay()
        ->withoutSslVerification()
        ->send();

    assert($result->status() !== 999, 'Pipeline must NOT use the global fake transport');
    assert($result->isValid(), "Expected real 200 response, got: " . $result->status());

    Wire::restoreFake();
});

test('SecurityException named constructors produce meaningful, attack-safe messages', function () {
    $pinEx     = SecurityException::pinMismatch('https://test.com');
    $replayEx  = SecurityException::replayDetected('https://test.com', 600);
    $decryptEx = SecurityException::decryptionFailed('https://test.com');
    $sodiumEx  = SecurityException::sodiumMissing();

    assert(str_contains($pinEx->getMessage(), 'MITM'), 'Pin mismatch should mention MITM');
    assert(str_contains($replayEx->getMessage(), '600s'), 'Replay should mention delta seconds');
    assert(str_contains($decryptEx->getMessage(), 'tampered'), 'Decrypt fail should mention tamper');
    assert(str_contains($sodiumEx->getMessage(), 'sodium'), 'Missing sodium should mention the extension');
});

test('SecurityException::getSecurityContext() returns internal debug data', function () {
    $e = SecurityException::pinMismatch('https://test.com');

    $ctx = $e->getSecurityContext();
    assert($ctx['reason'] === 'pin_mismatch', 'Context should contain reason');
    assert($ctx['url'] === 'https://test.com', 'Context should contain url');
});

test('SecurityException messages do NOT expose internal context keys to end users', function () {
    $e = SecurityException::pinMismatch('https://test.com');
    assert(!str_contains($e->getMessage(), '"reason"'), 'Message should not expose raw context keys');
});

// ─────────────────────────────────────────────────────────────────────────────
// Summary
// ─────────────────────────────────────────────────────────────────────────────
$total = $pass + $fail;
$color = $fail === 0 ? "\033[32m" : "\033[31m";

echo "\n\033[1m╔══════════════════════════════════════════════════════════════╗\033[0m\n";
printf("\033[1m║  Results: %s%d/%d passed\033[0m\033[1m, %d failed%-21s║\033[0m\n",
    $color, $pass, $total, $fail, "\033[0m");
echo "\033[1m╚══════════════════════════════════════════════════════════════╝\033[0m\n\n";

exit($fail > 0 ? 1 : 0);
