<?php

declare(strict_types=1);

namespace JC\Controller;

use JC\Core\Request;
use JC\Core\Response;
use JC\Service\ReportService;

/** Returns & reports for the workspace. */
final class ReportController extends Controller
{
    public function index(Request $request): Response
    {
        $this->auth()->authorize('reports.view');
        [$from, $to, $period] = $this->period($request);
        $report = $this->app->service(ReportService::class)->returns($from, $to);
        $this->app->log('report.viewed', null, null, ['from' => $from, 'to' => $to]);
        return $this->render('reports/index', [
            'title' => 'Returns & reports',
            'r' => $report,
            'period' => $period,
            'chambers' => $this->auth()->chambers(),
        ], $request->input('print') === '1' ? 'layout/print' : 'layout/main');
    }

    public function export(Request $request): Response
    {
        $this->auth()->authorize('reports.view');
        [$from, $to] = $this->period($request);
        $service = $this->app->service(ReportService::class);
        $csv = $service->csv($service->returns($from, $to));
        $this->app->log('report.exported', null, null, ['from' => $from, 'to' => $to]);
        $response = new Response("\xEF\xBB\xBF" . $csv);
        $response->headers['Content-Type'] = 'text/csv; charset=utf-8';
        $response->headers['Content-Disposition'] = 'attachment; filename="returns-' . $from . '-to-' . $to . '.csv"';
        return $response;
    }

    /** @return array{0:string,1:string,2:string} */
    private function period(Request $request): array
    {
        $tz = app_timezone();
        $today = new \DateTimeImmutable('today', $tz);
        $period = (string) $request->input('period', 'quarter');
        $month = (int) $today->format('n');
        [$from, $to] = match ($period) {
            'month' => [$today->modify('first day of this month'), $today->modify('last day of this month')],
            'last_month' => [$today->modify('first day of last month'), $today->modify('last day of last month')],
            'year' => [$today->setDate((int) $today->format('Y'), 1, 1), $today->setDate((int) $today->format('Y'), 12, 31)],
            'custom' => [
                \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $request->input('from'), $tz) ?: $today->modify('-90 days'),
                \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $request->input('to'), $tz) ?: $today,
            ],
            default => [
                $today->setDate((int) $today->format('Y'), intdiv($month - 1, 3) * 3 + 1, 1),
                $today->setDate((int) $today->format('Y'), intdiv($month - 1, 3) * 3 + 3, 1)->modify('last day of this month'),
            ],
        };
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        return [$from->format('Y-m-d'), $to->format('Y-m-d'), $period];
    }
}
