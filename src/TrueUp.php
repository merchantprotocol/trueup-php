<?php

declare(strict_types=1);

namespace TrueUp;

use TrueUp\Exception\AuthenticationException;
use TrueUp\Exception\ConnectionException;
use TrueUp\Exception\InvalidRequestException;
use TrueUp\Exception\NotFoundException;
use TrueUp\Exception\QuotaExceededException;
use TrueUp\Exception\RateLimitException;
use TrueUp\Exception\ServerException;
use TrueUp\Exception\TrueUpException;

/**
 * TrueUp API client.
 *
 *     $trueup = new \TrueUp\TrueUp();                   // reads TRUEUP_API_KEY
 *     $result = $trueup->reconcile('statement.csv', 'receiving.csv');
 *     foreach ($result['findings'] as $f) {
 *         echo $f['kind'], ' ', $f['subject'], ' ', $f['detail'], "\n";
 *     }
 */
final class TrueUp
{
    public const VERSION = '0.1.0';
    public const DEFAULT_BASE_URL = 'https://trueup-cloud.merchantprotocol.workers.dev';

    private string $apiKey;
    public readonly string $baseUrl;

    /**
     * @param string|null $apiKey     defaults to the TRUEUP_API_KEY environment variable
     * @param string|null $baseUrl    defaults to TRUEUP_BASE_URL, then the hosted API
     * @param int         $timeout    seconds per request (big ledgers take a while)
     * @param int         $maxRetries retries for rate limits, server errors and dropped connections
     */
    public function __construct(
        ?string $apiKey = null,
        ?string $baseUrl = null,
        private readonly int $timeout = 300,
        private readonly int $maxRetries = 2,
    ) {
        $key = $apiKey ?: (getenv('TRUEUP_API_KEY') ?: null);
        if (!$key) {
            throw new AuthenticationException(
                'No API key: pass $apiKey or set TRUEUP_API_KEY. Create one in the TrueUp dashboard under API keys.',
                0,
                'missing_api_key',
            );
        }
        $this->apiKey = $key;
        $this->baseUrl = rtrim($baseUrl ?: (getenv('TRUEUP_BASE_URL') ?: self::DEFAULT_BASE_URL), '/');
    }

    /** The team, plan and key behind this client's API key. */
    public function account(): array
    {
        return $this->request('GET', '/v1/account');
    }

    /** This month's usage for the key's team. */
    public function usage(): array
    {
        return $this->request('GET', '/v1/usage');
    }

    /** The plans a team can be on. */
    public function plans(): array
    {
        return $this->request('GET', '/v1/plans')['plans'];
    }

    /**
     * Reconcile two tables. $left is the side that bills or claims (a statement, your books), $right the other side
     * (receiving log, bank feed): a path or a Table. Counts as one analysis.
     *
     * @param array|null $weights details['weights'] from an earlier result, to apply instead of learning again
     * @param array|null $answers ['same' => [[left row, right row]], 'different' => [...]]: decisions a person made
     */
    public function reconcile(string|Table $left, string|Table $right, ?array $weights = null, ?array $answers = null): array
    {
        $l = $left instanceof Table ? $left : Table::file($left);
        $r = $right instanceof Table ? $right : Table::file($right);
        if ($l->isRows() && $r->isRows()) {
            $body = ['left' => ['name' => $l->name, 'rows' => $l->getRows()], 'right' => ['name' => $r->name, 'rows' => $r->getRows()]];
            if ($weights !== null) {
                $body['weights'] = $weights;
            }
            if ($answers !== null) {
                $body['answers'] = $answers;
            }
            return $this->request('POST', '/v1/reconcile', json: $body);
        }
        $parts = [['left', ...$l->asFile()], ['right', ...$r->asFile()]];
        return $this->request('POST', '/v1/reconcile', parts: $parts, fields: self::options($weights, $answers));
    }

    /**
     * Send two or more files; TrueUp picks the pair to reconcile and which side is which. One analysis.
     *
     * @param list<string|Table> $files
     */
    public function reconcileFiles(array $files, ?array $weights = null, ?array $answers = null): array
    {
        $parts = [];
        foreach ($files as $f) {
            $parts[] = ['files', ...($f instanceof Table ? $f : Table::file($f))->asFile()];
        }
        return $this->request('POST', '/v1/reconcile', parts: $parts, fields: self::options($weights, $answers));
    }

    // ---------------------------------------------------------------- transport

    private static function options(?array $weights, ?array $answers): array
    {
        $out = [];
        if ($weights !== null) {
            $out['weights'] = json_encode($weights, JSON_THROW_ON_ERROR);
        }
        if ($answers !== null) {
            $out['answers'] = json_encode($answers, JSON_THROW_ON_ERROR);
        }
        return $out;
    }

    /**
     * @param list<array{0: string, 1: string, 2: string}> $parts  [field, file name, bytes]
     * @param array<string, string>                          $fields
     */
    private function request(string $method, string $path, ?array $json = null, array $parts = [], array $fields = []): array
    {
        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
            'Accept: application/json',
            'User-Agent: trueup-php/' . self::VERSION,
        ];
        $payload = null;
        if ($json !== null) {
            $headers[] = 'Content-Type: application/json';
            $payload = json_encode($json, JSON_THROW_ON_ERROR);
        } elseif ($parts) {
            $boundary = '----trueup' . bin2hex(random_bytes(12));
            $headers[] = 'Content-Type: multipart/form-data; boundary=' . $boundary;
            $payload = self::multipart($boundary, $parts, $fields);
        }
        for ($attempt = 0; ; $attempt++) {
            $ch = curl_init($this->baseUrl . $path);
            $responseHeaders = [];
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $this->timeout,
                CURLOPT_CONNECTTIMEOUT => 30,
                CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$responseHeaders): int {
                    $p = strpos($line, ':');
                    if ($p !== false) {
                        $responseHeaders[strtolower(trim(substr($line, 0, $p)))] = trim(substr($line, $p + 1));
                    }
                    return strlen($line);
                },
            ]);
            if ($payload !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            }
            $raw = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);
            if ($raw === false || $status === 0) {
                if ($attempt < $this->maxRetries) {
                    usleep(self::backoff($attempt));
                    continue;
                }
                throw new ConnectionException("Couldn't reach TrueUp at {$this->baseUrl}: {$curlError}");
            }
            $data = json_decode((string) $raw, true);
            if ($status >= 200 && $status < 300) {
                return is_array($data) ? $data : [];
            }
            $err = is_array($data) && isset($data['error']) && is_array($data['error']) ? $data['error'] : [];
            $error = self::errorFor(
                $status,
                (string) ($err['code'] ?? "http_{$status}"),
                (string) ($err['message'] ?? "HTTP {$status}"),
                $data ?? $raw,
                $responseHeaders['retry-after'] ?? null,
            );
            if (($error instanceof RateLimitException || $error instanceof ServerException) && $attempt < $this->maxRetries) {
                $wait = $error instanceof RateLimitException && $error->retryAfter ? (int) ($error->retryAfter * 1_000_000) : self::backoff($attempt);
                usleep($wait);
                continue;
            }
            throw $error;
        }
    }

    private static function errorFor(int $status, string $code, string $message, mixed $body, ?string $retryAfter): TrueUpException
    {
        if ($status === 401) {
            return new AuthenticationException($message, $status, $code, $body);
        }
        if ($status === 429 && $code === 'quota_exceeded') {
            return new QuotaExceededException($message, $status, $code, $body);
        }
        if ($status === 429) {
            $e = new RateLimitException($message, $status, $code, $body);
            $e->retryAfter = $retryAfter !== null ? (float) $retryAfter : null;
            return $e;
        }
        if ($status === 404 || $status === 405) {
            return new NotFoundException($message, $status, $code, $body);
        }
        if ($status >= 500) {
            return new ServerException($message, $status, $code, $body);
        }
        return new InvalidRequestException($message, $status, $code, $body);
    }

    private static function multipart(string $boundary, array $parts, array $fields): string
    {
        $body = '';
        foreach ($fields as $name => $value) {
            $body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$name}\"\r\n\r\n{$value}\r\n";
        }
        foreach ($parts as [$field, $filename, $bytes]) {
            $safe = str_replace(['"', "\r", "\n"], '_', $filename);
            $body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$field}\"; filename=\"{$safe}\"\r\n"
                . "Content-Type: application/octet-stream\r\n\r\n{$bytes}\r\n";
        }
        return $body . "--{$boundary}--\r\n";
    }

    /** Microseconds to wait before retry $attempt. */
    private static function backoff(int $attempt): int
    {
        return (int) (min(30.0, 2 ** $attempt) * (0.5 + mt_rand() / mt_getrandmax() / 2) * 1_000_000);
    }
}
