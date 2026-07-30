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
        public int $progressS = 0,
        public int $accessDistanceM = 0,
        public int $accessDurationS = 0,
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
        if ($progressS < 0 || $accessDistanceM < 0 || $accessDurationS < 0) {
            throw new InvalidArgumentException('Optimizer node distance and duration values must be non-negative.');
        }
    }

    public static function station(
        string $id,
        int $progressM,
        float $priceCentsPerL,
        string $label = '',
        int $progressS = 0,
        int $accessDistanceM = 0,
        int $accessDurationS = 0,
    ): self {
        return new self(
            id: $id,
            progressM: $progressM,
            priceTenthsCentsPerL: (int) round($priceCentsPerL * 10),
            label: $label,
            progressS: $progressS,
            accessDistanceM: $accessDistanceM,
            accessDurationS: $accessDurationS,
        );
    }
}

final readonly class FuelauOptimizerPolicy
{
    public function __construct(
        public string $mode = 'practical_least_cost',
        public ?int $maximumFuelOnlyStops = null,
        public ?float $minimumDiscretionaryPurchaseL = null,
        public int $minimumStopSpacingM = 150_000,
        public int $minimumStopSpacingS = 5_400,
        public int $maximumDiscretionaryDetourM = 20_000,
        public int $maximumDiscretionaryDetourS = 1_200,
        public int $maximumSafetyDetourM = 75_000,
        public int $maximumSafetyDetourS = 3_600,
        public int $minimumNetSavingCents = 1_000,
        public int $driverTimeValueCentsPerHour = 3_000,
        public int $fuelOnlyStopSeconds = 600,
        public int $similarCostCents = 500,
    ) {
        if (!in_array($mode, ['practical_least_cost', 'fewer_stops'], true)) {
            throw new InvalidArgumentException('Unsupported optimizer policy mode.');
        }
        if ($maximumFuelOnlyStops !== null && ($maximumFuelOnlyStops < 0 || $maximumFuelOnlyStops > 20)) {
            throw new InvalidArgumentException('Maximum fuel-only stops must be between 0 and 20.');
        }
        if ($minimumDiscretionaryPurchaseL !== null && $minimumDiscretionaryPurchaseL < 0) {
            throw new InvalidArgumentException('Minimum discretionary purchase must be non-negative.');
        }
        foreach ([
            $minimumStopSpacingM,
            $minimumStopSpacingS,
            $maximumDiscretionaryDetourM,
            $maximumDiscretionaryDetourS,
            $maximumSafetyDetourM,
            $maximumSafetyDetourS,
            $minimumNetSavingCents,
            $driverTimeValueCentsPerHour,
            $fuelOnlyStopSeconds,
            $similarCostCents,
        ] as $value) {
            if ($value < 0) {
                throw new InvalidArgumentException('Optimizer policy values must be non-negative.');
            }
        }
    }

    public function stopCostCents(): int
    {
        return (int) ceil(
            ($this->driverTimeValueCentsPerHour * $this->fuelOnlyStopSeconds) / 3_600,
        );
    }
}

final readonly class FuelauOptimizerPurchase
{
    /**
     * @param list<string> $reasonCodes
     */
    public function __construct(
        public string $nodeId,
        public string $label,
        public int $progressM,
        public int $progressS,
        public int $detourDistanceM,
        public int $detourDurationS,
        public float $arrivalFuelL,
        public float $purchaseL,
        public float $departureFuelL,
        public float $priceCentsPerL,
        public int $purchaseCostCents,
        public string $classification = 'unclassified',
        public array $reasonCodes = [],
        public ?int $marginalNetSavingCents = null,
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
        public int $generalizedCostCents,
        public int $requiredStopCount = 0,
        public int $discretionaryStopCount = 0,
    ) {}
}

/**
 * Pure fixed-corridor fuel-state solver.
 *
 * Version 1 uses conservative half-litre buckets and critical departure fuel
 * levels. The practical wrapper applies meaningful-stop and marginal-saving
 * policy without introducing external state or route-service dependencies.
 */
final class FuelauFuelStateOptimizer
{
    private const BUCKET_L = 0.5;

    /**
     * @param list<FuelauOptimizerNode> $nodes
     */
    public function optimize(array $nodes, FuelauOptimizerVehicle $vehicle): FuelauOptimizerPlan
    {
        return $this->solve(
            $nodes,
            $vehicle,
            stopCostCents: 0,
            maximumStops: null,
            forbiddenNodeIds: [],
            objective: 'fuel_cost',
            similarCostCents: 0,
            accessTimeValueCentsPerHour: 0,
        );
    }

    /**
     * @param list<FuelauOptimizerNode> $nodes
     */
    public function optimizePractical(
        array $nodes,
        FuelauOptimizerVehicle $vehicle,
        ?FuelauOptimizerPolicy $policy = null,
    ): FuelauOptimizerPlan {
        $nodes = array_values($nodes);
        $this->validateNodes($nodes);
        $policy ??= new FuelauOptimizerPolicy();

        $minimumStopPlan = $this->solve(
            $nodes,
            $vehicle,
            stopCostCents: 0,
            maximumStops: null,
            forbiddenNodeIds: [],
            objective: 'fewest_stops',
            similarCostCents: 0,
            accessTimeValueCentsPerHour: 0,
        );
        $automaticAllowance = min(
            2,
            intdiv($nodes[count($nodes) - 1]->progressM, 1_000_000),
        );
        $maximumStops = $policy->maximumFuelOnlyStops
            ?? ($minimumStopPlan->fuelStopCount + $automaticAllowance);
        if ($maximumStops < $minimumStopPlan->fuelStopCount) {
            throw new FuelauRouteInfeasibleException(
                'The configured stop limit is below the minimum feasible stop count.',
            );
        }

        $forbidden = [];
        $required = [];
        $marginalSavings = [];
        $plan = $this->solve(
            $nodes,
            $vehicle,
            $policy->stopCostCents(),
            $maximumStops,
            $forbidden,
            $policy->mode === 'fewer_stops' ? 'fewest_stops' : 'generalized_cost',
            $policy->similarCostCents,
            $policy->driverTimeValueCentsPerHour,
        );

        for ($stabilizationPass = 0; $stabilizationPass < count($nodes); $stabilizationPass++) {
            for ($constraintPass = 0; $constraintPass < count($nodes); $constraintPass++) {
                $changed = false;
                $previousProgressM = 0;
                $previousProgressS = 0;
                $previousAccessDistanceM = 0;
                $previousAccessDurationS = 0;
                foreach ($plan->purchases as $purchase) {
                    if (isset($required[$purchase->nodeId])) {
                        $previousProgressM = $purchase->progressM;
                        $previousProgressS = $purchase->progressS;
                        $previousAccessDistanceM = intdiv($purchase->detourDistanceM, 2);
                        $previousAccessDurationS = intdiv($purchase->detourDurationS, 2);
                        continue;
                    }

                    $minimumPurchaseL = $policy->minimumDiscretionaryPurchaseL
                        ?? max(15.0, $vehicle->tankCapacityL * 0.25);
                    $tooSmall = $purchase->purchaseL < $minimumPurchaseL;
                    $distanceSincePhysicalStopM = ($purchase->progressM - $previousProgressM)
                        + $previousAccessDistanceM
                        + intdiv($purchase->detourDistanceM, 2);
                    $durationSincePhysicalStopS = ($purchase->progressS - $previousProgressS)
                        + $previousAccessDurationS
                        + intdiv($purchase->detourDurationS, 2);
                    $tooClose = $distanceSincePhysicalStopM < $policy->minimumStopSpacingM
                        && $durationSincePhysicalStopS < $policy->minimumStopSpacingS;
                    $tooFar = $purchase->detourDistanceM > $policy->maximumDiscretionaryDetourM
                        || $purchase->detourDurationS > $policy->maximumDiscretionaryDetourS;
                    $beyondSafetyDetour = $purchase->detourDistanceM > $policy->maximumSafetyDetourM
                        || $purchase->detourDurationS > $policy->maximumSafetyDetourS;

                    if ($tooSmall || $tooClose || $tooFar || $beyondSafetyDetour) {
                        $alternative = $this->solveWithoutNode(
                            $nodes,
                            $vehicle,
                            $policy,
                            $maximumStops,
                            $forbidden,
                            $purchase->nodeId,
                        );
                        if ($alternative === null) {
                            if ($beyondSafetyDetour) {
                                throw new FuelauRouteInfeasibleException(sprintf(
                                    'The only reachable station exceeds the maximum safety detour '
                                        . '(%d m, %d s round trip).',
                                    $purchase->detourDistanceM,
                                    $purchase->detourDurationS,
                                ));
                            }
                            $required[$purchase->nodeId] = match (true) {
                                $tooFar => 'sparse_corridor',
                                $tooSmall => 'minimum_purchase_safety_override',
                                default => 'stop_spacing_safety_override',
                            };
                        } elseif (
                            !$tooFar
                            && $alternative->fuelStopCount >= $plan->fuelStopCount
                        ) {
                            $required[$purchase->nodeId] = $tooSmall
                                ? 'minimum_purchase_safety_override'
                                : 'stop_spacing_safety_override';
                        } else {
                            $forbidden[$purchase->nodeId] = true;
                            $plan = $alternative;
                            $changed = true;
                            break;
                        }
                    }
                    $previousProgressM = $purchase->progressM;
                    $previousProgressS = $purchase->progressS;
                    $previousAccessDistanceM = intdiv($purchase->detourDistanceM, 2);
                    $previousAccessDurationS = intdiv($purchase->detourDurationS, 2);
                }
                if ($changed) {
                    continue;
                }
                break;
            }

            $auditChangedPlan = false;
            for ($auditPass = 0; $auditPass < count($nodes); $auditPass++) {
                $changed = false;
                foreach ($plan->purchases as $purchase) {
                    if (isset($required[$purchase->nodeId])) {
                        continue;
                    }
                    $alternative = $this->solveWithoutNode(
                        $nodes,
                        $vehicle,
                        $policy,
                        $maximumStops,
                        $forbidden,
                        $purchase->nodeId,
                    );
                    if ($alternative === null) {
                        $required[$purchase->nodeId] = 'reserve_feasibility';
                        continue;
                    }
                    if ($alternative->fuelStopCount >= $plan->fuelStopCount) {
                        // A different station with the same stop count is a
                        // location substitution, not removal of a physical
                        // stop. Keep the cheaper complete plan, but only label
                        // its location strategic when the location choice
                        // clears the meaningful-saving threshold.
                        $saving =
                            $alternative->generalizedCostCents - $plan->generalizedCostCents;
                        if ($saving < $policy->minimumNetSavingCents) {
                            $required[$purchase->nodeId] = 'reserve_feasibility';
                        } else {
                            $marginalSavings[$purchase->nodeId] = $saving;
                        }
                        continue;
                    }

                    $saving = $alternative->generalizedCostCents - $plan->generalizedCostCents;
                    $marginalSavings[$purchase->nodeId] = $saving;
                    if ($saving < $policy->minimumNetSavingCents) {
                        $forbidden[$purchase->nodeId] = true;
                        $plan = $alternative;
                        $changed = true;
                        $auditChangedPlan = true;
                        break;
                    }
                }
                if ($changed) {
                    continue;
                }
                break;
            }

            if (!$auditChangedPlan) {
                break;
            }
        }

        return $this->classifyPlan($plan, $required, $marginalSavings);
    }

    /**
     * @param list<FuelauOptimizerNode> $nodes
     * @param array<string, bool> $forbiddenNodeIds
     */
    private function solve(
        array $nodes,
        FuelauOptimizerVehicle $vehicle,
        int $stopCostCents,
        ?int $maximumStops,
        array $forbiddenNodeIds,
        string $objective,
        int $similarCostCents,
        int $accessTimeValueCentsPerHour,
    ): FuelauOptimizerPlan
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
            'fuel_cost_units' => 0,
            'generalized_cost_units' => 0,
            'previous_node' => -1,
            'previous_key' => '',
            'purchase_buckets' => 0,
            'departure_buckets' => $startingBuckets,
        ];

        $lastIndex = count($nodes) - 1;
        /** @var array<int, array<int, list<int>>> $departureOptionsCache */
        $departureOptionsCache = [];
        for ($fromIndex = 0; $fromIndex < $lastIndex; $fromIndex++) {
            foreach ($states[$fromIndex] as $fromKey => $state) {
                $arrivalBuckets = (int) $state['fuel_buckets'];
                $departureOptions = $departureOptionsCache[$fromIndex][$arrivalBuckets]
                    ??= $this->departureOptions(
                        $nodes,
                        $fromIndex,
                        $arrivalBuckets,
                        $capacityBuckets,
                        $reserveBuckets,
                        $vehicle->economyLPer100km,
                    );
                foreach ($departureOptions as $departureBuckets) {
                    $purchaseBuckets = $departureBuckets - $arrivalBuckets;
                    $price = $nodes[$fromIndex]->priceTenthsCentsPerL;
                    if ($fromIndex > 0 && $price !== null && $purchaseBuckets === 0) {
                        continue;
                    }
                    if ($purchaseBuckets > 0 && isset($forbiddenNodeIds[$nodes[$fromIndex]->id])) {
                        continue;
                    }
                    if ($purchaseBuckets > 0 && $price === null) {
                        continue;
                    }
                    $nextStopCount = (int) $state['stop_count'] + ($purchaseBuckets > 0 ? 1 : 0);
                    if ($maximumStops !== null && $nextStopCount > $maximumStops) {
                        continue;
                    }
                    $nextFuelCostUnits = (int) $state['fuel_cost_units']
                        + ($purchaseBuckets * (int) ($price ?? 0));
                    $purchaseGeneralizedCostUnits = (int) $state['generalized_cost_units']
                        + ($purchaseBuckets * (int) ($price ?? 0))
                        + ($purchaseBuckets > 0 ? $stopCostCents * 20 : 0);

                    for ($toIndex = $fromIndex + 1; $toIndex <= $lastIndex; $toIndex++) {
                        $corridorFuelUsedBuckets = $this->fuelUsedBuckets(
                            $nodes[$toIndex]->progressM - $nodes[$fromIndex]->progressM,
                            $vehicle->economyLPer100km,
                        );
                        if ($departureBuckets - $corridorFuelUsedBuckets < $reserveBuckets) {
                            break;
                        }
                        $fuelUsedBuckets = $this->fuelUsedBuckets(
                            $this->travelDistanceM($nodes[$fromIndex], $nodes[$toIndex]),
                            $vehicle->economyLPer100km,
                        );
                        $nextFuelBuckets = $departureBuckets - $fuelUsedBuckets;
                        if ($nextFuelBuckets < $reserveBuckets) {
                            continue;
                        }
                        $accessDurationS = $nodes[$fromIndex]->accessDurationS
                            + $nodes[$toIndex]->accessDurationS;
                        $nextGeneralizedCostUnits = $purchaseGeneralizedCostUnits
                            + $this->driverTimeCostUnits(
                                $accessDurationS,
                                $accessTimeValueCentsPerHour,
                            );

                        $nextKey = $this->stateKey($nextFuelBuckets, $nextStopCount);
                        $existing = $states[$toIndex][$nextKey] ?? null;
                        if (
                            $existing !== null
                            && (int) $existing['generalized_cost_units'] <= $nextGeneralizedCostUnits
                            && (int) $existing['fuel_cost_units'] <= $nextFuelCostUnits
                        ) {
                            continue;
                        }

                        $states[$toIndex][$nextKey] = [
                            'fuel_buckets' => $nextFuelBuckets,
                            'stop_count' => $nextStopCount,
                            'fuel_cost_units' => $nextFuelCostUnits,
                            'generalized_cost_units' => $nextGeneralizedCostUnits,
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

        $bestKey = $this->bestDestinationKey(
            $states[$lastIndex],
            $objective,
            $similarCostCents,
        );

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
                $this->travelDistanceM($nodes[$fromIndex], $nodes[$toIndex]),
                $economyLPer100km,
            ) + $reserveBuckets;
            if ($requiredBuckets > $capacityBuckets) {
                $corridorRequiredBuckets = $this->fuelUsedBuckets(
                    $nodes[$toIndex]->progressM - $nodes[$fromIndex]->progressM,
                    $economyLPer100km,
                ) + $reserveBuckets;
                if ($corridorRequiredBuckets > $capacityBuckets) {
                    break;
                }
                continue;
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

    private function travelDistanceM(
        FuelauOptimizerNode $from,
        FuelauOptimizerNode $to,
    ): int {
        return ($to->progressM - $from->progressM)
            + $from->accessDistanceM
            + $to->accessDistanceM;
    }

    private function driverTimeCostUnits(
        int $durationS,
        int $timeValueCentsPerHour,
    ): int {
        return (int) ceil(($durationS * $timeValueCentsPerHour * 20) / 3_600);
    }

    private function stateKey(int $fuelBuckets, int $stopCount): string
    {
        return "{$fuelBuckets}:{$stopCount}";
    }

    /**
     * @param array<string, array<string, int|string>> $destinationStates
     */
    private function bestDestinationKey(
        array $destinationStates,
        string $objective,
        int $similarCostCents,
    ): string
    {
        $keys = array_keys($destinationStates);
        if ($objective === 'generalized_cost' && $similarCostCents > 0) {
            $minimumCostUnits = min(array_map(
                static fn (array $state): int => (int) $state['generalized_cost_units'],
                $destinationStates,
            ));
            $maximumSimilarCostUnits = $minimumCostUnits + ($similarCostCents * 20);
            $keys = array_values(array_filter(
                $keys,
                static fn (string $key): bool =>
                    (int) $destinationStates[$key]['generalized_cost_units']
                    <= $maximumSimilarCostUnits,
            ));
            usort(
                $keys,
                static fn (string $leftKey, string $rightKey): int =>
                    ((int) $destinationStates[$leftKey]['stop_count']
                        <=> (int) $destinationStates[$rightKey]['stop_count'])
                    ?: ((int) $destinationStates[$leftKey]['generalized_cost_units']
                        <=> (int) $destinationStates[$rightKey]['generalized_cost_units'])
                    ?: ((int) $destinationStates[$rightKey]['fuel_buckets']
                        <=> (int) $destinationStates[$leftKey]['fuel_buckets']),
            );

            return $keys[0];
        }

        usort($keys, static function (string $leftKey, string $rightKey) use (
            $destinationStates,
            $objective,
        ): int {
            $left = $destinationStates[$leftKey];
            $right = $destinationStates[$rightKey];

            $primary = match ($objective) {
                'fewest_stops' => (int) $left['stop_count'] <=> (int) $right['stop_count'],
                'fuel_cost' => (int) $left['fuel_cost_units'] <=> (int) $right['fuel_cost_units'],
                default => (int) $left['generalized_cost_units'] <=> (int) $right['generalized_cost_units'],
            };
            $secondary = $objective === 'fewest_stops'
                ? (int) $left['generalized_cost_units'] <=> (int) $right['generalized_cost_units']
                : (int) $left['stop_count'] <=> (int) $right['stop_count'];

            return $primary
                ?: $secondary
                ?: ((int) $left['fuel_cost_units'] <=> (int) $right['fuel_cost_units'])
                ?: ((int) $right['fuel_buckets'] <=> (int) $left['fuel_buckets']);
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
                    progressS: $node->progressS,
                    detourDistanceM: $node->accessDistanceM * 2,
                    detourDurationS: $node->accessDurationS * 2,
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
            fuelPurchaseCostCents: (int) ceil((int) $destinationState['fuel_cost_units'] / 20),
            fuelPurchasedL: $totalPurchasedL,
            endingFuelL: (int) $destinationState['fuel_buckets'] * self::BUCKET_L,
            fuelStopCount: (int) $destinationState['stop_count'],
            generalizedCostCents: (int) ceil(
                (int) $destinationState['generalized_cost_units'] / 20,
            ),
        );
    }

    /**
     * @param list<FuelauOptimizerNode> $nodes
     * @param array<string, bool> $forbidden
     */
    private function solveWithoutNode(
        array $nodes,
        FuelauOptimizerVehicle $vehicle,
        FuelauOptimizerPolicy $policy,
        int $maximumStops,
        array $forbidden,
        string $nodeId,
    ): ?FuelauOptimizerPlan {
        $forbidden[$nodeId] = true;
        try {
            return $this->solve(
                $nodes,
                $vehicle,
                $policy->stopCostCents(),
                $maximumStops,
                $forbidden,
                $policy->mode === 'fewer_stops' ? 'fewest_stops' : 'generalized_cost',
                $policy->similarCostCents,
                $policy->driverTimeValueCentsPerHour,
            );
        } catch (FuelauRouteInfeasibleException) {
            return null;
        }
    }

    /**
     * @param array<string, string> $required
     * @param array<string, int> $marginalSavings
     */
    private function classifyPlan(
        FuelauOptimizerPlan $plan,
        array $required,
        array $marginalSavings,
    ): FuelauOptimizerPlan {
        $purchases = array_map(
            static function (FuelauOptimizerPurchase $purchase) use (
                $required,
                $marginalSavings,
            ): FuelauOptimizerPurchase {
                $requiredReason = $required[$purchase->nodeId] ?? null;

                return new FuelauOptimizerPurchase(
                    nodeId: $purchase->nodeId,
                    label: $purchase->label,
                    progressM: $purchase->progressM,
                    progressS: $purchase->progressS,
                    detourDistanceM: $purchase->detourDistanceM,
                    detourDurationS: $purchase->detourDurationS,
                    arrivalFuelL: $purchase->arrivalFuelL,
                    purchaseL: $purchase->purchaseL,
                    departureFuelL: $purchase->departureFuelL,
                    priceCentsPerL: $purchase->priceCentsPerL,
                    purchaseCostCents: $purchase->purchaseCostCents,
                    classification: $requiredReason !== null ? 'required' : 'strategic',
                    reasonCodes: [$requiredReason ?? 'lower_trip_cost'],
                    marginalNetSavingCents: $requiredReason === null
                        ? ($marginalSavings[$purchase->nodeId] ?? null)
                        : null,
                );
            },
            $plan->purchases,
        );
        $requiredStopCount = count(array_filter(
            $purchases,
            static fn (FuelauOptimizerPurchase $purchase): bool => $purchase->classification === 'required',
        ));

        return new FuelauOptimizerPlan(
            purchases: $purchases,
            fuelPurchaseCostCents: $plan->fuelPurchaseCostCents,
            fuelPurchasedL: $plan->fuelPurchasedL,
            endingFuelL: $plan->endingFuelL,
            fuelStopCount: $plan->fuelStopCount,
            generalizedCostCents: $plan->generalizedCostCents,
            requiredStopCount: $requiredStopCount,
            discretionaryStopCount: $plan->fuelStopCount - $requiredStopCount,
        );
    }
}
