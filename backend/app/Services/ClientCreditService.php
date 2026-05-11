<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientCreditTransaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ClientCreditService
{
    /**
     * Carga al cliente (aumenta deuda). Respeta tope si hay credit_limit.
     *
     * @throws ValidationException
     */
    public function charge(
        Client $client,
        string $amount,
        ?string $description = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?User $actor = null,
    ): ClientCreditTransaction {
        if (! $client->credit_enabled) {
            throw ValidationException::withMessages([
                'credit' => ['El cliente no tiene crédito habilitado.'],
            ]);
        }

        $delta = $this->parsePositiveAmount($amount, 'Monto');

        return $this->applyBalanceChange(
            $client,
            ClientCreditTransaction::TYPE_CHARGE,
            $delta,
            $description,
            $referenceType,
            $referenceId,
            $actor,
        );
    }

    /**
     * Abono (reduce deuda).
     *
     * @throws ValidationException
     */
    public function payment(
        Client $client,
        string $amount,
        ?string $description = null,
        ?User $actor = null,
    ): ClientCreditTransaction {
        if (! $client->credit_enabled) {
            throw ValidationException::withMessages([
                'credit' => ['El cliente no tiene crédito habilitado.'],
            ]);
        }

        $pay = $this->parsePositiveAmount($amount, 'Monto');

        return $this->applyBalanceChange(
            $client,
            ClientCreditTransaction::TYPE_PAYMENT,
            -$pay,
            $description,
            null,
            null,
            $actor,
        );
    }

    /**
     * Revierte los cargos (TYPE_CHARGE) registrados contra una venta (`reference_type` sale).
     * Usado al anular ventas: reduce la deuda del cliente en la suma de esos cargos.
     *
     * @throws ValidationException
     */
    public function reverseAccruedChargesForSaleReference(Client $client, int $saleId, ?User $actor = null): void
    {
        $sum = round((float) ClientCreditTransaction::query()
            ->where('client_id', $client->id)
            ->where('reference_type', 'sale')
            ->where('reference_id', $saleId)
            ->where('type', ClientCreditTransaction::TYPE_CHARGE)
            ->sum('amount'), 2);

        if ($sum <= 0.0001) {
            return;
        }

        DB::transaction(function () use ($client, $sum, $saleId, $actor): void {
            $locked = Client::query()->lockForUpdate()->findOrFail($client->id);

            $current = (float) $locked->credit_balance;
            $newBalance = round(max(0, $current - $sum), 2);

            $locked->credit_balance = (string) $newBalance;
            $locked->save();

            ClientCreditTransaction::query()->create([
                'client_id' => $locked->id,
                'type' => ClientCreditTransaction::TYPE_PAYMENT,
                'amount' => (string) round($sum, 2),
                'balance_after' => (string) $newBalance,
                'description' => 'Reversión por anulación venta #'.$saleId,
                'reference_type' => 'sale_void',
                'reference_id' => $saleId,
                'created_by' => $actor?->id,
            ]);
        });
    }

    /**
     * Ajuste manual: amount positivo aumenta deuda, negativo la reduce.
     *
     * @throws ValidationException
     */
    public function adjustment(
        Client $client,
        string $signedAmount,
        ?string $description = null,
        ?User $actor = null,
    ): ClientCreditTransaction {
        if (! $client->credit_enabled) {
            throw ValidationException::withMessages([
                'credit' => ['El cliente no tiene crédito habilitado.'],
            ]);
        }

        $delta = $this->parseSignedAmount($signedAmount);

        if (abs($delta) < 0.0001) {
            throw ValidationException::withMessages([
                'amount' => ['El monto del ajuste no puede ser cero.'],
            ]);
        }

        return $this->applyBalanceChange(
            $client,
            ClientCreditTransaction::TYPE_ADJUSTMENT,
            $delta,
            $description,
            null,
            null,
            $actor,
        );
    }

    private function applyBalanceChange(
        Client $client,
        string $type,
        float $delta,
        ?string $description,
        ?string $referenceType,
        ?int $referenceId,
        ?User $actor,
    ): ClientCreditTransaction {
        return DB::transaction(function () use ($client, $type, $delta, $description, $referenceType, $referenceId, $actor): ClientCreditTransaction {
            $client->refresh();

            $current = (float) $client->credit_balance;
            $newBalance = round($current + $delta, 2);

            if ($newBalance < -0.0001) {
                throw ValidationException::withMessages([
                    'amount' => ['El abono supera el saldo pendiente ('.number_format($current, 2, ',', '.').').'],
                ]);
            }
            if ($newBalance < 0) {
                $newBalance = 0;
            }

            $limit = $client->credit_limit;
            if ($client->credit_enabled && $limit !== null && $newBalance - (float) $limit > 0.0001) {
                throw ValidationException::withMessages([
                    'amount' => [
                        'Excede el límite de crédito ('.number_format((float) $limit, 2, ',', '.').' Bs.). '
                        .'Saldo actual: '.number_format($current, 2, ',', '.').'.',
                    ],
                ]);
            }

            $storedAmount = $type === ClientCreditTransaction::TYPE_PAYMENT
                ? round(abs($delta), 2)
                : ($type === ClientCreditTransaction::TYPE_ADJUSTMENT
                    ? round($delta, 2)
                    : round($delta, 2));

            $client->credit_balance = (string) $newBalance;
            $client->save();

            return ClientCreditTransaction::query()->create([
                'client_id' => $client->id,
                'type' => $type,
                'amount' => (string) $storedAmount,
                'balance_after' => (string) $newBalance,
                'description' => $description,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'created_by' => $actor?->id,
            ]);
        });
    }

    private function parsePositiveAmount(string $raw, string $label): float
    {
        $n = $this->normalizeDecimal($raw);
        if ($n === null || $n <= 0) {
            throw ValidationException::withMessages([
                'amount' => [$label.' debe ser mayor a cero.'],
            ]);
        }

        return round($n, 2);
    }

    private function parseSignedAmount(string $raw): float
    {
        $n = $this->normalizeDecimal($raw);
        if ($n === null) {
            throw ValidationException::withMessages([
                'amount' => ['Monto no válido.'],
            ]);
        }

        return round($n, 2);
    }

    private function normalizeDecimal(string $raw): ?float
    {
        $s = trim(str_replace(' ', '', $raw));
        if ($s === '') {
            return null;
        }
        if (str_contains($s, ',') && ! str_contains($s, '.')) {
            $s = str_replace(',', '.', $s);
        } else {
            $s = str_replace(',', '', $s);
        }
        if (! is_numeric($s)) {
            return null;
        }

        return (float) $s;
    }
}
