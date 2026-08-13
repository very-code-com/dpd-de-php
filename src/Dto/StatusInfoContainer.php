<?php

declare(strict_types=1);

namespace VeryCodeCom\DpdDe\Dto;

/** `StatusInfoContainerType`: the five named tracking milestones of getOrderStatus. */
final class StatusInfoContainer
{
    public function __construct(
        public readonly ?StatusInfoDetail $start,
        public readonly ?StatusInfoDetail $onTheRoad,
        public readonly ?StatusInfoDetail $deliveryDepot,
        public readonly ?StatusInfoDetail $carLoad,
        public readonly ?StatusInfoDetail $delivered,
    ) {
    }
}
