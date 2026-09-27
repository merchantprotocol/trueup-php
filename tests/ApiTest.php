<?php

declare(strict_types=1);

namespace TrueUp\Tests;

use PHPUnit\Framework\TestCase;
use TrueUp\Exception\AuthenticationException;
use TrueUp\Exception\InvalidRequestException;
use TrueUp\Exception\NotFoundException;
use TrueUp\Table;
use TrueUp\TrueUp;

/**
 * Integration tests against the live TrueUp API. Need TRUEUP_API_KEY (and optionally TRUEUP_BASE_URL).
 * Each full run uses 10 analyses. Run in Docker: `just test` (or `docker compose run --rm test`).
 */
final class ApiTest extends TestCase
{
    private static function fixture(string $name): string
    {
        return __DIR__ . '/fixtures/' . $name;
    }

    private static function rows(string $name): array
    {
        $lines = array_map('str_getcsv', file(self::fixture($name), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
        $head = array_shift($lines);
        return array_map(fn ($l) => array_combine($head, $l), $lines);
    }

    private function live(): void
    {
        if (!getenv('TRUEUP_API_KEY')) {
            $this->markTestSkipped('needs TRUEUP_API_KEY');
        }
    }

    public function testMissingApiKeyFailsBeforeAnyRequest(): void
    {
        $saved = getenv('TRUEUP_API_KEY');
        putenv('TRUEUP_API_KEY');
        try {
            new TrueUp();
            $this->fail('expected AuthenticationException');
        } catch (AuthenticationException $e) {
            $this->assertSame('missing_api_key', $e->getErrorCode());
        } finally {
            if ($saved !== false) {
                putenv('TRUEUP_API_KEY=' . $saved);
            }
        }
    }

    public function testAccountUsagePlans(): void
    {
        $this->live();
        $tu = new TrueUp();
        $this->assertStringStartsWith('tu_live_', $tu->account()['key']['prefix']);
        $this->assertContains('analyses', array_column($tu->usage()['metrics'], 'metric'));
        $this->assertContains('free', array_column($tu->plans(), 'slug'));
    }

    public function testReconcileFilesThenRowsWithSavedWeights(): void
    {
        $this->live();
        $tu = new TrueUp();
        $result = $tu->reconcile(self::fixture('statement.csv'), self::fixture('receiving.csv'));
        $this->assertSame('reconcile', $result['analysis']);
        $this->assertSame(7, $result['stats']['paired']);
        $this->assertSame(
            [['qty_mismatch', 'statement.csv:row 5'], ['phantom', 'statement.csv:row 6']],
            array_map(fn ($f) => [$f['kind'], $f['subject']], $result['findings']),
        );
        $this->assertEqualsWithDelta(43.2, $result['findings'][1]['amount'], 1e-9);
        $weights = $result['details']['weights'];
        $this->assertSame('trueup.match-weights', $weights['format']);

        $again = $tu->reconcile(
            Table::rows('statement.csv', self::rows('statement.csv')),
            Table::rows('receiving.csv', self::rows('receiving.csv')),
            weights: $weights,
        );
        $this->assertSame(7, $again['stats']['paired']);
        $this->assertFalse($again['details']['model']['learned']);
    }

    public function testErrorsAreTyped(): void
    {
        $this->live();
        try {
            (new TrueUp('tu_live_' . str_repeat('x', 40)))->account();
            $this->fail('expected AuthenticationException');
        } catch (AuthenticationException $e) {
            $this->assertSame(401, $e->getStatus());
            $this->assertSame('invalid_api_key', $e->getErrorCode());
        }
        try {
            (new TrueUp())->reconcile(self::fixture('statement.csv'), Table::content('scan.pdf', '%PDF-1.4'));
            $this->fail('expected InvalidRequestException');
        } catch (InvalidRequestException $e) {
            $this->assertSame(422, $e->getStatus());
            $this->assertSame('unsupported_file', $e->getErrorCode());
        }
    }

    public function testStoredFilesRunsAndModels(): void
    {
        $this->live();
        $tu = new TrueUp();
        [$statement, $receiving] = $tu->uploadFiles(self::fixture('statement.csv'), self::fixture('receiving.csv'));
        try {
            $this->assertSame(8, $statement['rows']);
            $this->assertSame('receiving.csv', $tu->getFile($receiving['id'])['name']);
            $this->assertContains($statement['id'], array_column($tu->listFiles(), 'id'));
            $this->assertSame(file_get_contents(self::fixture('statement.csv')), $tu->fileContent($statement['id']));

            $result = $tu->reconcileStored($statement['id'], $receiving['id']);
            $this->assertSame(7, $result['stats']['paired']);
            $got = $tu->getRun($result['run_id']);
            $this->assertSame('done', $got['run']['status']);
            $this->assertSame(7, $got['result']['stats']['paired']);
            $page = $tu->listRuns(1);
            $this->assertCount(1, $page['runs']);
            if ($page['has_more']) {
                $this->assertNotSame($page['runs'][0]['id'], $tu->listRuns(1, $page['runs'][0]['id'])['runs'][0]['id']);
            }

            $modelId = $tu->createModel($result['run_id'], 'sdk test');
            try {
                $this->assertSame('trueup.match-weights', $tu->getModel($modelId)['weights']['format']);
                $again = $tu->reconcileStored(fileIds: [$statement['id'], $receiving['id']], model: $modelId);
                $this->assertFalse($again['details']['model']['learned']);
            } finally {
                $tu->deleteModel($modelId);
            }
            try {
                $tu->getModel($modelId);
                $this->fail('a deleted model is gone');
            } catch (NotFoundException) {
            }
        } finally {
            $tu->deleteFile($statement['id']);
            $tu->deleteFile($receiving['id']);
        }
        $this->expectException(NotFoundException::class);
        $tu->getFile($statement['id']);
    }

    public function testMatchTwoListsThenReuseTheLearning(): void
    {
        $this->live();
        $tu = new TrueUp();
        $matched = [['1', '1'], ['2', '2'], ['3', '3'], ['4', '5']];
        $result = $tu->match(self::fixture('invoice.csv'), self::fixture('catalog.csv'));
        $this->assertSame('match', $result['analysis']);
        $this->assertSame($matched, array_map(fn ($p) => array_slice($p, 0, 2), $result['details']['pairs']));
        $only = array_values(array_filter($result['findings'], fn ($f) => $f['kind'] === 'only_left'));
        $this->assertSame(['5'], array_column($only, 'subject'));
        $again = $tu->match(Table::rows('invoice.csv', self::rows('invoice.csv')), Table::rows('catalog.csv', self::rows('catalog.csv')),
            weights: $result['details']['weights']);
        $this->assertSame($matched, array_map(fn ($p) => array_slice($p, 0, 2), $again['details']['pairs']));
        $this->assertFalse($again['details']['model']['learned']);
    }

    public function testAuditSixInvoicesThenOneAgainstTheSavedLaws(): void
    {
        $this->live();
        $tu = new TrueUp();
        $files = array_map(fn ($i) => self::fixture("invoices/inv-104{$i}.txt"), range(1, 6));
        $result = $tu->audit($files);
        $this->assertSame('audit', $result['analysis']);
        $this->assertSame([['inv-1045.txt', 'yes', 200]], array_map(fn ($f) => [$f['subject'], $f['status'], $f['amount']], $result['findings']));
        $this->assertContains('subtotal + tax amount = total', array_column($result['details']['laws'], 'law'));
        $one = $tu->audit([self::fixture('invoices/inv-1045.txt')], $result['details']['weights']);
        $this->assertFalse($one['details']['model']['learned']);
        $this->assertSame(['inv-1045.txt'], array_column($one['findings'], 'subject'));
    }

    public function testEstimateANewJobThenTheNextWithTheSavedModel(): void
    {
        $this->live();
        $tu = new TrueUp();
        $trade = ["barndo.tu", "01_anderson.csv", "02_brooks.csv", "03_carter.md", "04_dalton.txt", "05_ellis.json", "06_foster.tsv", "07_garrison.txt", "08_hayes.csv", "09_iverson.csv", "10_jensen.md"];
        $result = $tu->estimate(array_map(fn ($n) => self::fixture("barndo/$n"), [...$trade, 'job_a.txt']));
        $this->assertSame('estimate', $result['analysis']);
        $this->assertSame(10, $result['stats']['past estimates']);
        $this->assertLessThan(0.05, abs($result['stats']['total'] - 292267) / 292267);
        $this->assertLessThan($result['stats']['total'], $result['stats']['low']);
        $next = $tu->estimate([self::fixture('barndo/job_b.txt')], $result['details']['weights']);
        $this->assertFalse($next['details']['model']['learned']);
    }
}
