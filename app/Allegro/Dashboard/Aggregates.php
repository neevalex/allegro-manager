<?php
declare(strict_types=1);

namespace App\Allegro\Dashboard;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Helper class for aggregating dashboard data.
 */
final class Aggregates
{
    public DateTimeImmutable $todayStart;
    public DateTimeImmutable $monthStart;
    public DateTimeImmutable $days7Start;
    public DateTimeImmutable $days30Start;

    public ?float $balance = null;
    public string $balanceCurrency = 'PLN';
    public ?string $balanceUpdatedAt = null;

    public float $todaySalesGross = 0.0;
    public float $sales7Gross = 0.0;
    public float $sales30Gross = 0.0;
    public float $monthSalesGross = 0.0;

    public float $todayIncome = 0.0;
    public float $monthIncome = 0.0;
    public float $todayExpenses = 0.0;
    public float $monthExpenses = 0.0;

    public int $todayOrders = 0;
    public int $orders7 = 0;
    public int $orders30 = 0;
    public int $monthOrders = 0;

    public int $todayItems = 0;
    public int $items7 = 0;
    public int $items30 = 0;
    public int $monthItems = 0;

    public int $pendingOrders = 0;
    public int $awaitingShipmentCount = 0;

    public string $currency = 'PLN';
    public array $awaitingShipment = [];
    public array $recentSkuMap = [];
    public array $activeOfferHighlights = [];
    public array $dailyTrend = [];

    public function __construct(
        DateTimeImmutable $todayStart,
        DateTimeImmutable $monthStart,
        DateTimeImmutable $days7Start,
        DateTimeImmutable $days30Start,
    ) {
        $this->todayStart = $todayStart;
        $this->monthStart = $monthStart;
        $this->days7Start = $days7Start;
        $this->days30Start = $days30Start;

        // Initialize daily trend for last 30 days
        for ($i = 29; $i >= 0; $i--) {
            $day = $todayStart->modify('-' . $i . ' days');
            $this->dailyTrend[$day->format('Y-m-d')] = [
                'date' => $day->format(DateTimeInterface::ATOM),
                'label' => $day->format('d M'),
                'sales' => 0.0,
                'orders' => 0,
                'items' => 0,
            ];
        }
    }

    public function toArray(): array
    {
        // Sort SKUs by quantity and revenue
        uasort($this->recentSkuMap, static function (array $a, array $b): int {
            return [$b['qty'], $b['revenue'], $b['orders']] <=> [$a['qty'], $a['revenue'], $a['orders']];
        });
        $topSkus = array_slice(array_values($this->recentSkuMap), 0, 5);

        return [
            'currency' => $this->currency,
            'balance' => $this->balance,
            'balance_currency' => $this->balanceCurrency ?? $this->currency,
            'balance_updated_at' => $this->balanceUpdatedAt,
            'sales_today' => $this->todaySalesGross,
            'sales_7d' => $this->sales7Gross,
            'sales_30d' => $this->sales30Gross,
            'sales_month' => $this->monthSalesGross,
            'income_today' => $this->todayIncome,
            'income_month' => $this->monthIncome,
            'expenses_today' => $this->todayExpenses,
            'expenses_month' => $this->monthExpenses,
            'orders_today' => $this->todayOrders,
            'orders_7d' => $this->orders7,
            'orders_30d' => $this->orders30,
            'orders_month' => $this->monthOrders,
            'items_today' => $this->todayItems,
            'items_7d' => $this->items7,
            'items_30d' => $this->items30,
            'items_month' => $this->monthItems,
            'pending_orders' => $this->pendingOrders,
            'awaiting_shipment_count' => $this->awaitingShipmentCount,
            'awaiting_shipment' => $this->awaitingShipment,
            'top_recent_skus' => $topSkus,
            'active_offer_highlights' => $this->activeOfferHighlights,
            'daily_trend_30' => array_values($this->dailyTrend),
            'daily_trend_7' => array_slice(array_values($this->dailyTrend), -7),
            'payments_loaded' => 0, // Populated by caller if needed
            'orders_loaded' => 0, // Populated by caller if needed
            'offers_loaded' => count($this->activeOfferHighlights),
            'today_start' => $this->todayStart->format(DateTimeInterface::ATOM),
            'month_start' => $this->monthStart->format(DateTimeInterface::ATOM),
            'days7_start' => $this->days7Start->format(DateTimeInterface::ATOM),
            'days30_start' => $this->days30Start->format(DateTimeInterface::ATOM),
        ];
    }
}
