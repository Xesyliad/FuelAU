<?php

declare(strict_types=1);

final class FuelauRouteInfeasibleException extends RuntimeException {}

final readonly class FuelauOptimizerVehicle
{
    public function __construct(
        public float $tankCapacityL,
        public float $startingFuelL,
        public float $reserveL,
        public float $economyLPer100km,
    ) {
        if ($tankCapacityL <= 0) {
            throw new InvalidArgumentException('Tank capacity must be positive.');
        }
        if ($startingFuelL < 0 || $startingFuelL > $tankCapacityL) {
            throw new InvalidArgumentException('Starting fuel must be within tank capacity.');
        }
        if ($reserveL < 0 || $reserveL >= $tankCapacityL) {
            throw new InvalidArgumentException('Reserve must be non-negative and below tank capacity.');
        }
        if ($economyLPer100km <= 0) {
            throw new InvalidArgumentException('Fuel economy must be positive.');
        }
    }
}

final readonly class FuelauOptimizerNode
{
    public function __construct(
        public string $id,
        public int $progressM,
        public ?int $priceTenthsCentsPerL = null,
        public string $label = '',
    ) {
        if ($id === '') {
            throw new InvalidArgumentException('Optimizer node ID must not be empty.');
        }
        if ($progressM < 0) {
            throw new InvalidArgumentException('Optimizer node progress must be non-negative.');
        }
        if ($priceTenthsCentsPerL !== null && $priceTenthsCentsPerL <= 0) {
            throw new InvalidArgumentException('Station price must be positive.');
        }
    }

    public static function station(
        string $id,
        int $progressM,
        float $priceCentsPerL,
        string $label = '',
    ): self {
        return new self(
            id: $id,
            progressM: $progressM,
            priceTenthsCentsPerL: (int) round($priceCentsPerL * 10),
            label: $label,
        );
    }
}

final readonly class FuelauOptimizerPurchase
{
    public function __construct(
        public string $nodeId,
        public string $label,
        public int $progressM,
        public float $arrivalFuelL,
        public float $purchaseL,
        public float $departureFuelL,
        public float $priceCentsPerL,
        public int $purchaseCostCents,
    ) {}
}

final readonly class FuelauOptimizerPlan
{
    /**
     * @param list<FuelauOptimizerPurchase> $purchases
     */
    public function __construct(
        public array $purchases,
        public int $fuelPurchaseCostCents,
        public float $fuelPurchasedL,
        public float $endingFuelL,
        public int $fuelStopCount,
    ) {}
}

/**
 * Pure fixed-corridor fuel-state solver.
 *
 * Version 1 uses conservative half-litre buckets. This initial engine slice
 * optimizes fuel cash cost; meaningful-stop and generalized-cost labels are
 * layered onto the same state model in the next implementation phase.
 */
final class FuelauFuelStateOptimizer
{
    private const BUCKET_L = 0.5;

    /**
     * @param list<FuelauOptimizerNode> $nodes
     */
    public function optimize(array $nodes, FuelauOptimizerVehicle $vehicle): FuelauOptimizerPlan
    {
        $nodes = array_values($nodes);
        $this->validateNodes($nodes);

        $capacityBuckets = (int) floor($vehicle->tankCapacityL / self::BUCKET_L);
        $startingBuckets = (int) floor($vehicle->startingFuelL / self::BUCKET_L);
        $reserveBuckets = (int) ceil($vehicle->reserveL / self::BUCKET_L);
        if ($startingBuckets < $reserveBuckets) {
            throw new FuelauRouteInfeasibleException('Starting fuel is below the required reserve.');
        }

        /** @var array<int, array<string, array<string, int|string>>> $states */
        $states = array_fill(0, count($nodes), []);
        $initialKey = $this->stateKey($startingBuckets, 0);
        $states[0][$initialKey] = [
            'fuel_buckets' => $startingBuckets,
            'stop_count' => 0,
            'cost_units' => 0,
            'previous_node' => -1,
            'previous_key' => '',
            'purchase_buckets' => 0,
            'departure_buckets' => $startingBuckets,
        ];

        $lastIndex = count($nodes) - 1;
        for ($fromIndex = 0; $fromIndex < $lastIndex; $fromIndex++) {
            foreach ($states[$fromIndex] as $fromKey => $state) {
                $arrivalBuckets = (int) $state['fuel_buckets'];
                foreach (
                    $this->departureOptions(
                        $nodes,
                        $fromIndex,
                        $arrivalBuckets,
                        $capacityBuckets,
                        $reserveBuckets,
                        $vehicle->economyLPer100km,
                    ) as $departureBuckets
                ) {
                    $purchaseBuckets = $departureBuckets - $arrivalBuckets;
                    $price = $nodes[$fromIndex]->priceTenthsCentsPerL;
                    if ($purchaseBuckets > 0 && $price === null) {
                        continue;
                    }
                    $nextStopCount = (int) $state['stop_count'] + ($purchaseBuckets > 0 ? 1 : 0);
                    $nextCostUnits = (int) $state['cost_units']
                        + ($purchaseBuckets * (int) ($price ?? 0));

                    for ($toIndex = $fromIndex + 1; $toIndex <= $lastIndex; $toIndex++) {
                        $fuelUsedBuckets = $this->fuelUsedBuckets(
                            $nodes[$toIndex]->progressM - $nodes[$fromIndex]->progressM,
                            $vehicle->economyLPer100km,
                        );
                        $nextFuelBuckets = $departureBuckets - $fuelUsedBuckets;
                        if ($nextFuelBuckets < $reserveBuckets) {
                            break;
                        }

                        $nextKey = $this->stateKey($nextFuelBuckets, $nextStopCount);
                        $existing = $states[$toIndex][$nextKey] ?? null;
                        if (
                            $existing !== null
                            && (int) $existing['cost_units'] <= $nextCostUnits
                        ) {
                            continue;
                        }

                        $states[$toIndex][$nextKey] = [
                            'fuel_buckets' => $nextFuelBuckets,
                            'stop_count' => $nextStopCount,
                            'cost_units' => $nextCostUnits,
                            'previous_node' => $fromIndex,
                            'previous_key' => $fromKey,
                            'purchase_buckets' => $purchaseBuckets,
                            'departure_buckets' => $departureBuckets,
                        ];
                    }
                }
            }
        }

        if ($states[$lastIndex] === []) {
            throw new FuelauRouteInfeasibleException(
                'No station sequence can reach the destination while maintaining reserve.',
            );
        }

        $bestKey = $this->bestDestinationKey($states[$lastIndex]);

        return $this->buildPlan($nodes, $states, $lastIndex, $bestKey);
    }

    /**
     * @param list<FuelauOptimizerNode> $nodes
     */
    private function validateNodes(array $nodes): void
    {
        if (count($nodes) < 2) {
            throw new InvalidArgumentException('Optimizer requires an origin and destination.');
        }

        $ids = [];
        $previousProgress = -1;
        foreach ($nodes as $index => $node) {
            if (!$node instanceof FuelauOptimizerNode) {
                throw new InvalidArgumentException("Optimizer node {$index} has an invalid type.");
            }
            if (isset($ids[$node->id])) {
                throw new InvalidArgumentException("Duplicate optimizer node ID: {$node->id}");
            }
            if ($node->progressM <= $previousProgress) {
                throw new InvalidArgumentException('Optimizer nodes must have strictly increasing progress.');
            }
            if (($index === 0 || $index === count($nodes) - 1) && $node->priceTenthsCentsPerL !== null) {
                throw new InvalidArgumentException('Origin and destination must not expose station prices.');
            }
            $ids[$node->id] = true;
            $previousProgress = $node->progressM;
        }
    }

    /**
     * @param list<FuelauOptimizerNode> $nodes
     * @return list<int>
     */
    private function departureOptions(
        array $nodes,
        int $fromIndex,
        int $arrivalBuckets,
        int $capacityBuckets,
        int $reserveBuckets,
        float $economyLPer100km,
    ): array {
        if ($fromIndex === 0) {
            return [$arrivalBuckets];
        }
        if ($nodes[$fromIndex]->priceTenthsCentsPerL === null) {
            return [$arrivalBuckets];
        }

        $targets = [$arrivalBuckets => true, $capacityBuckets => true];
        for ($toIndex = $fromIndex + 1; $toIndex < count($nodes); $toIndex++) {
            $requiredBuckets = $this->fuelUsedBuckets(
                $nodes[$toIndex]->progressM - $nodes[$fromIndex]->progressM,
                $economyLPer100km,
            ) + $reserveBuckets;
            if ($requiredBuckets > $capacityBuckets) {
                break;
            }
            if ($requiredBuckets >= $arrivalBuckets) {
                $targets[$requiredBuckets] = true;
            }
        }

        $options = array_map('intval', array_keys($targets));
        sort($options, SORT_NUMERIC);

        return $options;
    }

    private function fuelUsedBuckets(int $distanceM, float $economyLPer100km): int
    {
        $fuelUsedL = ($distanceM / 100_000) * $economyLPer100km;

        return (int) ceil($fuelUsedL / self::BUCKET_L);
    }

    private function stateKey(int $fuelBuckets, int $stopCount): string
    {
        return "{$fuelBuckets}:{$stopCount}";
    }

    /**
     * @param array<string, array<string, int|string>> $destinationStates
     */
    private function bestDestinationKey(array $destinationStates): string
    {
        $keys = array_keys($destinationStates);
        usort($keys, static function (string $leftKey, string $rightKey) use ($destinationStates): int {
            $left = $destinationStates[$leftKey];
            $right = $destinationStates[$rightKey];

            return ((int) $left['cost_units'] <=> (int) $right['cost_units'])
                ?: ((int) $left['stop_count'] <=> (int) $right['stop_count'])
                ?: ((int) $left['fuel_buckets'] <=> (int) $right['fuel_buckets']);
        });

        return $keys[0];
    }

    /**
     * @param list<FuelauOptimizerNode> $nodes
     * @param array<int, array<string, array<string, int|string>>> $states
     */
    private function buildPlan(
        array $nodes,
        array $states,
        int $destinationIndex,
        string $destinationKey,
    ): FuelauOptimizerPlan {
        $purchases = [];
        $nodeIndex = $destinationIndex;
        $stateKey = $destinationKey;
        while ($nodeIndex > 0) {
            $state = $states[$nodeIndex][$stateKey];
            $fromIndex = (int) $state['previous_node'];
            $fromKey = (string) $state['previous_key'];
            $purchaseBuckets = (int) $state['purchase_buckets'];
            if ($purchaseBuckets > 0) {
                $fromState = $states[$fromIndex][$fromKey];
                $node = $nodes[$fromIndex];
                $costUnits = $purchaseBuckets * (int) $node->priceTenthsCentsPerL;
                $purchases[] = new FuelauOptimizerPurchase(
                    nodeId: $node->id,
                    label: $node->label,
                    progressM: $node->progressM,
                    arrivalFuelL: (int) $fromState['fuel_buckets'] * self::BUCKET_L,
                    purchaseL: $purchaseBuckets * self::BUCKET_L,
                    departureFuelL: (int) $state['departure_buckets'] * self::BUCKET_L,
                    priceCentsPerL: (int) $node->priceTenthsCentsPerL / 10,
                    purchaseCostCents: (int) ceil($costUnits / 20),
                );
            }
            $nodeIndex = $fromIndex;
            $stateKey = $fromKey;
        }
        $purchases = array_reverse($purchases);
        $destinationState = $states[$destinationIndex][$destinationKey];
        $totalPurchasedL = array_reduce(
            $purchases,
            static fn (float $total, FuelauOptimizerPurchase $purchase): float => $total + $purchase->purchaseL,
            0.0,
        );

        return new FuelauOptimizerPlan(
            purchases: $purchases,
            fuelPurchaseCostCents: (int) ceil((int) $destinationState['cost_units'] / 20),
            fuelPurchasedL: $totalPurchasedL,
            endingFuelL: (int) $destinationState['fuel_buckets'] * self::BUCKET_L,
            fuelStopCount: (int) $destinationState['stop_count'],
        );
    }
}
