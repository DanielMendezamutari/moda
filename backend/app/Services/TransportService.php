<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Transport;
use App\Models\TransportDetail;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TransportService
{
    /**
     * @param  array{
     *     warehouse_start_id: int,
     *     warehouse_end_id: int,
     *     date_emision?: string|null,
     *     state?: string,
     *     description?: string|null,
     *     reference?: string|null,
     *     igv?: float|string,
     *     date_entrega?: string|null,
     *     items: list<array{product_id: int, quantity: float|string, price_unit: float|string, unit_id?: int|null, description?: string|null}>
     * }  $payload
     */
    public function createTransport(User $user, array $payload): Transport
    {
        $startId = (int) $payload['warehouse_start_id'];
        $endId = (int) $payload['warehouse_end_id'];

        if ($startId === $endId) {
            throw ValidationException::withMessages([
                'warehouse_end_id' => ['El almacén de destino debe ser distinto al de origen.'],
            ]);
        }

        $this->assertWarehousePairScope($user, $startId, $endId);

        $items = $payload['items'];

        return DB::transaction(function () use ($user, $payload, $items, $startId, $endId): Transport {
            $lineTotals = [];
            $prepared = [];

            foreach ($items as $idx => $line) {
                $product = Product::query()->lockForUpdate()->findOrFail((int) $line['product_id']);
                if (! $product->is_active) {
                    throw ValidationException::withMessages([
                        "items.{$idx}.product_id" => ['El producto no está activo.'],
                    ]);
                }

                $qty = (float) $line['quantity'];
                if ($qty <= 0) {
                    throw ValidationException::withMessages([
                        "items.{$idx}.quantity" => ['La cantidad debe ser mayor a cero.'],
                    ]);
                }
                $qtyInt = (int) round($qty);
                if (abs($qty - $qtyInt) > 0.0001) {
                    throw ValidationException::withMessages([
                        "items.{$idx}.quantity" => ['La cantidad debe ser un número entero.'],
                    ]);
                }

                $priceUnit = round((float) $line['price_unit'], 2);
                if ($priceUnit < 0) {
                    throw ValidationException::withMessages([
                        "items.{$idx}.price_unit" => ['El precio unitario no puede ser negativo.'],
                    ]);
                }

                $startPivot = null;
                foreach ([$startId => 'origen', $endId => 'destino'] as $wid => $label) {
                    $pivot = DB::table('product_warehouses')
                        ->where('product_id', $product->id)
                        ->where('warehouse_id', $wid)
                        ->lockForUpdate()
                        ->first();

                    if ($pivot === null) {
                        throw ValidationException::withMessages([
                            "items.{$idx}.product_id" => ["El producto no está asignado al almacén de {$label}."],
                        ]);
                    }
                    if ((int) $wid === $startId) {
                        $startPivot = $pivot;
                    }
                }

                $lineTotal = round($qtyInt * $priceUnit, 2);
                $lineTotals[] = $lineTotal;

                $unitId = isset($line['unit_id'])
                    ? ($line['unit_id'] !== null ? (int) $line['unit_id'] : null)
                    : ($startPivot && $startPivot->unit_id !== null ? (int) $startPivot->unit_id : null);

                $prepared[] = [
                    'product_id' => $product->id,
                    'unit_id' => $unitId,
                    'quantity' => $qtyInt,
                    'price_unit' => $priceUnit,
                    'line_total' => $lineTotal,
                    'state' => TransportDetail::STATE_SOLICITUD,
                    'description' => isset($line['description']) ? (string) $line['description'] : null,
                ];
            }

            $importe = round(array_sum($lineTotals), 2);
            $igv = round((float) ($payload['igv'] ?? 0), 2);
            if ($igv < 0) {
                throw ValidationException::withMessages(['igv' => ['El IGV no puede ser negativo.']]);
            }
            $total = round($importe + $igv, 2);

            $transport = new Transport;
            $transport->warehouse_start_id = $startId;
            $transport->warehouse_end_id = $endId;
            $transport->user_id = (int) $user->id;
            $transport->date_emision = isset($payload['date_emision']) ? $payload['date_emision'] : now()->toDateString();
            $transport->state = isset($payload['state']) ? (string) $payload['state'] : Transport::STATE_SOLICITUD;
            $transport->description = $payload['description'] ?? null;
            $transport->reference = $payload['reference'] ?? null;
            $transport->importe = $importe;
            $transport->igv = $igv;
            $transport->total = $total;
            $transport->date_entrega = $payload['date_entrega'] ?? null;
            $transport->save();

            foreach ($prepared as $row) {
                $transport->details()->create($row);
            }

            $this->syncHeaderStateFromLines($transport->fresh('details'));

            return $transport->fresh()->load([
                'warehouseStart:id,name,branch_id',
                'warehouseEnd:id,name,branch_id',
                'user:id,name',
                'details.product:id,name,sku',
                'details.unit:id,name',
                'details.userSalida:id,name',
                'details.userEntrega:id,name',
            ]);
        });
    }

    public function syncHeaderStateFromLines(Transport $transport): void
    {
        $transport->loadMissing('details');
        if ($transport->details->isEmpty()) {
            return;
        }

        $allSol = $transport->details->every(fn (TransportDetail $d) => $d->state === TransportDetail::STATE_SOLICITUD);
        $allSal = $transport->details->every(fn (TransportDetail $d) => $d->state === TransportDetail::STATE_SALIDA);
        $allEnt = $transport->details->every(fn (TransportDetail $d) => $d->state === TransportDetail::STATE_ENTREGA);
        $anyEnt = $transport->details->contains(fn (TransportDetail $d) => $d->state === TransportDetail::STATE_ENTREGA);
        $anySal = $transport->details->contains(fn (TransportDetail $d) => $d->state === TransportDetail::STATE_SALIDA);
        $anySol = $transport->details->contains(fn (TransportDetail $d) => $d->state === TransportDetail::STATE_SOLICITUD);

        if ($allSol) {
            $transport->state = Transport::STATE_SOLICITUD;
        } elseif ($allEnt) {
            $transport->state = Transport::STATE_ENTREGA;
        } elseif ($anyEnt) {
            $transport->state = Transport::STATE_LLEGADA;
        } elseif ($allSal) {
            $transport->state = Transport::STATE_SALIDA;
        } elseif ($anySal && $anySol) {
            $transport->state = Transport::STATE_REVISION_SALIDA;
        } else {
            $transport->state = Transport::STATE_REVISION_LLEGADA;
        }

        $transport->save();
    }

    private function assertWarehousePairScope(User $user, int $startId, int $endId): void
    {
        if ($user->hasRole('admin')) {
            return;
        }

        $bid = $user->branch_id;
        if ($bid === null) {
            throw ValidationException::withMessages([
                'warehouse_start_id' => ['Tu usuario no tiene sucursal asignada.'],
            ]);
        }

        foreach ([$startId, $endId] as $wid) {
            $w = Warehouse::query()->find($wid);
            if (! $w || (int) $w->branch_id !== (int) $bid) {
                throw ValidationException::withMessages([
                    'warehouse_start_id' => ['Solo podés trasladar entre almacenes de tu sucursal.'],
                ]);
            }
        }
    }
}
