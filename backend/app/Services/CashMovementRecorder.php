<?php

namespace App\Services;

use App\Models\CashMovement;
use App\Models\CashRegisterSession;
use App\Models\Sale;

class CashMovementRecorder
{
    public function recordPaymentsForSale(Sale $sale, CashRegisterSession $session): void
    {
        $sale->loadMissing('payments');

        foreach ($sale->payments as $payment) {
            CashMovement::query()->firstOrCreate(
                ['sale_payment_id' => $payment->id],
                [
                    'cash_register_session_id' => $session->id,
                    'type' => 'income',
                    'source' => 'sale_payment',
                    'amount' => $payment->amount,
                    'method_payment' => $payment->method_payment,
                    'description' => 'Cobro venta #'.$sale->id,
                    'occurred_at' => $payment->created_at ?? now(),
                ]
            );
        }
    }

    public function recordPaymentLine(Sale $sale, \App\Models\SalePayment $payment, CashRegisterSession $session): void
    {
        CashMovement::query()->firstOrCreate(
            ['sale_payment_id' => $payment->id],
            [
                'cash_register_session_id' => $session->id,
                'type' => 'income',
                'source' => 'sale_payment',
                'amount' => $payment->amount,
                'method_payment' => $payment->method_payment,
                'description' => 'Abono venta #'.$sale->id,
                'occurred_at' => $payment->created_at ?? now(),
            ]
        );
    }
}
