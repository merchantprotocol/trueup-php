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

    // ---------------------------------------------------------------- match

    /**
     * Match two lists that describe the same things in different words (two catalogs, a price book and an invoice):
     * each record on $left (the list to go through) is paired with its counterpart on $right (the list to search), or
     * reported as having none. A path or a Table. One analysis.
     *
     * @param array|null $weights details['weights'] from an earlier match, to apply instead of learning again
     */
    public function match(string|Table $left, string|Table $right, ?array $weights = null): array
    {
        $l = $left instanceof Table ? $left : Table::file($left);
        $r = $right instanceof Table ? $right : Table::file($right);
        if ($l->isRows() && $r->isRows()) {
            $body = ['left' => ['name' => $l->name, 'rows' => $l->getRows()], 'right' => ['name' => $r->name, 'rows' => $r->getRows()]];
            if ($weights !== null) {
                $body['weights'] = $weights;
            }
            return $this->request('POST', '/v1/match', json: $body);
        }
        $parts = [['left', ...$l->asFile()], ['right', ...$r->asFile()]];
        return $this->request('POST', '/v1/match', parts: $parts, fields: self::options($weights, null));
    }

    /**
     * Send two or more lists; TrueUp picks the pair to match and puts the shorter on the left. One analysis.
     *
     * @param list<string|Table> $files
     */
    public function matchFiles(array $files, ?array $weights = null): array
    {
        $parts = [];
        foreach ($files as $f) {
            $parts[] = ['files', ...($f instanceof Table ? $f : Table::file($f))->asFile()];
        }
        return $this->request('POST', '/v1/match', parts: $parts, fields: self::options($weights, null));
    }

    /**
     * Match lists already stored in the team, by id. $model applies a saved match model. The run is kept ('run_id').
     *
     * @param list<string>|null $fileIds
     */
    public function matchStored(?string $leftFileId = null, ?string $rightFileId = null, ?array $fileIds = null, ?string $model = null): array
    {
        return $this->stored('/v1/match', $leftFileId, $rightFileId, $fileIds, $model, null);
    }

    // ---------------------------------------------------------------- audit

    /**
     * Find what doesn't add up. Text documents (invoices, statements, 4 or more of a kind): TrueUp learns the
     * arithmetic each kind obeys and flags the ones that break it. One table: the same for its rows, plus repeated
     * rows. $weights (details['weights'] of an earlier audit) checks new documents against the same laws. One analysis.
     *
     * @param list<string|Table> $files
     */
    public function audit(array $files, ?array $weights = null): array
    {
        if (!$files) {
            throw new InvalidRequestException('Pass the documents (or one table) to audit.', 0, 'invalid_request', null);
        }
        $parts = [];
        foreach ($files as $f) {
            $parts[] = ['files', ...($f instanceof Table ? $f : Table::file($f))->asFile()];
        }
        return $this->request('POST', '/v1/audit', parts: $parts, fields: self::options($weights, null));
    }

    /**
     * Audit files already stored in the team, by id. $model applies a saved audit model. The run is kept ('run_id').
     *
     * @param list<string> $fileIds
     */
    public function auditStored(array $fileIds, ?string $model = null): array
    {
        return $this->stored('/v1/audit', null, null, $fileIds, $model, null);
    }

    // ---------------------------------------------------------------- estimate

    /**
     * Price a new job from past estimates: a domain file for the trade (.tu), at least 3 past estimates in any
     * format, and one request describing the new job; or, with $weights (details['weights'] of an earlier estimate),
     * just the request. One analysis.
     *
     * @param list<string|Table> $files
     */
    public function estimate(array $files, ?array $weights = null): array
    {
        if (!$files) {
            throw new InvalidRequestException('Pass the domain file, past estimates and the request.', 0, 'invalid_request', null);
        }
        $parts = [];
        foreach ($files as $f) {
            $parts[] = ['files', ...($f instanceof Table ? $f : Table::file($f))->asFile()];
        }
        return $this->request('POST', '/v1/estimate', parts: $parts, fields: self::options($weights, null));
    }

    /**
     * Price from files already stored in the team, by id. $model applies a saved estimate model.
     *
     * @param list<string> $fileIds
     */
    public function estimateStored(array $fileIds, ?string $model = null): array
    {
        return $this->stored('/v1/estimate', null, null, $fileIds, $model, null);
    }

    // ---------------------------------------------------------------- stored files, runs, saved models

    /**
     * Upload one or more files (paths or Tables) to the team. Each comes back with its id, rows, columns and roles
     * (what TrueUp read each column as).
     *
     * @return list<array>
     */
    public function uploadFiles(string|Table ...$files): array
    {
        if (!$files) {
            throw new InvalidRequestException('Pass at least one file to upload.', 0, 'invalid_request');
        }
        $parts = [];
        foreach ($files as $f) {
            $parts[] = ['file', ...($f instanceof Table ? $f : Table::file($f))->asFile()];
        }
        return $this->request('POST', '/v1/files', parts: $parts)['files'];
    }

    /** @return list<array> The team's stored files. */
    public function listFiles(): array
    {
        return $this->request('GET', '/v1/files')['files'];
    }

    public function getFile(string $id): array
    {
        return $this->request('GET', '/v1/files/' . rawurlencode($id))['file'];
    }

    /** The file's bytes, exactly as uploaded. */
    public function fileContent(string $id): string
    {
        return $this->request('GET', '/v1/files/' . rawurlencode($id) . '/content', binary: true);
    }

    public function deleteFile(string $id): void
    {
        $this->request('DELETE', '/v1/files/' . rawurlencode($id));
    }

    /**
     * Reconcile files already stored in the team, by id: two ids (left bills or claims), or pass $fileIds for TrueUp
     * to pick the pair. $model applies a saved model instead of learning. The run is kept: its id is 'run_id' in the
     * result. One analysis.
     *
     * @param list<string>|null $fileIds
     */
    public function reconcileStored(
        ?string $leftFileId = null,
        ?string $rightFileId = null,
        ?array $fileIds = null,
        ?string $model = null,
        ?array $answers = null,
    ): array {
        return $this->stored('/v1/reconcile', $leftFileId, $rightFileId, $fileIds, $model, $answers);
    }

    private function stored(string $path, ?string $leftFileId, ?string $rightFileId, ?array $fileIds, ?string $model, ?array $answers): array
    {
        if ($fileIds !== null) {
            $body = ['file_ids' => array_values($fileIds)];
        } elseif ($leftFileId !== null && $rightFileId !== null) {
            $body = ['left_file_id' => $leftFileId, 'right_file_id' => $rightFileId];
        } else {
            throw new InvalidRequestException('Pass leftFileId and rightFileId, or fileIds.', 0, 'invalid_request');
        }
        if ($model !== null) {
            $body['model'] = $model;
        }
        if ($answers !== null) {
            $body['answers'] = $answers;
        }
        return $this->request('POST', $path, json: $body);
    }

    /**
     * One page of runs on stored files, newest first: ['runs' => [...], 'has_more' => bool].
     * $limit is 1-100; $before is a run id.
     */
    public function listRuns(?int $limit = null, ?string $before = null): array
    {
        $query = http_build_query(array_filter(['limit' => $limit, 'before' => $before], fn ($v) => $v !== null));
        return $this->request('GET', '/v1/runs' . ($query !== '' ? "?{$query}" : ''));
    }

    /** Every run, fetching page after page. */
    public function allRuns(): \Generator
    {
        $before = null;
        do {
            $page = $this->listRuns(100, $before);
            yield from $page['runs'];
            $before = $page['runs'] ? $page['runs'][count($page['runs']) - 1]['id'] : null;
        } while ($page['has_more'] && $before !== null);
    }

    /** ['run' => [...], 'result' => [...]]: the result has the same shape reconcile() returns. */
    public function getRun(string $id): array
    {
        return $this->request('GET', '/v1/runs/' . rawurlencode($id));
    }

    /** Save what a run learned as a model. Returns the model id. */
    public function createModel(string $runId, ?string $name = null): string
    {
        return $this->request('POST', '/v1/models', json: array_filter(['run_id' => $runId, 'name' => $name], fn ($v) => $v !== null))['id'];
    }

    /** @return list<array> */
    public function listModels(): array
    {
        return $this->request('GET', '/v1/models')['models'];
    }

    /** One saved model, including its 'weights'. */
    public function getModel(string $id): array
    {
        return $this->request('GET', '/v1/models/' . rawurlencode($id))['model'];
    }

    public function deleteModel(string $id): void
    {
        $this->request('DELETE', '/v1/models/' . rawurlencode($id));
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
    private function request(string $method, string $path, ?array $json = null, array $parts = [], array $fields = [], bool $binary = false): array|string
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
            if ($binary && $status >= 200 && $status < 300) {
                return (string) $raw;
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
