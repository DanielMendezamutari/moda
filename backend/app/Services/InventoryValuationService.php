<?php

namespace App\Services;

use App\Models\Conversion;
use App\Models\InventoryKardexEntry;
use App\Models\ProductReturn;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\SaleDetail;
use App\Models\Transport;
use App\Models\TransportDetail;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * Promedio ponderado por almacén (unidad de stock del pivot) y líneas de kardex al estilo planilla (Entrada / Salida / Existencias).
 */
final class InventoryValuationService
{
    public const MOV_PURCHASE_IN = 'purchase_in';

    public const MOV_PURCHASE_REVERT = 'purchase_revert';

    public const MOV_SALE_OUT = 'sale_out';

    public const MOV_RETURN_IN = 'return_in';

    public const MOV_RETURN_REVERT = 'return_revert';

    public const MOV_TRANSPORT_OUT = 'transport_out';

    public const MOV_TRANSPORT_IN = 'transport_in';

    public const MOV_TRANSPORT_OUT_REVERT = 'transport_out_revert';

    public const MOV_TRANSPORT_IN_REVERT = 'transport_in_revert';

    public const MOV_CONVERSION = 'conversion';

    private function roundMoney(float $v): float
    {
        return round($v, 2);
    }

    private function roundCost(float $v): float
    {
        return round($v, 4);
    }

    /**
     * Ingreso por compra entregada: actualiza stock, promedio ponderado y kardex.
     */
    public function applyPurchaseInbound(stdClass $pivot, PurchaseItem $item, Purchase $purchase, int $delta): void
    {
        if ($delta <= 0) {
            throw ValidationException::withMessages([
                'quantity' => ['La cantidad recibida en stock debe ser mayor a cero.'],
            ]);
        }

        $lineTotal = $this->roundMoney((float) $item->line_total);
        $costPerUnit = $this->roundCost($lineTotal / $delta);

        $q0 = (int) $pivot->stock;
        $c0 = isset($pivot->weighted_avg_cost) && $pivot->weighted_avg_cost !== null
            ? $this->roundCost((float) $pivot->weighted_avg_cost)
            : null;

        $value0 = $q0 * ($c0 ?? 0.0);
        $q1 = $q0 + $delta;
        $value1 = $this->roundMoney($value0 + $lineTotal);
        $c1 = $q1 > 0 ? $this->roundCost($value1 / $q1) : null;
        $balanceTotal = $q1 > 0 && $c1 !== null ? $this->roundMoney($q1 * $c1) : null;

        DB::table('product_warehouses')
            ->where('id', $pivot->id)
            ->update([
                'stock' => $q1,
                'weighted_avg_cost' => $c1,
                'updated_at' => now(),
            ]);

        $this->insertKardex([
            'product_id' => (int) $pivot->product_id,
            'warehouse_id' => (int) $pivot->warehouse_id,
            'unit_id' => $pivot->unit_id !== null ? (int) $pivot->unit_id : null,
            'occurred_at' => $this->purchaseInboundOccurredAt($item),
            'movement_type' => self::MOV_PURCHASE_IN,
            'detail_label' => 'COMPRAS',
            'reference_type' => 'purchase_item',
            'reference_id' => $item->id,
            'quantity_delta' => $delta,
            'unit_cost' => $costPerUnit,
            'total_value_delta' => $lineTotal,
            'in_qty' => $delta,
            'in_unit_value' => $costPerUnit,
            'in_total_value' => $lineTotal,
            'out_qty' => null,
            'out_unit_value' => null,
            'out_total_value' => null,
            'balance_quantity' => $q1,
            'balance_avg_cost' => $c1,
            'balance_total_value' => $balanceTotal,
            'user_id' => (int) $purchase->user_id,
        ]);
    }

    public function revertPurchaseInbound(stdClass $pivot, PurchaseItem $item, Purchase $purchase, int $delta): void
    {
        $lineTotal = $this->roundMoney((float) $item->line_total);
        $q1 = (int) $pivot->stock;
        if ($q1 < $delta) {
            throw ValidationException::withMessages([
                'state' => ['No se puede revertir: stock insuficiente para deshacer la compra.'],
            ]);
        }

        $c1 = isset($pivot->weighted_avg_cost) && $pivot->weighted_avg_cost !== null
            ? $this->roundCost((float) $pivot->weighted_avg_cost)
            : null;

        $value1 = $this->roundMoney($q1 * ($c1 ?? 0.0));
        $q0 = $q1 - $delta;
        $value0 = $this->roundMoney($value1 - $lineTotal);
        $c0 = $q0 > 0 ? $this->roundCost($value0 / $q0) : null;
        $balanceTotal = $q0 > 0 && $c0 !== null ? $this->roundMoney($q0 * $c0) : null;

        DB::table('product_warehouses')
            ->where('id', $pivot->id)
            ->update([
                'stock' => $q0,
                'weighted_avg_cost' => $c0,
                'updated_at' => now(),
            ]);

        $costPerUnit = $delta > 0 ? $this->roundCost($lineTotal / $delta) : 0.0;

        $this->insertKardex([
            'product_id' => (int) $pivot->product_id,
            'warehouse_id' => (int) $pivot->warehouse_id,
            'unit_id' => $pivot->unit_id !== null ? (int) $pivot->unit_id : null,
            'occurred_at' => now(),
            'movement_type' => self::MOV_PURCHASE_REVERT,
            'detail_label' => 'ANULACIÓN COMPRA',
            'reference_type' => 'purchase_item',
            'reference_id' => $item->id,
            'quantity_delta' => -$delta,
            'unit_cost' => $costPerUnit,
            'total_value_delta' => -$lineTotal,
            'in_qty' => null,
            'in_unit_value' => null,
            'in_total_value' => null,
            'out_qty' => $delta,
            'out_unit_value' => $costPerUnit,
            'out_total_value' => $lineTotal,
            'balance_quantity' => $q0,
            'balance_avg_cost' => $c0,
            'balance_total_value' => $balanceTotal,
            'user_id' => (int) $purchase->user_id,
        ]);
    }

    /**
     * Salida por venta: el costo unitario de salida es el promedio antes del movimiento; el promedio no cambia hasta agotar stock.
     */
    public function applySaleOutbound(stdClass $pivot, SaleDetail $detail, int $userId): void
    {
        $qty = (int) round((float) $detail->quantity);
        if ($qty <= 0) {
            throw ValidationException::withMessages([
                'quantity' => ['Cantidad inválida.'],
            ]);
        }

        $q0 = (int) $pivot->stock;
        if ($q0 < $qty) {
            throw ValidationException::withMessages([
                'quantity' => ['Stock insuficiente.'],
            ]);
        }

        $cBefore = isset($pivot->weighted_avg_cost) && $pivot->weighted_avg_cost !== null
            ? $this->roundCost((float) $pivot->weighted_avg_cost)
            : 0.0;

        $outTotal = $this->roundMoney($qty * $cBefore);
        $q1 = $q0 - $qty;
        $c1 = $q1 > 0 ? $cBefore : null;

        DB::table('product_warehouses')
            ->where('id', $pivot->id)
            ->update([
                'stock' => $q1,
                'weighted_avg_cost' => $c1,
                'updated_at' => now(),
            ]);

        $balanceTotal = $q1 > 0 && $c1 !== null ? $this->roundMoney($q1 * $c1) : null;

        $this->insertKardex([
            'product_id' => (int) $detail->product_id,
            'warehouse_id' => (int) $detail->warehouse_id,
            'unit_id' => $pivot->unit_id !== null ? (int) $pivot->unit_id : null,
            'occurred_at' => now(),
            'movement_type' => self::MOV_SALE_OUT,
            'detail_label' => 'DESPACHO',
            'reference_type' => 'sale_detail',
            'reference_id' => $detail->id,
            'quantity_delta' => -$qty,
            'unit_cost' => $cBefore > 0 ? $cBefore : null,
            'total_value_delta' => -$outTotal,
            'in_qty' => null,
            'in_unit_value' => null,
            'in_total_value' => null,
            'out_qty' => $qty,
            'out_unit_value' => $cBefore > 0 ? $cBefore : null,
            'out_total_value' => $outTotal > 0 ? $outTotal : null,
            'balance_quantity' => $q1,
            'balance_avg_cost' => $c1,
            'balance_total_value' => $balanceTotal,
            'user_id' => $userId,
        ]);
    }

    public function applyReturnInbound(stdClass $pivot, ProductReturn $return): void
    {
        $qty = (int) round((float) $return->quantity);
        if ($qty <= 0) {
            throw ValidationException::withMessages([
                'quantity' => ['Cantidad inválida.'],
            ]);
        }

        $q0 = (int) $pivot->stock;
        $c0 = isset($pivot->weighted_avg_cost) && $pivot->weighted_avg_cost !== null
            ? $this->roundCost((float) $pivot->weighted_avg_cost)
            : null;

        $unitIn = $c0 ?? 0.0;
        $inTotal = $this->roundMoney($qty * $unitIn);
        $q1 = $q0 + $qty;
        $value1 = $this->roundMoney($q0 * ($c0 ?? 0.0) + $inTotal);
        $c1 = $q1 > 0 ? $this->roundCost($value1 / $q1) : null;
        $balanceTotal = $q1 > 0 && $c1 !== null ? $this->roundMoney($q1 * $c1) : null;

        DB::table('product_warehouses')
            ->where('id', $pivot->id)
            ->update([
                'stock' => $q1,
                'weighted_avg_cost' => $c1,
                'updated_at' => now(),
            ]);

        $this->insertKardex([
            'product_id' => (int) $return->product_id,
            'warehouse_id' => (int) $return->warehouse_id,
            'unit_id' => $pivot->unit_id !== null ? (int) $pivot->unit_id : null,
            'occurred_at' => now(),
            'movement_type' => self::MOV_RETURN_IN,
            'detail_label' => 'DEVOLUCIÓN',
            'reference_type' => 'product_return',
            'reference_id' => $return->id,
            'quantity_delta' => $qty,
            'unit_cost' => $unitIn > 0 ? $unitIn : null,
            'total_value_delta' => $inTotal > 0 ? $inTotal : null,
            'in_qty' => $qty,
            'in_unit_value' => $unitIn > 0 ? $unitIn : null,
            'in_total_value' => $inTotal > 0 ? $inTotal : null,
            'out_qty' => null,
            'out_unit_value' => null,
            'out_total_value' => null,
            'balance_quantity' => $q1,
            'balance_avg_cost' => $c1,
            'balance_total_value' => $balanceTotal,
            'user_id' => (int) $return->user_id,
        ]);
    }

    public function revertReturnInbound(stdClass $pivot, ProductReturn $return): void
    {
        $qty = (int) round((float) $return->quantity);
        $q1 = (int) $pivot->stock;
        if ($q1 < $qty) {
            throw ValidationException::withMessages([
                'state' => ['No se puede revertir: stock insuficiente.'],
            ]);
        }

        $c1 = isset($pivot->weighted_avg_cost) && $pivot->weighted_avg_cost !== null
            ? $this->roundCost((float) $pivot->weighted_avg_cost)
            : null;

        $unitOut = $c1 ?? 0.0;
        $outTotal = $this->roundMoney($qty * $unitOut);
        $q0 = $q1 - $qty;
        $value0 = $this->roundMoney($q1 * ($c1 ?? 0.0) - $outTotal);
        $c0 = $q0 > 0 ? $this->roundCost($value0 / $q0) : null;
        $balanceTotal = $q0 > 0 && $c0 !== null ? $this->roundMoney($q0 * $c0) : null;

        DB::table('product_warehouses')
            ->where('id', $pivot->id)
            ->update([
                'stock' => $q0,
                'weighted_avg_cost' => $c0,
                'updated_at' => now(),
            ]);

        $this->insertKardex([
            'product_id' => (int) $return->product_id,
            'warehouse_id' => (int) $return->warehouse_id,
            'unit_id' => $pivot->unit_id !== null ? (int) $pivot->unit_id : null,
            'occurred_at' => now(),
            'movement_type' => self::MOV_RETURN_REVERT,
            'detail_label' => 'REVERSIÓN DEVOLUCIÓN',
            'reference_type' => 'product_return',
            'reference_id' => $return->id,
            'quantity_delta' => -$qty,
            'unit_cost' => $unitOut > 0 ? $unitOut : null,
            'total_value_delta' => -$outTotal,
            'in_qty' => null,
            'in_unit_value' => null,
            'in_total_value' => null,
            'out_qty' => $qty,
            'out_unit_value' => $unitOut > 0 ? $unitOut : null,
            'out_total_value' => $outTotal > 0 ? $outTotal : null,
            'balance_quantity' => $q0,
            'balance_avg_cost' => $c0,
            'balance_total_value' => $balanceTotal,
            'user_id' => (int) $return->user_id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function insertKardex(array $row): void
    {
        InventoryKardexEntry::query()->create($row);
    }

    /**
     * Fecha contable del ingreso por compra (fecha de entrega preferida; si no, cuando se registró el ingreso).
     */
    public function purchaseInboundOccurredAt(PurchaseItem $item): Carbon
    {
        if ($item->date_entrega !== null) {
            return Carbon::parse($item->date_entrega);
        }
        if ($item->inventory_received_at !== null) {
            return Carbon::parse($item->inventory_received_at);
        }

        return now();
    }

    /**
     * Inserta solo la fila de kardex por compra entregada (sin tocar stock). Para respaldo histórico.
     *
     * @param  array{q0: int, c0: ?float}  $openingBalances  Saldo y costo promedio **antes** de este movimiento.
     */
    public function insertPurchaseInboundKardexOnly(
        stdClass $pivot,
        PurchaseItem $item,
        Purchase $purchase,
        int $delta,
        int $q0,
        ?float $c0,
        Carbon $occurredAt,
    ): void {
        if ($delta <= 0) {
            throw ValidationException::withMessages([
                'quantity' => ['La cantidad recibida en stock debe ser mayor a cero.'],
            ]);
        }

        $lineTotal = $this->roundMoney((float) $item->line_total);
        $costPerUnit = $this->roundCost($lineTotal / $delta);

        $c0r = $c0 !== null ? $this->roundCost($c0) : null;
        $value0 = $q0 * ($c0r ?? 0.0);
        $q1 = $q0 + $delta;
        $value1 = $this->roundMoney($value0 + $lineTotal);
        $c1 = $q1 > 0 ? $this->roundCost($value1 / $q1) : null;
        $balanceTotal = $q1 > 0 && $c1 !== null ? $this->roundMoney($q1 * $c1) : null;

        $this->insertKardex([
            'product_id' => (int) $pivot->product_id,
            'warehouse_id' => (int) $pivot->warehouse_id,
            'unit_id' => $pivot->unit_id !== null ? (int) $pivot->unit_id : null,
            'occurred_at' => $occurredAt,
            'movement_type' => self::MOV_PURCHASE_IN,
            'detail_label' => 'COMPRAS',
            'reference_type' => 'purchase_item',
            'reference_id' => $item->id,
            'quantity_delta' => $delta,
            'unit_cost' => $costPerUnit,
            'total_value_delta' => $lineTotal,
            'in_qty' => $delta,
            'in_unit_value' => $costPerUnit,
            'in_total_value' => $lineTotal,
            'out_qty' => null,
            'out_unit_value' => null,
            'out_total_value' => null,
            'balance_quantity' => $q1,
            'balance_avg_cost' => $c1,
            'balance_total_value' => $balanceTotal,
            'user_id' => (int) $purchase->user_id,
        ]);
    }

    /**
     * Salida por traslado en almacén origen: stock y costo promedio (sin cambiar el CPU al salir al costo promedio).
     * Congela costo en la línea para la entrada en destino.
     */
    public function applyTransportOriginOut(stdClass $pivot, TransportDetail $line, Transport $transport, int $qty): void
    {
        if ($qty <= 0) {
            throw ValidationException::withMessages([
                'quantity' => ['La cantidad de traslado debe ser mayor a cero.'],
            ]);
        }

        $q0 = (int) $pivot->stock;
        if ($q0 < $qty) {
            throw ValidationException::withMessages([
                'state' => ['Stock insuficiente en origen.'],
            ]);
        }

        $c0 = isset($pivot->weighted_avg_cost) && $pivot->weighted_avg_cost !== null
            ? $this->roundCost((float) $pivot->weighted_avg_cost)
            : null;

        $outTotal = $this->roundMoney($qty * ($c0 ?? 0.0));
        $outUnit = $c0 !== null ? $c0 : null;

        $q1 = $q0 - $qty;
        $c1 = $c0;
        $balanceTotal = ($q1 > 0 && $c1 !== null) ? $this->roundMoney($q1 * $c1) : null;

        DB::table('product_warehouses')
            ->where('id', $pivot->id)
            ->update([
                'stock' => $q1,
                'weighted_avg_cost' => $c1,
                'updated_at' => now(),
            ]);

        TransportDetail::query()->whereKey($line->id)->update([
            'transfer_unit_cost' => $c0,
            'transfer_value' => $outTotal,
        ]);

        $occ = $line->date_salida !== null ? Carbon::parse($line->date_salida) : now();

        $this->insertKardex([
            'product_id' => (int) $pivot->product_id,
            'warehouse_id' => (int) $pivot->warehouse_id,
            'unit_id' => $pivot->unit_id !== null ? (int) $pivot->unit_id : null,
            'occurred_at' => $occ,
            'movement_type' => self::MOV_TRANSPORT_OUT,
            'detail_label' => 'TRASLADO (SALIDA)',
            'reference_type' => 'transport_detail',
            'reference_id' => $line->id,
            'quantity_delta' => -$qty,
            'unit_cost' => $outUnit,
            'total_value_delta' => $outTotal > 0 ? -$outTotal : null,
            'in_qty' => null,
            'in_unit_value' => null,
            'in_total_value' => null,
            'out_qty' => $qty,
            'out_unit_value' => $outUnit,
            'out_total_value' => $outTotal > 0 ? $outTotal : null,
            'balance_quantity' => $q1,
            'balance_avg_cost' => $c1,
            'balance_total_value' => $balanceTotal,
            'user_id' => (int) $transport->user_id,
        ]);
    }

    public function revertTransportOriginOut(stdClass $pivot, TransportDetail $line, Transport $transport, int $qty): void
    {
        $q0 = (int) $pivot->stock;
        $q1 = $q0 + $qty;

        $c0 = isset($pivot->weighted_avg_cost) && $pivot->weighted_avg_cost !== null
            ? $this->roundCost((float) $pivot->weighted_avg_cost)
            : null;

        $tu = $line->transfer_unit_cost !== null ? $this->roundCost((float) $line->transfer_unit_cost) : null;
        $inTotal = $this->roundMoney($qty * ($tu ?? 0.0));

        $value0 = $this->roundMoney($q0 * ($c0 ?? 0.0));
        $value1 = $this->roundMoney($value0 + $inTotal);
        $c1 = $q1 > 0 ? $this->roundCost($value1 / $q1) : null;
        $balanceTotal = $q1 > 0 && $c1 !== null ? $this->roundMoney($q1 * $c1) : null;

        DB::table('product_warehouses')
            ->where('id', $pivot->id)
            ->update([
                'stock' => $q1,
                'weighted_avg_cost' => $c1,
                'updated_at' => now(),
            ]);

        TransportDetail::query()->whereKey($line->id)->update([
            'transfer_unit_cost' => null,
            'transfer_value' => null,
        ]);

        $occ = $line->date_salida !== null ? Carbon::parse($line->date_salida) : now();

        $this->insertKardex([
            'product_id' => (int) $pivot->product_id,
            'warehouse_id' => (int) $pivot->warehouse_id,
            'unit_id' => $pivot->unit_id !== null ? (int) $pivot->unit_id : null,
            'occurred_at' => $occ,
            'movement_type' => self::MOV_TRANSPORT_OUT_REVERT,
            'detail_label' => 'ANULACIÓN TRASLADO (ORIGEN)',
            'reference_type' => 'transport_detail',
            'reference_id' => $line->id,
            'quantity_delta' => $qty,
            'unit_cost' => $tu,
            'total_value_delta' => $inTotal > 0 ? $inTotal : null,
            'in_qty' => $qty,
            'in_unit_value' => $tu,
            'in_total_value' => $inTotal > 0 ? $inTotal : null,
            'out_qty' => null,
            'out_unit_value' => null,
            'out_total_value' => null,
            'balance_quantity' => $q1,
            'balance_avg_cost' => $c1,
            'balance_total_value' => $balanceTotal,
            'user_id' => (int) $transport->user_id,
        ]);
    }

    public function applyTransportDestinationIn(stdClass $pivot, TransportDetail $line, Transport $transport, int $qty): void
    {
        if ($qty <= 0) {
            throw ValidationException::withMessages([
                'quantity' => ['La cantidad de traslado debe ser mayor a cero.'],
            ]);
        }

        $tu = $line->transfer_unit_cost !== null ? $this->roundCost((float) $line->transfer_unit_cost) : null;
        $inTotal = $line->transfer_value !== null
            ? $this->roundMoney((float) $line->transfer_value)
            : $this->roundMoney($qty * ($tu ?? 0.0));

        $inUnit = $tu !== null && $tu > 0 ? $tu : ($inTotal > 0 && $qty > 0 ? $this->roundCost($inTotal / $qty) : null);

        $q0 = (int) $pivot->stock;
        $c0 = isset($pivot->weighted_avg_cost) && $pivot->weighted_avg_cost !== null
            ? $this->roundCost((float) $pivot->weighted_avg_cost)
            : null;

        $q1 = $q0 + $qty;
        $value1 = $this->roundMoney($q0 * ($c0 ?? 0.0) + $inTotal);
        $c1 = $q1 > 0 ? $this->roundCost($value1 / $q1) : null;
        $balanceTotal = $q1 > 0 && $c1 !== null ? $this->roundMoney($q1 * $c1) : null;

        DB::table('product_warehouses')
            ->where('id', $pivot->id)
            ->update([
                'stock' => $q1,
                'weighted_avg_cost' => $c1,
                'updated_at' => now(),
            ]);

        $occ = $line->date_entrega !== null ? Carbon::parse($line->date_entrega) : now();

        $this->insertKardex([
            'product_id' => (int) $pivot->product_id,
            'warehouse_id' => (int) $pivot->warehouse_id,
            'unit_id' => $pivot->unit_id !== null ? (int) $pivot->unit_id : null,
            'occurred_at' => $occ,
            'movement_type' => self::MOV_TRANSPORT_IN,
            'detail_label' => 'TRASLADO (ENTRADA)',
            'reference_type' => 'transport_detail',
            'reference_id' => $line->id,
            'quantity_delta' => $qty,
            'unit_cost' => $inUnit,
            'total_value_delta' => $inTotal > 0 ? $inTotal : null,
            'in_qty' => $qty,
            'in_unit_value' => $inUnit,
            'in_total_value' => $inTotal > 0 ? $inTotal : null,
            'out_qty' => null,
            'out_unit_value' => null,
            'out_total_value' => null,
            'balance_quantity' => $q1,
            'balance_avg_cost' => $c1,
            'balance_total_value' => $balanceTotal,
            'user_id' => (int) $transport->user_id,
        ]);
    }

    public function revertTransportDestinationIn(stdClass $pivot, TransportDetail $line, Transport $transport, int $qty): void
    {
        $q0 = (int) $pivot->stock;
        if ($q0 < $qty) {
            throw ValidationException::withMessages([
                'state' => ['Stock insuficiente en destino para anular la entrega.'],
            ]);
        }

        $cBefore = isset($pivot->weighted_avg_cost) && $pivot->weighted_avg_cost !== null
            ? $this->roundCost((float) $pivot->weighted_avg_cost)
            : null;

        $outTotal = $this->roundMoney($qty * ($cBefore ?? 0.0));
        $q1 = $q0 - $qty;
        $c1 = $q1 > 0 ? $cBefore : null;
        $balanceTotal = $q1 > 0 && $c1 !== null ? $this->roundMoney($q1 * $c1) : null;

        DB::table('product_warehouses')
            ->where('id', $pivot->id)
            ->update([
                'stock' => $q1,
                'weighted_avg_cost' => $c1,
                'updated_at' => now(),
            ]);

        $occ = $line->date_entrega !== null ? Carbon::parse($line->date_entrega) : now();

        $this->insertKardex([
            'product_id' => (int) $pivot->product_id,
            'warehouse_id' => (int) $pivot->warehouse_id,
            'unit_id' => $pivot->unit_id !== null ? (int) $pivot->unit_id : null,
            'occurred_at' => $occ,
            'movement_type' => self::MOV_TRANSPORT_IN_REVERT,
            'detail_label' => 'ANULACIÓN TRASLADO (DESTINO)',
            'reference_type' => 'transport_detail',
            'reference_id' => $line->id,
            'quantity_delta' => -$qty,
            'unit_cost' => $cBefore,
            'total_value_delta' => $outTotal > 0 ? -$outTotal : null,
            'in_qty' => null,
            'in_unit_value' => null,
            'in_total_value' => null,
            'out_qty' => $qty,
            'out_unit_value' => $cBefore,
            'out_total_value' => $outTotal > 0 ? $outTotal : null,
            'balance_quantity' => $q1,
            'balance_avg_cost' => $c1,
            'balance_total_value' => $balanceTotal,
            'user_id' => (int) $transport->user_id,
        ]);
    }

    /**
     * Tras cambiar stock por conversión de unidades: recalcula costo promedio conservando valor total y registra kardex.
     *
     * @param  int  $q0  Stock antes del cambio.
     * @param  ?float  $c0  Costo promedio antes del cambio.
     */
    public function applyConversionAfterStockChange(
        stdClass $pivot,
        Conversion $conversion,
        int $q0,
        ?float $c0,
        int $delta,
        int $userId,
    ): void {
        if ($delta === 0) {
            return;
        }

        $q1 = (int) $pivot->stock;

        $c0r = $c0 !== null ? $this->roundCost($c0) : null;
        $total0 = $this->roundMoney($q0 * ($c0r ?? 0.0));

        $c1 = null;
        $balanceTotal = null;
        if ($q1 > 0 && $c0r !== null) {
            $c1 = $this->roundCost($total0 / $q1);
            $balanceTotal = $this->roundMoney($q1 * $c1);
        }

        DB::table('product_warehouses')
            ->where('id', $pivot->id)
            ->update([
                'weighted_avg_cost' => $c1,
                'updated_at' => now(),
            ]);

        $occ = $conversion->created_at !== null ? Carbon::parse($conversion->created_at) : now();

        $this->insertKardex([
            'product_id' => (int) $conversion->product_id,
            'warehouse_id' => (int) $conversion->warehouse_id,
            'unit_id' => $pivot->unit_id !== null ? (int) $pivot->unit_id : null,
            'occurred_at' => $occ,
            'movement_type' => self::MOV_CONVERSION,
            'detail_label' => 'CONVERSIÓN',
            'reference_type' => 'conversion',
            'reference_id' => $conversion->id,
            'quantity_delta' => $delta,
            'unit_cost' => null,
            'total_value_delta' => null,
            'in_qty' => $delta > 0 ? $delta : null,
            'in_unit_value' => null,
            'in_total_value' => null,
            'out_qty' => $delta < 0 ? -$delta : null,
            'out_unit_value' => null,
            'out_total_value' => null,
            'balance_quantity' => $q1,
            'balance_avg_cost' => $c1,
            'balance_total_value' => $balanceTotal,
            'user_id' => $userId,
        ]);
    }
}
