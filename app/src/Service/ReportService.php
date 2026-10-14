<?php

declare(strict_types=1);

namespace JC\Service;

use JC\Core\App;

/**
 * Returns and reports: what was registered and concluded in a period, what
 * output is pending, and the shape of the docket. Built from the cases the
 * current user can see, so a report never reveals a matter its reader could
 * not open.
 */
final class ReportService
{
    public function __construct(private readonly App $app)
    {
    }

    /**
     * @param string $from local date Y-m-d (inclusive)
     * @param string $to   local date Y-m-d (inclusive)
     * @return array<string,mixed>
     */
    public function returns(string $from, string $to): array
    {
        $tz = app_timezone();
        $utc = new \DateTimeZone('UTC');
        $fromUtc = (new \DateTimeImmutable($from, $tz))->setTimezone($utc)->format('Y-m-d H:i:s');
        $toUtc = (new \DateTimeImmutable($to, $tz))->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s');
        $in = static fn (?string $ts) => $ts !== null && $ts >= $fromUtc && $ts < $toUtc;

        $cases = $this->app->service(CaseService::class)->listAccessible();
        $closed = ['concluded', 'closed'];

        $registered = array_values(array_filter($cases, static fn ($c) => $in($c['created_at'])));
        $concluded = array_values(array_filter($cases, static fn ($c) => in_array($c['status'], $closed, true) && $in($c['concluded_at'] ?? null)));
        $open = array_values(array_filter($cases, static fn ($c) => !in_array($c['status'], $closed, true)));

        $now = time();
        $pending = [];
        foreach ($cases as $c) {
            if ($c['status'] === 'judgment_reserved') {
                $since = $c['status_changed_at'] ?? $c['updated_at'];
                $c['days_pending'] = (int) floor(($now - strtotime($since . ' UTC')) / 86400);
                $pending[] = $c;
            }
        }
        usort($pending, static fn ($a, $b) => $b['days_pending'] <=> $a['days_pending']);

        $byStatus = array_fill_keys(array_keys(CaseService::statuses()), 0);
        $byType = [];
        foreach ($open as $c) {
            $byStatus[$c['status']] = ($byStatus[$c['status']] ?? 0) + 1;
            $byType[$c['case_type']] = ($byType[$c['case_type']] ?? 0) + 1;
        }
        foreach ($closed as $s) {
            unset($byStatus[$s]);
        }
        arsort($byType);

        $hearings = $this->app->service(HearingService::class)->between($fromUtc, $toUtc);
        $hearingsByStatus = [];
        foreach ($hearings as $h) {
            $hearingsByStatus[$h['status']] = ($hearingsByStatus[$h['status']] ?? 0) + 1;
        }

        $reminders = $this->app->service(ReminderService::class);
        $outputDue = array_values(array_filter(
            $reminders->list(null, now_utc(30 * 86400), 'open'),
            static fn ($r) => in_array($r['kind'], ['ruling', 'judgment'], true)
        ));
        $overdue = array_values(array_filter($reminders->list(null, now_utc(), 'open')));

        // Clearance rate: matters concluded per matter registered in the period.
        $clearance = count($registered) > 0 ? round(count($concluded) / count($registered) * 100) : null;
        $ages = array_map(static fn ($c) => (int) floor(($now - strtotime($c['created_at'] . ' UTC')) / 86400), $open);
        sort($ages);

        return [
            'from' => $from,
            'to' => $to,
            'registered' => $registered,
            'concluded' => $concluded,
            'open_count' => count($open),
            'pending' => $pending,
            'by_status' => $byStatus,
            'by_type' => $byType,
            'hearings_total' => count($hearings),
            'hearings_by_status' => $hearingsByStatus,
            'adjournments' => $hearingsByStatus['adjourned'] ?? 0,
            'output_due' => $outputDue,
            'overdue_reminders' => count($overdue),
            'clearance_rate' => $clearance,
            'median_age_days' => $ages ? $ages[intdiv(count($ages), 2)] : null,
            'aged_over_year' => count(array_filter($ages, static fn ($d) => $d > 365)),
        ];
    }

    /** CSV rows for the returns of a period (one line per matter). */
    public function csv(array $report): string
    {
        $fh = fopen('php://temp', 'r+');
        fputcsv($fh, [term('Case number'), 'Title', 'Type', 'Status', 'Registered', 'Concluded', 'Days pending ' . term('judgment')]);
        $rows = [];
        foreach (['registered', 'concluded', 'pending'] as $group) {
            foreach ($report[$group] as $c) {
                $rows[(int) $c['id']] = $c;
            }
        }
        foreach ($rows as $c) {
            fputcsv($fh, [
                self::safeCell((string) $c['case_number']),
                self::safeCell((string) $c['title']),
                CaseService::typeLabel($c['case_type']),
                CaseService::statusLabel($c['status']),
                local_time($c['created_at'], 'Y-m-d'),
                local_time($c['concluded_at'] ?? null, 'Y-m-d'),
                $c['days_pending'] ?? '',
            ]);
        }
        rewind($fh);
        return (string) stream_get_contents($fh);
    }

    /** Neutralises spreadsheet formulas in user-supplied text (CSV injection). */
    private static function safeCell(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) ? "'" . $value : $value;
    }
}
