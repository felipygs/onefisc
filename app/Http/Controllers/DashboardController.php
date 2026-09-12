<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\User;
use App\Support\CurrentAccount;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $account = CurrentAccount::resolve();

        $end = $this->parseDate($request->input('end')) ?? CarbonImmutable::today();
        $start = $this->parseDate($request->input('start')) ?? $end->subDays(13);

        if ($start->greaterThan($end)) {
            [$start, $end] = [$end, $start];
        }

        if ($start->diffInDays($end) > 365) {
            $start = $end->subDays(365);
        }

        $period = $request->input('period');
        $period = in_array($period, ['daily', 'weekly', 'monthly'], true) ? $period : 'daily';

        $customers = $account ? Client::query()->count() : 0;
        $members = $account ? User::query()->where('account_id', $account->id)->count() : 0;

        $chart = $this->chartPoints($start, $end);
        $revenue = array_sum(array_column($chart, 'amount'));

        return Inertia::render('Dashboard', [
            'home' => [
                'range' => [
                    'start' => $start->toDateString(),
                    'end' => $end->toDateString(),
                ],
                'period' => $period,
                'stats' => [
                    ['title' => 'Clients', 'icon' => 'i-lucide-users', 'value' => $customers, 'variation' => 12],
                    ['title' => 'Equipe', 'icon' => 'i-lucide-user-round', 'value' => $members, 'variation' => 4],
                    ['title' => 'Receita', 'icon' => 'i-lucide-circle-dollar-sign', 'value' => $this->formatBrl($revenue), 'variation' => 18],
                    ['title' => 'Documentos', 'icon' => 'i-lucide-file-text', 'value' => 0, 'variation' => 0],
                ],
                'chart' => $chart,
                'sales' => $this->recentSales($end),
            ],
        ]);
    }

    protected function parseDate(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Deterministic illustrative series so reloads over the same range are stable.
     *
     * @return array<int, array{date: string, amount: int}>
     */
    protected function chartPoints(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $points = [];
        $cursor = $start;

        while (! $cursor->greaterThan($end)) {
            $ordinal = $cursor->dayOfYear + $cursor->year * 366;
            $points[] = [
                'date' => $cursor->toDateString(),
                'amount' => 1000 + (($ordinal * 7919) % 9000),
            ];
            $cursor = $cursor->addDay();
        }

        return $points;
    }

    protected function formatBrl(int $value): string
    {
        return 'R$ '.number_format($value, 0, ',', '.');
    }

    /**
     * @return array<int, array{id: string, date: string, status: string, email: string, amount: int}>
     */
    protected function recentSales(CarbonImmutable $end): array
    {
        $samples = [
            ['id' => '4600', 'hours' => 2, 'status' => 'paid', 'email' => 'fiscal@empresa-exemplo.com.br', 'amount' => 890],
            ['id' => '4599', 'hours' => 7, 'status' => 'paid', 'email' => 'contato@comercio-exemplo.com.br', 'amount' => 450],
            ['id' => '4598', 'hours' => 19, 'status' => 'refunded', 'email' => 'admin@industria-exemplo.com.br', 'amount' => 320],
            ['id' => '4597', 'hours' => 26, 'status' => 'paid', 'email' => 'fiscal@servicos-exemplo.com.br', 'amount' => 1150],
            ['id' => '4596', 'hours' => 41, 'status' => 'failed', 'email' => 'contato@varejo-exemplo.com.br', 'amount' => 210],
        ];

        return array_map(fn (array $sale) => [
            'id' => $sale['id'],
            'date' => $end->subHours($sale['hours'])->toIso8601String(),
            'status' => $sale['status'],
            'email' => $sale['email'],
            'amount' => $sale['amount'],
        ], $samples);
    }
}
