<?php
declare(strict_types=1);

namespace Artdon\CommercialCenter\Services;

use Artdon\CommercialCenter\Repositories\LegacyOperationsReadRepository;
use Artdon\CommercialCenter\Support\Logger;
use Throwable;

final class OperationsDashboardService
{
    public function load(array $authentication): array
    {
        if (!$authentication['authenticated'] || !$authentication['user']) {
            return $this->empty('unauthenticated');
        }
        try {
            $repository = new LegacyOperationsReadRepository();
            $workQueue = $repository->dispatchQueue($authentication['user']);
            $delivery = $repository->quoteDeliveryQueue($authentication['user']);
            $orders = $repository->orders($authentication['user']);
            $exceptions = $repository->exceptions($authentication['user']);
            $activity = $repository->recentActivity($authentication['user']);
            return [
                'status' => 'available',
                'work_queue' => $workQueue,
                'delivery_queue' => $delivery,
                'orders' => $orders,
                'exceptions' => $exceptions,
                'activity' => $activity,
                'counts' => [
                    'work_queue' => count($workQueue),
                    'delivery_queue' => count($delivery),
                    'orders' => count($orders),
                    'exceptions' => array_sum(array_column($exceptions, 'count')),
                ],
            ];
        } catch (Throwable $error) {
            Logger::error('Operations dashboard unavailable', [
                'type' => get_class($error),
                'message' => $error->getMessage(),
            ]);
            return $this->empty('unavailable');
        }
    }

    private function empty(string $status): array
    {
        return [
            'status' => $status,
            'work_queue' => [],
            'delivery_queue' => [],
            'orders' => [],
            'exceptions' => [],
            'activity' => [],
            'counts' => ['work_queue' => 0, 'delivery_queue' => 0, 'orders' => 0, 'exceptions' => 0],
        ];
    }
}
