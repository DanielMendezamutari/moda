<?php

namespace App\Services;

use App\Models\Transport;
use App\Models\TransportDetail;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Salida: descuenta stock en almacén origen. Entrega: suma stock en almacén destino.
 */
final class TransportInventoryService
{
    public function __construct(
        private readonly InventoryValuationService $valuation,
    ) {}

    public function syncAfterLineStateChange(TransportDetail $line, ?string $previousState, Transport $transport): void
    {
        $prev = $previousState ?? '';
        $next = (string) $line->state;

        if ($prev === TransportDetail::STATE_SOLICITUD && $next === TransportDetail::STATE_SALIDA) {
            $this->applyDeparture($line, $transport);

            return;
        }

        if ($prev === TransportDetail::STATE_SALIDA && $next === TransportDetail::STATE_SOLICITUD) {
            $this->revertDeparture($line, $transport);

            return;
        }

        if ($prev === TransportDetail::STATE_SALIDA && $next === TransportDetail::STATE_ENTREGA) {
            $this->applyArrival($line, $transport);

            return;
        }

        if ($prev === TransportDetail::STATE_ENTREGA && $next === TransportDetail::STATE_SALIDA) {
            $this->revertArrival($line, $transport);

            return;
        }
    }

    private function applyDeparture(TransportDetail $line, Transport $transport): void
    {
        if ($line->inventory_departed_at !== null) {
            return;
        }

        $qtyInt = $this->integerQuantity($line);
        $warehouseId = (int) $transport->warehouse_start_id;
        $productId = (int) $line->product_id;

        DB::transaction(function () use ($line, $transport, $warehouseId, $productId, $qtyInt): void {
            $pivot = DB::table('product_warehouses')
                ->where('product_id', $productId)
                ->where('warehouse_id', $warehouseId)
                ->lockForUpdate()
                ->first();

            if ($pivot === null) {
                throw ValidationException::withMessages([
                    'product_id' => ['El producto no está asociado al almacén de origen.'],
                ]);
            }

            if ((int) $pivot->stock < $qtyInt) {
                throw ValidationException::withMessages([
                    'state' => ['Stock insuficiente en origen ('.(int) $pivot->stock.' < '.$qtyInt.').'],
                ]);
            }

            $this->valuation->applyTransportOriginOut($pivot, $line, $transport, $qtyInt);

            $line->forceFill(['inventory_departed_at' => now()])->save();
        });
    }

    private function revertDeparture(TransportDetail $line, Transport $transport): void
    {
        if ($line->inventory_departed_at === null) {
            return;
        }

        if ($line->inventory_arrived_at !== null) {
            throw ValidationException::withMessages([
                'state' => ['No se puede revertir la salida: la línea ya fue entregada en destino. Revertí primero la entrega.'],
            ]);
        }

        $qtyInt = $this->integerQuantity($line);
        $warehouseId = (int) $transport->warehouse_start_id;
        $productId = (int) $line->product_id;

        DB::transaction(function () use ($line, $transport, $warehouseId, $productId, $qtyInt): void {
            $pivot = DB::table('product_warehouses')
                ->where('product_id', $productId)
                ->where('warehouse_id', $warehouseId)
                ->lockForUpdate()
                ->first();

            if ($pivot === null) {
                throw ValidationException::withMessages([
                    'product_id' => ['No se pudo revertir: falta fila producto–almacén en origen.'],
                ]);
            }

            $this->valuation->revertTransportOriginOut($pivot, $line, $transport, $qtyInt);

            $line->forceFill(['inventory_departed_at' => null])->save();
        });
    }

    private function applyArrival(TransportDetail $line, Transport $transport): void
    {
        if ($line->inventory_arrived_at !== null) {
            return;
        }

        if ($line->inventory_departed_at === null) {
            throw ValidationException::withMessages([
                'state' => ['No se puede registrar entrega sin salida de origen.'],
            ]);
        }

        $qtyInt = $this->integerQuantity($line);
        $warehouseId = (int) $transport->warehouse_end_id;
        $productId = (int) $line->product_id;

        DB::transaction(function () use ($line, $transport, $warehouseId, $productId, $qtyInt): void {
            $pivot = DB::table('product_warehouses')
                ->where('product_id', $productId)
                ->where('warehouse_id', $warehouseId)
                ->lockForUpdate()
                ->first();

            if ($pivot === null) {
                throw ValidationException::withMessages([
                    'product_id' => ['El producto no está asociado al almacén de destino. Asignalo en el catálogo antes de recibir el traslado.'],
                ]);
            }

            $this->valuation->applyTransportDestinationIn($pivot, $line, $transport, $qtyInt);

            $line->forceFill(['inventory_arrived_at' => now()])->save();
        });
    }

    private function revertArrival(TransportDetail $line, Transport $transport): void
    {
        if ($line->inventory_arrived_at === null) {
            return;
        }

        $qtyInt = $this->integerQuantity($line);
        $warehouseId = (int) $transport->warehouse_end_id;
        $productId = (int) $line->product_id;

        DB::transaction(function () use ($line, $transport, $warehouseId, $productId, $qtyInt): void {
            $pivot = DB::table('product_warehouses')
                ->where('product_id', $productId)
                ->where('warehouse_id', $warehouseId)
                ->lockForUpdate()
                ->first();

            if ($pivot === null) {
                throw ValidationException::withMessages([
                    'product_id' => ['No se pudo revertir entrega: falta fila producto–almacén en destino.'],
                ]);
            }

            if ((int) $pivot->stock < $qtyInt) {
                throw ValidationException::withMessages([
                    'state' => ['No se puede revertir la entrega: stock en destino insuficiente.'],
                ]);
            }

            $this->valuation->revertTransportDestinationIn($pivot, $line, $transport, $qtyInt);

            $line->forceFill(['inventory_arrived_at' => null])->save();
        });
    }

    private function integerQuantity(TransportDetail $line): int
    {
        $q = (float) $line->quantity;
        $n = (int) round($q);
        if ($n <= 0 || abs($q - $n) > 0.0001) {
            throw ValidationException::withMessages([
                'quantity' => ['La cantidad debe ser un entero positivo (unidades de stock).'],
            ]);
        }

        return $n;
    }
}
