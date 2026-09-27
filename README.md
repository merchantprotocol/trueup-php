# TrueUp for PHP

The official client for the [TrueUp API](https://trueup-cloud.merchantprotocol.workers.dev/docs). Send TrueUp two ledgers (a supplier statement and your receiving log, your books and the bank feed, invoices and payments) and it pairs every row, then tells you what's only on one side, what was counted twice and where the numbers disagree.

PHP 8.1+ with the curl and json extensions. No other dependencies.

## Install

```bash
composer require trueup/trueup
```

## Quickstart

Create an API key in the TrueUp dashboard (**API keys**), then set `TRUEUP_API_KEY`:

```php
use TrueUp\TrueUp;

$trueup = new TrueUp(); // reads TRUEUP_API_KEY

$result = $trueup->reconcile('statement.csv', 'receiving.csv'); // left: the side that bills or claims

echo $result['headline'], "\n";
// 7 of 8 rows of statement.csv paired with receiving.csv; 1 only in statement.csv, ...
foreach ($result['findings'] as $f) {
    echo "{$f['kind']} {$f['subject']} {$f['detail']} {$f['amount']}\n";
}
// qty_mismatch statement.csv:row 5 Qty 24 vs qty_received 20; ... 99.6
// phantom statement.csv:row 6 no match on the other side 43.2
```

## Reconcile

A table is a path, file contents, or rows:

```php
use TrueUp\Table;

$trueup->reconcile('books.csv', 'bank.csv');
$trueup->reconcile(Table::content('books.csv', $csv), Table::content('bank.csv', $bytes));
$trueup->reconcile(
    Table::rows('invoices', [['Invoice #' => 'INV-10101', 'Date' => '2026-06-09', 'Total' => '$2,999.31']]),
    Table::rows('payments', [['Received' => '2026-07-01', 'From' => 'ACME CONSTR', 'Amount' => '2999.31']]),
);
```

CSV, TSV, JSON and JSON Lines are read, and date and number formats are detected. Nothing about the columns is configured.

Not sure which file is which? `$trueup->reconcileFiles(['a.csv', 'b.csv'])` picks the pair and the sides.

**Reuse what was learned.** Pass an earlier result's weights to reconcile next month's files the same way, without learning again:

```php
$march = $trueup->reconcile('march-statement.csv', 'march-receiving.csv');
$april = $trueup->reconcile('april-statement.csv', 'april-receiving.csv', weights: $march['details']['weights']);
```

**Answer the questions.** Findings with `status` `unsure` need a person. Send the decisions back:

```php
$trueup->reconcile('statement.csv', 'receiving.csv', answers: [
    'same' => [['statement.csv:row 12', 'receiving.csv:row 11']],
    'different' => [['statement.csv:row 3', 'receiving.csv:row 9']],
]);
```

Each call to `reconcile` or `reconcileFiles` counts as one analysis on your plan.

## Match

Two lists that describe the same things in different words (two catalogs, a supplier's price book and your invoice, two vendor lists): every record on the left is paired with its counterpart on the right, or reported as having none. Nothing is configured; the columns can have different names.

```php
$result = $trueup->match('invoice.csv', 'catalog.csv');
echo $result['headline'];
// 4 of 5 records in invoice.csv matched to catalog.csv (0 unsure); 1 have no counterpart.
foreach ($result['findings'] as $f) {
    echo "{$f['kind']} {$f['subject']} {$f['detail']}\n";
}
// match 4 ~ 5 4 · cheese puffs jumbo 8oz · 3.30 · 10  ↔  C-105 · Cheese Puffs Jumbo 8 oz · 3.25
// only_left 5 5 · beef jerky teriyaki 2.5oz · 5.75 · 6
```

`kind` is `match`, `unsure_match` (a person should check), `only_left` or `only_right`. `details['pairs']` lists `[left id, right id, confidence]`. Like `reconcile`, it takes paths or `Table`s; `matchFiles([...])` picks the pair; `matchStored($leftId, $rightId, model: ...)` works on stored files; and `details['weights']` can be passed back as `weights:` to match next month's lists the same way. One analysis per call.

## Stored files, runs and saved models

Files uploaded to your team stay there (you'll also see them in the dashboard). Runs on stored files are kept, and what a run learned can be saved as a model:

```php
[$statement, $receiving] = $trueup->uploadFiles('statement.csv', 'receiving.csv');
$statement['rows'];    // 8
$statement['roles'];   // ['Inv Date' => 'date', 'Qty' => 'number', ...]

$result = $trueup->reconcileStored($statement['id'], $receiving['id']);
$modelId = $trueup->createModel($result['run_id'], 'Acme statements');

// Next month: apply what was learned.
$trueup->reconcileStored(fileIds: [$aprilStatement['id'], $aprilReceiving['id']], model: $modelId);
```

| Method | Returns |
|---|---|
| `uploadFiles(...$files)`, `listFiles()`, `getFile($id)` | stored files: `id`, `name`, `rows`, `columns`, `roles` |
| `fileContent($id)` | the bytes, exactly as uploaded |
| `deleteFile($id)` | |
| `reconcileStored($leftId, $rightId)` or `reconcileStored(fileIds: [...])`, with `model:`, `answers:` | a result plus `run_id` (one analysis) |
| `listRuns($limit, $before)` | `['runs' => [...], 'has_more' => bool]`, newest first |
| `allRuns()` | every run (a generator that pages for you) |
| `getRun($id)` | `['run' => ..., 'result' => ...]` |
| `createModel($runId, $name)`, `listModels()`, `getModel($id)`, `deleteModel($id)` | `getModel` includes the `weights` |

## Findings

| `kind` | Meaning |
|---|---|
| `phantom` | Only on the left: billed or recorded, never matched |
| `unbilled` | Only on the right: received or paid, never billed |
| `duplicate`, `received_duplicate` | A copy of a row that's already paired |
| `qty_mismatch`, `price_change`, `amount_mismatch` | Paired rows whose numbers disagree |
| `unsure_pair` | A likely pair a person should confirm |

## Account and usage

```php
$trueup->account(); // ['team' => ..., 'plan' => ..., 'key' => ...]
$trueup->usage();   // ['period' => ..., 'metrics' => [...]]
$trueup->plans();
```

## Errors

Every error is a `TrueUp\Exception\TrueUpException` with `getStatus()` and `getErrorCode()` (the API's error code):

| Class | When |
|---|---|
| `AuthenticationException` | 401: missing, unknown or revoked key |
| `InvalidRequestException` | 400, 413, 415, 422: the request or the files need fixing (`unsupported_file`, `not_reconcilable`, ...) |
| `RateLimitException` | 429 `rate_limited`: retried automatically; `$retryAfter` seconds |
| `QuotaExceededException` | 429 `quota_exceeded`: the plan's monthly allowance is used up |
| `ServerException` | 5xx: retried automatically |
| `ConnectionException` | the API couldn't be reached |

## Configuration

```php
new TrueUp(
    apiKey: 'tu_live_...',     // default: TRUEUP_API_KEY
    baseUrl: 'https://...',    // default: TRUEUP_BASE_URL, then the hosted API
    timeout: 300,              // seconds per request
    maxRetries: 2,             // rate limits, 5xx and dropped connections
);
```

## Development

The tests run in Docker against the live API:

```bash
export TRUEUP_API_KEY=tu_live_...   # a key for a test team (each run uses 6 analyses)
just test                            # or: docker compose run --rm test
```

## License

MIT
