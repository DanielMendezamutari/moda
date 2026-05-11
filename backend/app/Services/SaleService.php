<?php

namespace App\Services;

use App\Models\CashMovement;
use App\Models\CashRegisterSession;
use App\Models\Client;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaleService
{
    public function __construct(
        private readonly ClientCreditService $creditService,
        private readonly CashRegisterFlowService $cashFlow,
        private readonly CashMovementRecorder $cashMovementRecorder,
        private readonly InventoryValuationService $valuation,
    ) {}

    /**
     * @param  array{
     *     reference?: string|null,
     *     client_id?: int|null,
     *     type_client?: string|null,
     *     description?: string|null,
     *     igv?: float|string,
     *     items: list<array{product_id: int, warehouse_id: int, quantity: float|string, unit_price: float|string, unit_id?: int|null, category_id?: int|null, discount?: float|string}>,
     *     payments?: list<array{method_payment: string, amount: float|string, n_transaction?: string|null}>
     * }  $payload
     */
    public function createSale(User $user, array $payload): Sale
    {
        $items = $payload['items'];
        $igv = round((float) ($payload['igv'] ?? 0), 2);
        $cashSessionId = isset($payload['cash_register_session_id']) ? (int) $payload['cash_register_session_id'] : null;
        $cashSession = $this->cashFlow->assertSessionForSale($user, $cashSessionId > 0 ? $cashSessionId : null);

        return DB::transaction(function () use ($user, $payload, $items, $igv, $cashSession): Sale {
            $lineTotals = [];
            $prepared = [];
            $lineSnapshots = [];

            foreach ($items as $idx => $line) {
                $product = Product::query()->lockForUpdate()->findOrFail((int) $line['product_id']);
                if (! $product->is_active) {
                    throw ValidationException::withMessages([
                        "items.{$idx}.product_id" => ['El producto no está activo.'],
                    ]);
                }

                $warehouseId = (int) $line['warehouse_id'];
                $qty = (float) $line['quantity'];
                if ($qty <= 0) {
                    throw ValidationException::withMessages([
                        "items.{$idx}.quantity" => ['La cantidad debe ser mayor a cero.'],
                    ]);
                }

                $qtyInt = (int) round($qty);
                if (abs($qty - $qtyInt) > 0.0001) {
                    throw ValidationException::withMessages([
                        "items.{$idx}.quantity" => ['Por ahora la cantidad debe ser un número entero (stock por unidad).'],
                    ]);
                }

                $this->assertWarehouseScope($user, $warehouseId, $idx);

                $unitPrice = round((float) $line['unit_price'], 2);
                $discount = round((float) ($line['discount'] ?? 0), 2);
                if ($discount < 0) {
                    throw ValidationException::withMessages([
                        "items.{$idx}.discount" => ['El descuento no puede ser negativo.'],
                    ]);
                }

                $lineSubtotal = round($qtyInt * $unitPrice, 2);
                if ($discount > $lineSubtotal + 0.0001) {
                    throw ValidationException::withMessages([
                        "items.{$idx}.discount" => ['El descuento no puede superar el subtotal de la línea.'],
                    ]);
                }

                $lineTotal = round($lineSubtotal - $discount, 2);

                $pivot = DB::table('product_warehouses')
                    ->where('product_id', $product->id)
                    ->where('warehouse_id', $warehouseId)
                    ->lockForUpdate()
                    ->first();

                if ($pivot === null) {
                    throw ValidationException::withMessages([
                        "items.{$idx}.warehouse_id" => ['El producto no tiene stock en ese almacén.'],
                    ]);
                }

                if ((int) $pivot->stock < $qtyInt) {
                    throw ValidationException::withMessages([
                        "items.{$idx}.quantity" => ['Stock insuficiente (disponible: '.(int) $pivot->stock.').'],
                    ]);
                }

                $categoryId = isset($line['category_id']) ? (int) $line['category_id'] : $product->category_id;
                $unitId = isset($line['unit_id']) ? ($line['unit_id'] !== null ? (int) $line['unit_id'] : null) : ($pivot->unit_id !== null ? (int) $pivot->unit_id : null);

                $prepared[] = [
                    'product_id' => $product->id,
                    'warehouse_id' => $warehouseId,
                    'unit_id' => $unitId,
                    'category_id' => $categoryId,
                    'quantity' => $qtyInt,
                    'unit_price' => $unitPrice,
                    'discount' => $discount,
                    'line_subtotal' => $lineSubtotal,
                    'line_total' => $lineTotal,
                ];

                $lineSnapshots[] = [
                    'pivot_id' => (int) $pivot->id,
                ];

                $lineTotals[] = $lineTotal;
            }

            $subtotal = round(array_sum($lineTotals), 2);
            $total = round($subtotal + $igv, 2);

            $clientId = isset($payload['client_id']) && $payload['client_id'] !== null ? (int) $payload['client_id'] : null;
            $typeClient = $payload['type_client'] ?? null;
            if ($clientId !== null) {
                $client = Client::query()->findOrFail($clientId);
                $typeClient = $typeClient ?? $client->type_client;
            }

            $payments = $payload['payments'] ?? [];
            $paidOut = 0.0;
            foreach ($payments as $pIdx => $p) {
                $amt = round((float) $p['amount'], 2);
                if ($amt <= 0) {
                    throw ValidationException::withMessages([
                        "payments.{$pIdx}.amount" => ['El monto del pago debe ser mayor a cero.'],
                    ]);
                }
                $paidOut += $amt;
            }
            $paidOut = round($paidOut, 2);

            if ($paidOut > $total + 0.0001) {
                throw ValidationException::withMessages([
                    'payments' => ['La suma de pagos no puede superar el total de la venta.'],
                ]);
            }

            $sale = new Sale;
            $sale->user_id = $user->id;
            $sale->cash_register_session_id = $cashSession?->id;
            $sale->client_id = $clientId;
            $sale->type_client = $typeClient;
            $sale->reference = $payload['reference'] ?? null;
            $sale->subtotal = $subtotal;
            $sale->igv = $igv;
            $sale->total = $total;
            $sale->state_sale = 'validated';
            $sale->paid_out = $paidOut;
            $sale->description = $payload['description'] ?? null;
            $sale->date_validation = now();
            $sale->applyPaymentState();
            $sale->save();

            $createdDetails = [];
            foreach ($prepared as $row) {
                $createdDetails[] = $sale->items()->create($row);
            }

            foreach ($createdDetails as $i => $detail) {
                $pivot = DB::table('product_warehouses')
                    ->where('id', $lineSnapshots[$i]['pivot_id'])
                    ->lockForUpdate()
                    ->first();
                if ($pivot === null) {
                    throw ValidationException::withMessages([
                        'items' => ['No se encontró el stock del producto para registrar el kardex.'],
                    ]);
                }
                $this->valuation->applySaleOutbound($pivot, $detail, (int) $user->id);
            }

            foreach ($payments as $p) {
                $sale->payments()->create([
                    'method_payment' => (string) $p['method_payment'],
                    'amount' => round((float) $p['amount'], 2),
                    'n_transaction' => isset($p['n_transaction']) ? (string) $p['n_transaction'] : null,
                ]);
            }

            $this->applyCreditChargesFromSalePayments($user, $sale, $payments);

            if ($cashSession !== null) {
                $sale->load('payments');
                $this->cashMovementRecorder->recordPaymentsForSale($sale, $cashSession);
            }

            return $sale->fresh()->load([
                'user:id,name',
                'client:id,full_name,n_document',
                'items.product:id,name,sku',
                'items.warehouse:id,name',
                'payments',
            ]);
        });
    }

    /**
     * Registra un pago adicional (caja se integrará después).
     */
    public function addPayment(User $user, Sale $sale, array $data): Sale
    {
        return DB::transaction(function () use ($user, $sale, $data): Sale {
            if ($sale->state_sale === 'cancelled') {
                throw ValidationException::withMessages([
                    'sale' => ['No se pueden registrar pagos en una venta anulada.'],
                ]);
            }

            $amount = round((float) $data['amount'], 2);
            if ($amount <= 0) {
                throw ValidationException::withMessages([
                    'amount' => ['El monto debe ser mayor a cero.'],
                ]);
            }

            $remaining = round((float) $sale->debt, 2);
            if ($amount > $remaining + 0.0001) {
                throw ValidationException::withMessages([
                    'amount' => ['El monto supera la deuda pendiente ('.number_format($remaining, 2, ',', '.').' Bs.).'],
                ]);
            }

            $paymentRow = $sale->payments()->create([
                'method_payment' => (string) $data['method_payment'],
                'amount' => $amount,
                'n_transaction' => isset($data['n_transaction']) ? (string) $data['n_transaction'] : null,
            ]);

            if ($sale->cash_register_session_id) {
                $sess = CashRegisterSession::query()->find((int) $sale->cash_register_session_id);
                if ($sess && $sess->status === 'open') {
                    $this->cashMovementRecorder->recordPaymentLine($sale, $paymentRow, $sess);
                }
            }

            if ($this->isCreditoPaymentMethod((string) $data['method_payment'])) {
                if ($sale->client_id === null) {
                    throw ValidationException::withMessages([
                        'method_payment' => ['El crédito en cuenta requiere cliente en la venta.'],
                    ]);
                }
                $client = Client::query()->lockForUpdate()->findOrFail((int) $sale->client_id);
                $this->creditService->charge(
                    $client,
                    (string) $amount,
                    'Abono venta #'.$sale->id,
                    'sale',
                    $sale->id,
                    $user,
                );
            }

            $sale->paid_out = round((float) $sale->paid_out + $amount, 2);
            $sale->applyPaymentState();
            $sale->save();

            return $sale->fresh()->load([
                'user:id,name',
                'client:id,full_name,n_document',
                'items.product:id,name,sku',
                'items.warehouse:id,name',
                'payments',
            ]);
        });
    }

    /**
     * Anula una venta validada: devuelve stock, elimina movimientos de caja del turno abierto
     * y revierte cargos de cuenta corriente (`credito`) del cliente.
     *
     * No permite anular si el turno de caja asociado ya está cerrado (integridad del arqueo).
     *
     * @throws ValidationException
     */
    public function cancelValidatedSale(User $user, Sale $sale): Sale
    {
        return DB::transaction(function () use ($user, $sale): Sale {
            /** @var Sale $sale */
            $sale = Sale::query()->lockForUpdate()->findOrFail($sale->id);

            if ($sale->state_sale === 'cancelled') {
                throw ValidationException::withMessages([
                    'sale' => ['La venta ya está anulada.'],
                ]);
            }

            if ($sale->state_sale !== 'validated') {
                throw ValidationException::withMessages([
                    'sale' => ['Solo se pueden anular ventas validadas.'],
                ]);
            }

            if ($sale->cash_register_session_id) {
                $sess = CashRegisterSession::query()->find((int) $sale->cash_register_session_id);
                if ($sess !== null && $sess->status === 'closed') {
                    throw ValidationException::withMessages([
                        'sale' => ['No se puede anular: el turno de caja ya está cerrado. Pedí asistencia a administración.'],
                    ]);
                }
            }

            $sale->load(['items', 'payments']);

            foreach ($sale->items as $line) {
                $qtyInt = (int) round((float) $line->quantity);
                if ($qtyInt <= 0) {
                    continue;
                }

                $pivot = DB::table('product_warehouses')
                    ->where('product_id', (int) $line->product_id)
                    ->where('warehouse_id', (int) $line->warehouse_id)
                    ->lockForUpdate()
                    ->first();

                if ($pivot === null) {
                    throw ValidationException::withMessages([
                        'sale' => ['No se encontró stock para revertir (producto #'.$line->product_id.', almacén #'.$line->warehouse_id.').'],
                    ]);
                }

                DB::table('product_warehouses')
                    ->where('id', $pivot->id)
                    ->update([
                        'stock' => DB::raw('stock + '.$qtyInt),
                        'updated_at' => now(),
                    ]);
            }

            $paymentIds = $sale->payments->pluck('id')->filter()->all();
            if ($paymentIds !== []) {
                CashMovement::query()->whereIn('sale_payment_id', $paymentIds)->delete();
            }

            if ($sale->client_id !== null) {
                $client = Client::query()->lockForUpdate()->findOrFail((int) $sale->client_id);
                $this->creditService->reverseAccruedChargesForSaleReference($client, $sale->id, $user);
            }

            $sale->state_sale = 'cancelled';
            $sale->save();

            return $sale->fresh()->load([
                'user:id,name',
                'client:id,full_name,n_document',
                'items.product:id,name,sku',
                'items.warehouse:id,name',
                'payments',
            ]);
        });
    }

    private function assertWarehouseScope(User $user, int $warehouseId, int $itemIndex): void
    {
        if ($user->hasRole('admin')) {
            return;
        }

        $bid = $user->branch_id;
        if ($bid === null) {
            throw ValidationException::withMessages([
                'items' => ['Tu usuario no tiene sucursal asignada.'],
            ]);
        }

        $w = Warehouse::query()->find($warehouseId);
        if (! $w || (int) $w->branch_id !== (int) $bid) {
            throw ValidationException::withMessages([
                "items.{$itemIndex}.warehouse_id" => ['No podés vender desde un almacén de otra sucursal.'],
            ]);
        }
    }

    /**
     * @param  list<array{method_payment: string, amount: float|string, n_transaction?: string|null}>  $payments
     */
    private function applyCreditChargesFromSalePayments(User $user, Sale $sale, array $payments): void
    {
        if ($sale->client_id === null) {
            foreach ($payments as $idx => $p) {
                if ($this->isCreditoPaymentMethod((string) ($p['method_payment'] ?? ''))) {
                    throw ValidationException::withMessages([
                        "payments.{$idx}.method_payment" => ['Ventas a crédito en cuenta requieren cliente seleccionado.'],
                    ]);
                }
            }

            return;
        }

        $client = Client::query()->lockForUpdate()->findOrFail((int) $sale->client_id);

        foreach ($payments as $idx => $p) {
            if (! $this->isCreditoPaymentMethod((string) ($p['method_payment'] ?? ''))) {
                continue;
            }

            $amt = round((float) ($p['amount'] ?? 0), 2);
            if ($amt <= 0) {
                continue;
            }

            $this->creditService->charge(
                $client,
                (string) $amt,
                'Venta #'.$sale->id,
                'sale',
                $sale->id,
                $user,
            );
            $client->refresh();
        }
    }

    private function isCreditoPaymentMethod(string $method): bool
    {
        return strtolower(trim($method)) === 'credito';
    }
}
