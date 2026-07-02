<?php
declare(strict_types=1);

namespace App\Allegro\Dashboard;

use App\Allegro\AllegroClient;
use App\Allegro\Offers\OfferNormalizer;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Throwable;

/**
 * Generates comprehensive dashboard summary with sales, orders, and inventory analytics.
 */
final class DashboardService
{
    public function __construct(private AllegroClient $client)
    {
    }

    /**
     * Generate complete dashboard summary with analytics.
     */
    public function getSummary(): array
    {
        $now = new DateTimeImmutable('now');
        $todayStart = $now->setTime(0, 0, 0);
        $monthStart = $now->modify('first day of this month')->setTime(0, 0, 0);
        $days7Start = $todayStart->modify('-6 days');
        $days30Start = $todayStart->modify('-29 days');
        $ordersSinceStart = $monthStart->getTimestamp() < $days30Start->getTimestamp() ? $monthStart : $days30Start;

        $isoMonth = $monthStart->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        $isoOrdersSince = $ordersSinceStart->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');

        // Fetch data from APIs
        $latestPayments = $this->paginateCollection('/payments/payment-operations', 'paymentOperations', ['limit' => 1]);
        $payments = $this->paginateCollection('/payments/payment-operations', 'paymentOperations', [
            'occurredAt.gte' => $isoMonth,
            'limit' => 100,
        ]);
        $orders = $this->paginateCollection('/order/checkout-forms', 'checkoutForms', [
            'lineItems.boughtAt.gte' => $isoOrdersSince,
            'limit' => 100,
            'sort' => 'lineItems.boughtAt-desc',
        ]);
        $activeOffersResponse = $this->client->apiRequest('GET', '/sale/offers?' . http_build_query([
            'publication.status' => 'ACTIVE',
            'limit' => 100,
            'offset' => 0,
        ], '', '&', PHP_QUERY_RFC3986));
        $activeOffers = is_array($activeOffersResponse['offers'] ?? null) ? $activeOffersResponse['offers'] : [];

        // Initialize accumulators
        $aggregates = new Aggregates($todayStart, $monthStart, $days7Start, $days30Start);

        // Process payments
        $this->processPayments($payments, $latestPayments, $aggregates);

        // Process orders
        $this->processOrders($orders, $aggregates);

        // Process active offers
        $this->processActiveOffers($activeOffers, $aggregates);

        return $aggregates->toArray();
    }

    private function processPayments(array $payments, array $latestPayments, Aggregates $agg): void
    {
        $latestPayment = $latestPayments[0] ?? null;
        if (is_array($latestPayment) && isset($latestPayment['wallet']['balance'])) {
            $agg->balance = OfferNormalizer::extractMoneyAmount($latestPayment['wallet']['balance']);
            $agg->balanceCurrency = OfferNormalizer::extractMoneyCurrency($latestPayment['wallet']['balance']) ?? $agg->currency;
            $agg->balanceUpdatedAt = $latestPayment['occuriedAt'] ?? null;
            $latestCurrency = OfferNormalizer::extractMoneyCurrency($latestPayment['value'] ?? null);
            if ($latestCurrency !== null) {
                $agg->currency = $latestCurrency;
            }
        }

        foreach ($payments as $operation) {
            $occurredAt = $this->safeDate($operation['occurredAt'] ?? null);
            $amount = OfferNormalizer::extractMoneyAmount($operation['value'] ?? null);
            $opCurrency = OfferNormalizer::extractMoneyCurrency($operation['value'] ?? null);
            if ($opCurrency !== null) {
                $agg->currency = $opCurrency;
            }
            if ($agg->balance === null && isset($operation['wallet']['balance'])) {
                $agg->balance = OfferNormalizer::extractMoneyAmount($operation['wallet']['balance']);
                $agg->balanceCurrency = OfferNormalizer::extractMoneyCurrency($operation['wallet']['balance']) ?? $agg->currency;
                $agg->balanceUpdatedAt = $operation['occurredAt'] ?? null;
            }
            if (!$occurredAt || $amount === null) {
                continue;
            }
            if (($operation['group'] ?? null) === 'INCOME') {
                $agg->monthIncome += $amount;
                if ($occurredAt >= $agg->todayStart) {
                    $agg->todayIncome += $amount;
                }
            } elseif (($operation['group'] ?? null) === 'OUTCOME') {
                $agg->monthExpenses += abs($amount);
                if ($occurredAt >= $agg->todayStart) {
                    $agg->todayExpenses += abs($amount);
                }
            }
        }
    }

    private function processOrders(array $orders, Aggregates $agg): void
    {
        foreach ($orders as $order) {
            $finishedAt = $this->safeDate($order['payment']['finishedAt'] ?? null)
                ?? $this->safeDate($order['updatedAt'] ?? null);
            $paidAmount = OfferNormalizer::extractMoneyAmount($order['payment']['paidAmount'] ?? null)
                ?? OfferNormalizer::extractMoneyAmount($order['summary']['totalToPay'] ?? null)
                ?? 0.0;
            $orderCurrency = OfferNormalizer::extractMoneyCurrency($order['payment']['paidAmount'] ?? null)
                ?? OfferNormalizer::extractMoneyCurrency($order['summary']['totalToPay'] ?? null);
            if ($orderCurrency !== null) {
                $agg->currency = $orderCurrency;
            }

            $itemsCount = 0;
            foreach (($order['lineItems'] ?? []) as $lineItem) {
                $quantity = (int) round((float) ($lineItem['quantity'] ?? 0));
                $itemsCount += $quantity;
                $lineAmount = OfferNormalizer::extractMoneyAmount($lineItem['price'] ?? null)
                    ?? OfferNormalizer::extractMoneyAmount($lineItem['originalPrice'] ?? null)
                    ?? 0.0;
                $offerId = (string)($lineItem['offer']['id'] ?? '');
                $sku = (string)($lineItem['offer']['external']['id'] ?? $offerId);
                $key = $sku !== '' ? $sku : ($offerId !== '' ? $offerId : uniqid('sku_', true));
                
                if (!isset($agg->recentSkuMap[$key])) {
                    $agg->recentSkuMap[$key] = [
                        'sku' => $sku !== '' ? $sku : '—',
                        'offer_id' => $offerId !== '' ? $offerId : '—',
                        'name' => (string)($lineItem['offer']['name'] ?? 'Unnamed offer'),
                        'qty' => 0,
                        'orders' => 0,
                        'revenue' => 0.0,
                    ];
                }
                
                if ($finishedAt && $finishedAt >= $agg->days30Start) {
                    $agg->recentSkuMap[$key]['qty'] += $quantity;
                    $agg->recentSkuMap[$key]['revenue'] += $lineAmount * max(1, $quantity);
                    $agg->recentSkuMap[$key]['orders'] += 1;
                }
            }

            $status = (string)($order['status'] ?? '');
            $fulfillmentStatus = (string)($order['fulfillment']['status'] ?? '');
            $shipmentSummary = (string)($order['fulfillment']['shipmentSummary']['lineItemsSent'] ?? '');
            
            if (in_array($status, ['READY_FOR_PROCESSING', 'FILLED_IN'], true)
                || in_array($fulfillmentStatus, ['NEW', 'PROCESSING'], true)) {
                $agg->pendingOrders++;
            }
            
            $isAwaitingShipment = in_array($fulfillmentStatus, ['NEW', 'PROCESSING'], true)
                || ($status === 'READY_FOR_PROCESSING' && !in_array($shipmentSummary, ['ALL'], true) && !in_array($fulfillmentStatus, ['PICKED_UP', 'SENT'], true));
            
            if ($isAwaitingShipment) {
                $agg->awaitingShipmentCount++;
                if (count($agg->awaitingShipment) < 5) {
                    $agg->awaitingShipment[] = [
                        'id' => (string)($order['id'] ?? '—'),
                        'buyer' => trim((string)($order['buyer']['firstName'] ?? '') . ' ' . (string)($order['buyer']['lastName'] ?? '')) ?: ((string)($order['buyer']['login'] ?? 'Buyer')),
                        'status' => $status !== '' ? $status : '—',
                        'fulfillment_status' => $fulfillmentStatus !== '' ? $fulfillmentStatus : '—',
                        'amount' => $paidAmount,
                        'currency' => $orderCurrency ?? $agg->currency,
                        'updated_at' => $order['updatedAt'] ?? null,
                        'delivery_method' => (string)($order['delivery']['method']['name'] ?? '—'),
                    ];
                }
            }

            if (!$finishedAt) {
                continue;
            }
            
            $dayKey = $finishedAt->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d');
            if (isset($agg->dailyTrend[$dayKey])) {
                $agg->dailyTrend[$dayKey]['sales'] += $paidAmount;
                $agg->dailyTrend[$dayKey]['orders'] += 1;
                $agg->dailyTrend[$dayKey]['items'] += $itemsCount;
            }
            
            if ($finishedAt >= $agg->monthStart) {
                $agg->monthOrders++;
                $agg->monthItems += $itemsCount;
                $agg->monthSalesGross += $paidAmount;
            }
            if ($finishedAt >= $agg->days30Start) {
                $agg->orders30++;
                $agg->items30 += $itemsCount;
                $agg->sales30Gross += $paidAmount;
            }
            if ($finishedAt >= $agg->days7Start) {
                $agg->orders7++;
                $agg->items7 += $itemsCount;
                $agg->sales7Gross += $paidAmount;
            }
            if ($finishedAt >= $agg->todayStart) {
                $agg->todayOrders++;
                $agg->todayItems += $itemsCount;
                $agg->todaySalesGross += $paidAmount;
            }
        }
    }

    private function processActiveOffers(array $activeOffers, Aggregates $agg): void
    {
        foreach ($activeOffers as $offer) {
            $agg->activeOfferHighlights[] = [
                'offer_id' => (string)($offer['id'] ?? '—'),
                'sku' => (string)($offer['external']['id'] ?? '—'),
                'name' => (string)($offer['name'] ?? 'Unnamed offer'),
                'price' => OfferNormalizer::extractMoneyAmount($offer['sellingMode']['price'] ?? null),
                'currency' => OfferNormalizer::extractMoneyCurrency($offer['sellingMode']['price'] ?? null) ?? $agg->currency,
                'sold' => (int)($offer['stock']['sold'] ?? 0),
                'visits' => (int)($offer['stats']['visitsCount'] ?? 0),
                'watchers' => (int)($offer['stats']['watchersCount'] ?? 0),
                'available' => (int)($offer['stock']['available'] ?? 0),
            ];
        }
        
        usort($agg->activeOfferHighlights, static function (array $a, array $b): int {
            return [$b['sold'], $b['visits'], $b['watchers']] <=> [$a['sold'], $a['visits'], $a['watchers']];
        });
        $agg->activeOfferHighlights = array_slice($agg->activeOfferHighlights, 0, 5);
    }

    private function paginateCollection(string $path, string $collectionKey, array $query = []): array
    {
        $limit = max(1, min(100, (int)($query['limit'] ?? 100)));
        $offset = 0;
        $items = [];
        
        do {
            $query['limit'] = $limit;
            $query['offset'] = $offset;
            $glue = str_contains($path, '?') ? '&' : '?';
            $response = $this->client->apiRequest('GET', $path . $glue . http_build_query($query, '', '&', PHP_QUERY_RFC3986));
            $chunk = $response[$collectionKey] ?? [];
            if (!is_array($chunk)) {
                break;
            }
            $items = array_merge($items, $chunk);
            $count = (int)($response['count'] ?? count($chunk));
            $totalCount = (int)($response['totalCount'] ?? count($items));
            $offset += $count > 0 ? $count : count($chunk);
            if (count($chunk) < $limit) {
                break;
            }
        } while ($offset < $totalCount && $offset < 1000);
        
        return $items;
    }

    private function safeDate(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return new DateTimeImmutable($value);
        } catch (Throwable) {
            return null;
        }
    }
}
