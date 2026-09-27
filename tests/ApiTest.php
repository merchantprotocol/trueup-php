<?php

declare(strict_types=1);

namespace TrueUp\Tests;

use PHPUnit\Framework\TestCase;
use TrueUp\Exception\AuthenticationException;
use TrueUp\Exception\InvalidRequestException;
use TrueUp\Table;
use TrueUp\TrueUp;

/**
 * Integration tests against the live TrueUp API. Need TRUEUP_API_KEY (and optionally TRUEUP_BASE_URL).
 * Each full run uses 2 analyses. Run in Docker: `just test` (or `docker compose run --rm test`).
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
}
