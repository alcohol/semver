<?php

namespace Composer\Semver\Constraint;

use Composer\Semver\Comparator;

class ConstraintOptimizer
{
    /**
     * Attempt to optimize multiple non-conjunctive constraints.
     *
     * Given an array of constraints, examine any multiconstraints and determine if
     * they represent contiguous ranges. In the case that they do, attempt to reduce
     * them to a single multiconstraint.
     *
     * This (currently) only works for ranges that have lower and upper boundaries
     * defined as >= and < (greater than or equal to, less than) respectively.
     *
     * @param ConstraintInterface[] $constraints
     *
     * @return MultiConstraint
     */
    public function orConstraints(array $constraints)
    {
        $multi = array_filter(
            $constraints,
            function (ConstraintInterface $constraint) {
                return $constraint instanceof MultiConstraint;
            }
        );

        if (2 > count($multi)) {
            return new MultiConstraint($constraints, false);
        }

        $ranges = array_filter(
            $multi,
            function (MultiConstraint $constraint) {
                if (false === $this->getLowerBoundary($constraint)) {
                    return false;
                }

                if (false === $this->getUpperBoundary($constraint)) {
                    return false;
                }

                return true;
            }
        );

        if (2 > count($ranges)) {
            return new MultiConstraint($constraints, false);
        }

        $constraints = array_udiff($constraints, $ranges, function ($a, $b) { return $a === $b ? 0 : 1; });
        $constraints = array_merge($constraints, $this->collapseContiguousRanges($ranges));

        if (1 === count($constraints)) {
            return reset($constraints);
        }

        return new MultiConstraint($constraints, false);
    }

    /**
     * @param MultiConstraint[] $constraints
     *
     * @return MultiConstraint[]
     */
    private function collapseContiguousRanges(array $constraints)
    {
        $collapsed = array();

        while (count($constraints)) {
            $constraint = array_shift($constraints);
            $neighbours = $this->findNeighbours($constraint, $constraints);

            if (!count($neighbours)) {
                $collapsed[] = $constraint;
                continue;
            }

            $constraints = array_udiff($constraints, $neighbours, function ($a, $b) { return $a === $b ? 0 : 1; });
            $neighbours[] = $constraint;
            $collapsed[] = $this->mergeRangedMultiConstraints($neighbours);
        }

        return $collapsed;
    }

    /**
     * @param MultiConstraint[] $constraints
     *
     * @return MultiConstraint
     */
    private function mergeRangedMultiConstraints(array $constraints)
    {
        $lowerBoundaries = array();
        $upperBoundaries = array();
        $other = array();

        foreach ($constraints as $constraint) {
            $lowerBoundaries[] = $this->getLowerBoundary($constraint);
            $upperBoundaries[] = $this->getUpperBoundary($constraint);

            $other = array_merge($other, array_filter(
                $constraint->getConstraints(),
                function (ConstraintInterface $constraint) {
                    if ($constraint instanceof MultiConstraint) {
                        return true;
                    }

                    if ($constraint instanceof Constraint) {
                        return Constraint::OP_LT !== $constraint->getOperator()
                            && Constraint::OP_GE !== $constraint->getOperator();
                    }

                    throw new \RuntimeException('Unexpected type: ' . get_class($constraint));
                }
            ));
        }

        $lower = array_reduce($lowerBoundaries, function ($carry, $item) {
            if (!$carry instanceof ConstraintInterface) {
                return $item;
            }

            if (Comparator::lessThan($item, $carry)) {
                return $item;
            }

            return $carry;
        });

        $upper = array_reduce($upperBoundaries, function ($carry, $item) {
            if (!$carry instanceof ConstraintInterface) {
                return $item;
            }

            if (Comparator::greaterThan($item, $carry)) {
                return $item;
            }

            return $carry;
        });

        return new MultiConstraint(array_merge(array($lower, $upper), $other));
    }

    /**
     * @param MultiConstraint $needle
     * @param array $haystack
     *
     * @return array
     */
    private function findNeighbours(MultiConstraint $needle, array $haystack)
    {
        $matches = array();

        $lowerBoundary = $this->getLowerBoundary($needle);
        $upperBoundary = $this->getUpperBoundary($needle);

        foreach ($haystack as $constraint) {
            if ($this->getLowerBoundary($constraint)->getVersion() === $upperBoundary->getVersion()) {
                $matches[] = $constraint;
                continue;
            }

            if ($this->getUpperBoundary($constraint)->getVersion() === $lowerBoundary->getVersion()) {
                $matches[] = $constraint;
                continue;
            }
        }

        return $matches;
    }

    /**
     * @param MultiConstraint $multiConstraint
     *
     * @return false|Constraint
     */
    private function getLowerBoundary(MultiConstraint $multiConstraint)
    {
        foreach ($multiConstraint->getConstraints() as $constraint) {
            if ($constraint instanceof Constraint && Constraint::OP_GE === $constraint->getOperator()) {
                return $constraint;
            }
        }

        return false;
    }

    /**
     * @param MultiConstraint $multiConstraint
     *
     * @return false|Constraint
     */
    private function getUpperBoundary(MultiConstraint $multiConstraint)
    {
        foreach ($multiConstraint->getConstraints() as $constraint) {
            if ($constraint instanceof Constraint && Constraint::OP_LT === $constraint->getOperator()) {
                return $constraint;
            }
        }

        return false;
    }
}