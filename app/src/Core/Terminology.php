<?php

declare(strict_types=1);

namespace JC\Core;

/**
 * Workspace vocabulary. Court chambers and arbitral tribunals run the same
 * workspace; only the words change ("case" / "reference", "judgment" /
 * "award", "plaintiff" / "claimant"). Stored values never change, so a
 * workspace can switch mode without touching its data.
 */
final class Terminology
{
    public const MODES = ['court' => 'Court (judge)', 'arbitration' => 'Arbitration (arbitrator)'];

    private const ARBITRATION = [
        'case' => 'reference',
        'Case' => 'Reference',
        'cases' => 'references',
        'Cases' => 'References',
        'case number' => 'reference number',
        'Case number' => 'Reference number',
        'judge' => 'arbitrator',
        'Judge' => 'Arbitrator',
        'judgment' => 'award',
        'Judgment' => 'Award',
        'judgments' => 'awards',
        'Judgments' => 'Awards',
        'ruling' => 'procedural order',
        'Ruling' => 'Procedural order',
        'court' => 'institution',
        'Court' => 'Arbitral institution',
        'chambers' => 'tribunal',
        'Chambers' => 'Tribunal',
        'Cause list' => 'Hearing schedule',
        'Marshal' => 'Tribunal secretary',
    ];

    private static string $mode = 'court';

    public static function setMode(?string $mode): void
    {
        self::$mode = $mode === 'arbitration' ? 'arbitration' : 'court';
    }

    public static function mode(): string
    {
        return self::$mode;
    }

    public static function isArbitration(): bool
    {
        return self::$mode === 'arbitration';
    }

    public static function term(string $word): string
    {
        return self::$mode === 'arbitration' ? (self::ARBITRATION[$word] ?? $word) : $word;
    }
}
