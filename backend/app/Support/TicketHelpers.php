<?php

namespace App\Support;

use App\Models\SalePayment;
use Illuminate\Support\Collection;
use NumberFormatter;

final class TicketHelpers
{
    /**
     * Monto en letras estilo comprobante BO (ej. «CIENTO CATORCE CON 00/100»).
     */
    public static function amountInWordsBolivia(float $amount): string
    {
        $amount = round(abs($amount), 2);
        $whole = (int) floor($amount + 1e-9);
        $cents = (int) round(($amount - $whole) * 100);

        if ($whole > 999999 || ! extension_loaded('intl')) {
            return 'IMPORTE: '.number_format($amount, 2, ',', '.').' BS.';
        }

        $f = new NumberFormatter('es', NumberFormatter::SPELLOUT);
        $words = mb_strtoupper(trim((string) $f->format($whole)));

        return $words.' CON '.sprintf('%02d', $cents).'/100';
    }

    /**
     * Etiqueta breve para método de pago en ticket.
     */
    public static function paymentLabel(string $method): string
    {
        $m = strtolower(trim($method));

        return match ($m) {
            'efectivo', 'cash' => 'EFECTIVO',
            'credito', 'crédito', 'credit' => 'CRÉDITO EN CUENTA',
            'transferencia', 'transfer' => 'TRANSFERENCIA',
            'tarjeta', 'card' => 'TARJETA',
            'qr', 'cheque' => mb_strtoupper($method),
            default => mb_strtoupper($method),
        };
    }

    /**
     * Resumen del tipo de venta para el ticket.
     *
     * @param  Collection<int, SalePayment>  $payments
     */
    public static function tipoPagoResumen(Collection $payments): string
    {
        if ($payments->isEmpty()) {
            return 'SIN PAGO REGISTRADO';
        }

        $methods = $payments->pluck('method_payment')->map(fn ($m) => strtolower((string) $m))->unique()->values()->all();

        if (count($methods) > 1) {
            return 'MIXTO';
        }

        $one = $methods[0] ?? '';

        return match ($one) {
            'credito', 'crédito' => 'CRÉDITO EN CUENTA',
            default => 'CONTADO',
        };
    }
}
