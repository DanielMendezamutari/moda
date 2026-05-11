<?php

namespace App\Services;

use App\Models\Unit;
use App\Models\UnitConversion;
use App\Support\UnitQuantityMath;

class UnitConversionService
{
    /**
     * Convierte una cantidad entre dos unidades usando una fila almacenada o su inversa.
     * Convención almacenada: cantidad_destino = cantidad_origen × factor (1 origen = factor × destino).
     *
     * @return array{quantity_to: string, via: 'direct'|'inverse'|'same', factor_applied: string}|null
     */
    public function convertQuantity(string $quantity, int $fromUnitId, int $toUnitId): ?array
    {
        if ($fromUnitId === $toUnitId) {
            return [
                'quantity_to' => $quantity,
                'via' => 'same',
                'factor_applied' => '1',
            ];
        }

        $from = Unit::query()->find($fromUnitId);
        $to = Unit::query()->find($toUnitId);

        if (! $from || ! $to || $from->dimension !== $to->dimension) {
            return null;
        }

        $direct = UnitConversion::query()
            ->where('from_unit_id', $fromUnitId)
            ->where('to_unit_id', $toUnitId)
            ->first();

        if ($direct) {
            $factor = (string) $direct->factor;
            $qtyTo = UnitQuantityMath::multiply($quantity, $factor);

            return [
                'quantity_to' => $qtyTo,
                'via' => 'direct',
                'factor_applied' => $factor,
            ];
        }

        $inverse = UnitConversion::query()
            ->where('from_unit_id', $toUnitId)
            ->where('to_unit_id', $fromUnitId)
            ->first();

        if ($inverse) {
            $factorStored = (string) $inverse->factor;
            $qtyTo = UnitQuantityMath::divide($quantity, $factorStored);
            $factorApplied = UnitQuantityMath::divide('1', $factorStored);

            return [
                'quantity_to' => $qtyTo,
                'via' => 'inverse',
                'factor_applied' => $factorApplied,
            ];
        }

        return null;
    }

    /**
     * ¿Existe ya una conversión (en cualquier sentido) entre dos unidades distintas?
     */
    public function pairConflictsWithOther(int $unitAId, int $unitBId, ?int $ignoreConversionId = null): bool
    {
        if ($unitAId === $unitBId) {
            return false;
        }

        $q = UnitConversion::query()
            ->where(static function ($q) use ($unitAId, $unitBId): void {
                $q->where(static function ($q2) use ($unitAId, $unitBId): void {
                    $q2->where('from_unit_id', $unitAId)->where('to_unit_id', $unitBId);
                })->orWhere(static function ($q2) use ($unitAId, $unitBId): void {
                    $q2->where('from_unit_id', $unitBId)->where('to_unit_id', $unitAId);
                });
            });

        if ($ignoreConversionId !== null) {
            $q->where('id', '!=', $ignoreConversionId);
        }

        return $q->exists();
    }

    public function assertSameDimension(Unit $from, Unit $to): void
    {
        if ($from->dimension !== $to->dimension) {
            abort(422, 'Solo se pueden relacionar unidades de la misma dimensión (cantidad, longitud, masa o volumen).');
        }
    }
}
