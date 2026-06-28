<div align="center">
  <br />
  <img src="https://upload.wikimedia.org/wikipedia/commons/2/27/PHP-logo.svg" alt="PHP" width="120" />
  <h1 align="center">WireHTTP</h1>
  <p align="center">
    <strong>An Enterprise-Grade, Dependency-Free Asynchronous HTTP Client for PHP 8.1+</strong>
  </p>
  <p align="center">
    <i>Developed by <b>Anant</b></i>
  </p>

  <p align="center">
    <a href="https://php.net/"><img src="https://img.shields.io/badge/PHP-%5E8.1-777BB4?style=flat-square&logo=php&logoColor=white" alt="PHP Version" /></a>
    <a href="#"><img src="https://img.shields.io/badge/Dependencies-ZERO-success?style=flat-square" alt="Zero Dependencies" /></a>
    <a href="#"><img src="https://img.shields.io/badge/Concurrency-PHP_Fibers-FF9900?style=flat-square&logo=lightning" alt="PHP Fibers" /></a>
    <a href="#"><img src="https://img.shields.io/badge/Architecture-PSR--7%20%7C%20PSR--15-blueviolet?style=flat-square" alt="Architecture" /></a>
    <a href="#"><img src="https://img.shields.io/badge/License-MIT-blue?style=flat-square" alt="License" /></a>
  </p>
</div>

---

<br />

> **WireHTTP** brings true non-blocking concurrency, intelligent fault tolerance, and beautiful object hydration to modern PHP applications. It is engineered from the ground up to handle massive workloads without dragging a single external dependency into your vendor folder.

<br />

## 📊 Feature Matrix

<div align="center">
  <table>
    <tr>
      <td align="center" width="33%">
        <h3>⚡ Ultra-Fast</h3>
        <p>Built natively on <code>ext-curl</code>. Send thousands of requests concurrently using Fibers and <code>curl_multi</code>.</p>
      </td>
      <td align="center" width="33%">
        <h3>🛡️ Resilient</h3>
        <p>Built-in <b>Circuit Breakers</b>, proactive Rate Limiting, and Exponential Backoff Retries.</p>
      </td>
      <td align="center" width="33%">
        <h3>🧬 Intelligent</h3>
        <p>Automatically decodes JSON/XML and directly hydrates nested <b>DTO Objects</b>.</p>
      </td>
    </tr>
  </table>
</div>

<br />

## 🏛️ Architecture & Data Flow

WireHTTP's core is an ultra-fast, array-based `MiddlewareStack`. Requests flow symmetrically through Interceptors, hitting the Transport layer, and returning as beautifully decorated objects.

```mermaid
graph TD
    %% Styling
    classDef facade fill:#2D3748,stroke:#4A5568,stroke-width:2px,color:#fff,rx:10,ry:10;
    classDef middleware fill:#3182CE,stroke:#2B6CB0,stroke-width:2px,color:#fff,rx:10,ry:10;
    classDef network fill:#38A169,stroke:#2F855A,stroke-width:2px,color:#fff,rx:10,ry:10;
    classDef target fill:#E53E3E,stroke:#C53030,stroke-width:2px,color:#fff,rx:10,ry:10;

    subgraph Application Space
        A[Developer API<br/><code>Wire::get()</code>]:::facade
    end

    subgraph WireHTTP Pipeline
        B[Middleware Stack<br/>(Rate Limit, Retry, Circuit Breaker)]:::middleware
        C[Transport Layer<br/><code>ext-curl</code> / Fibers]:::network
        D[Response Decorator<br/>JSON / XML / DTO Hydration]:::middleware
    end

    subgraph External
        E((Remote API)):::target
    end

    A ==>|Build Request| B
    B ==>|Apply Interceptors| C
    C ==>|Execute Asynchronously| E
    E -.->|Raw HTTP Response| C
    C -.->|Reverse Interceptors| D
    D ==>|Hydrated Object| A
```

<br />

## 💻 Developer Experience (DX)

<details open>
<summary><b>1. Clean & Fluent Request Builder</b></summary>
<br/>

No more massive configuration arrays. Construct your requests using a highly readable, chainable API.

```php
use WireHttp\Wire;

$response = Wire::post('https://api.example.com/billing')
    ->withBearer('secret_token')
    ->withHeader('X-Client', 'MobileApp')
    ->withJson(['plan' => 'enterprise', 'users' => 500])
    ->timeout(5.0) // Request-specific timeout override
    ->send();

if ($response->isClientError()) {
    throw new Exception($response->json()['message']);
}
```
</details>

<details open>
<summary><b>2. True Asynchronous Concurrency</b></summary>
<br/>

Send massive batches of requests concurrently. WireHTTP uses `Fiber` under the hood—no callbacks required, your code stays linear.

```php
use WireHttp\Wire;
use WireHttp\Async\Future;

// Dispatch 3 non-blocking network requests
$users   = Wire::get('https://api.example.com/users')->sendAsync();
$stats   = Wire::get('https://api.example.com/stats')->sendAsync();
$reports = Wire::get('https://api.example.com/reports')->sendAsync();

// Block the current Fiber until ALL requests settle
[$resUsers, $resStats, $resReports] = Future::all(...[$users, $stats, $reports]);

echo $resUsers->status(); // 200
```
</details>

<details open>
<summary><b>3. Direct DTO Hydration</b></summary>
<br/>

Skip manual array parsing. WireHTTP maps JSON directly to strongly-typed PHP objects.

```php
use WireHttp\Response\Hydrator\Attributes\JsonProperty;

class ProfileDto {
    public int $id;
    
    // Map mismatched JSON keys seamlessly
    #[JsonProperty('avatar_url')]
    public string $avatarUrl;
}

// Automatically decodes JSON, validates types, and returns the DTO!
$profile = Wire::get('https://api.example.com/me')->send()->into(ProfileDto::class);

echo $profile->avatarUrl;
```
</details>

<details>
<summary><b>4. Stateful Cookie & Redirect Interceptors</b></summary>
<br/>

WireHTTP behaves like a real browser when you want it to.

```php
use WireHttp\Wire;
use WireHttp\Configuration\ClientConfig;
use WireHttp\Middleware\Core\CookieJar;

$client = Wire::with(
    ClientConfig::create()
        ->withFollowRedirects(true, maxRedirects: 5)
        ->withCookies(true, new CookieJar())
);

// 1. Logs in and receives a Set-Cookie header
$client->post('/login')->withForm(['user' => 'admin'])->send();

// 2. Automatically injects the Cookie into subsequent requests!
$dashboard = $client->get('/dashboard')->send()->body();
```
</details>

<br />

## 🧪 Zero-Network Mocking Engine

Testing external APIs shouldn't require an internet connection or fragile mock objects. WireHTTP includes a powerful global mock interceptor.

```php
use WireHttp\Wire;
use WireHttp\Transport\Mock\MockResponseQueue;
use WireHttp\Http\Response;
use WireHttp\Http\Stream;

$queue = new MockResponseQueue();
$queue->push(new Response(201, [], Stream::fromString('{"status": "created"}')));

// Hijack the transport layer globally
Wire::fake($queue);

// This executes instantly and returns the mock response!
$response = Wire::post('https://api.example.com/charge')->send();

assert($response->json()['status'] === 'created');
```

<br />

---

<div align="center">
  <p><b>WireHTTP</b> - Engineered for Resilience, Built for Speed.</p>
  <p>Released under the <a href="LICENSE">MIT License</a>.</p>
</div>
