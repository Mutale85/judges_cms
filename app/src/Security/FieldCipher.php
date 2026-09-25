<?php

declare(strict_types=1);

namespace JC\Security;

/**
 * Central register of which database columns are encrypted at rest, and with
 * which key purpose. Repositories call encryptRow()/decryptRow() so no
 * sensitive column is ever written in plaintext by accident.
 *
 * Confidential judicial notes use their own key purpose ("judicial-notes"), so
 * they are cryptographically separated from ordinary case data.
 */
final class FieldCipher
{
    /** @var array<string,array<string,string>> table => [column => purpose] */
    public const ENCRYPTED = [
        'users' => ['email' => 'field', 'full_name' => 'field', 'phone' => 'field', 'mfa_secret' => 'mfa'],
        'cases' => ['case_number' => 'field', 'title' => 'field', 'summary' => 'field'],
        'case_parties' => ['name' => 'field', 'representing' => 'field', 'contact' => 'field'],
        'documents' => ['title' => 'field', 'original_name' => 'field', 'description' => 'field'],
        'hearings' => ['notes' => 'field', 'outcome' => 'field'],
        'tasks' => ['title' => 'field', 'details' => 'field'],
        'judicial_notes' => ['title' => 'judicial-notes', 'body' => 'judicial-notes'],
        'transcript_segments' => ['speaker' => 'field', 'text' => 'field', 'machine_text' => 'field'],
        'recording_bookmarks' => ['label' => 'field'],
        'audit_log' => ['details' => 'audit', 'ip_address' => 'audit'],
    ];

    public function __construct(private readonly Crypto $crypto)
    {
    }

    public function crypto(): Crypto
    {
        return $this->crypto;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public function encryptRow(string $table, array $row): array
    {
        foreach (self::ENCRYPTED[$table] ?? [] as $column => $purpose) {
            if (array_key_exists($column, $row) && $row[$column] !== null) {
                $row[$column] = $this->crypto->encrypt((string) $row[$column], "{$table}.{$column}", $purpose);
            }
        }
        return $row;
    }

    /**
     * @param array<string,mixed>|null $row
     * @return array<string,mixed>|null
     */
    public function decryptRow(string $table, ?array $row): ?array
    {
        if ($row === null) {
            return null;
        }
        foreach (self::ENCRYPTED[$table] ?? [] as $column => $purpose) {
            if (isset($row[$column])) {
                $row[$column] = $this->crypto->decrypt((string) $row[$column], "{$table}.{$column}", $purpose);
            }
        }
        return $row;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    public function decryptRows(string $table, array $rows): array
    {
        return array_map(fn (array $row) => $this->decryptRow($table, $row), $rows);
    }

    public function blindIndex(string $table, string $column, string $value): string
    {
        return $this->crypto->blindIndex($value, "{$table}.{$column}");
    }
}
