<?php

declare(strict_types=1);

namespace TrueUp;

/**
 * A table to reconcile: a file, file contents, or rows. $name is how findings refer to its rows
 * ("statement.csv:row 5").
 *
 *     Table::file('statement.csv');
 *     Table::content('statement.csv', $csvText);
 *     Table::rows('statement.csv', [['Date' => '2026-08-03', 'Amount' => '$99.60'], ...]);
 */
final class Table
{
    /** @param list<array<string, string|int|float|bool|null>>|null $rows */
    private function __construct(
        public readonly string $name,
        private readonly ?string $content = null,
        private readonly ?array $rows = null,
    ) {
    }

    public static function file(string $path, ?string $name = null): self
    {
        $content = @file_get_contents($path);
        if ($content === false) {
            throw new \InvalidArgumentException("Can't read {$path}");
        }
        return new self($name ?? basename($path), $content);
    }

    public static function content(string $name, string $content): self
    {
        return new self($name, $content);
    }

    /** @param list<array<string, string|int|float|bool|null>> $rows */
    public static function rows(string $name, array $rows): self
    {
        return new self($name, null, array_values($rows));
    }

    public function isRows(): bool
    {
        return $this->rows !== null;
    }

    /** @return list<array<string, string|int|float|bool|null>> */
    public function getRows(): array
    {
        return $this->rows ?? [];
    }

    /** @return array{0: string, 1: string} file name and bytes, for a multipart upload */
    public function asFile(): array
    {
        if ($this->rows !== null) {
            $stem = str_contains($this->name, '.') ? substr($this->name, 0, (int) strrpos($this->name, '.')) : $this->name;
            return [$stem . '.json', json_encode($this->rows, JSON_THROW_ON_ERROR)];
        }
        return [$this->name, $this->content ?? ''];
    }
}
