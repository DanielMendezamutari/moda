<?php

namespace App\Support;

use App\Models\Client;
use App\Models\Sale;

/**
 * Reglas de negocio para eliminar un cliente (métricas y bloqueos).
 */
class ClientDeletionGuard
{
    /**
     * @return array{
     *     can_delete: bool,
     *     blockers: list<array{code: string, message: string}>,
     *     metrics: array{
     *         credit_balance: string,
     *         credit_enabled: bool,
     *         credit_limit: string|null,
     *         movements_count: int,
     *         linked_user: bool,
     *         sales_pending_debt_count: int,
     *     }
     * }
     */
    public static function analyze(Client $client): array
    {
        $client->loadCount('creditTransactions');

        $balance = (float) $client->credit_balance;
        $movementsCount = (int) $client->credit_transactions_count;

        $salesPendingDebtCount = Sale::query()
            ->where('client_id', $client->id)
            ->where('state_sale', '!=', 'cancelled')
            ->where('debt', '>', 0.01)
            ->count();

        $metrics = [
            'credit_balance' => (string) $client->credit_balance,
            'credit_enabled' => (bool) $client->credit_enabled,
            'credit_limit' => $client->credit_limit !== null ? (string) $client->credit_limit : null,
            'movements_count' => $movementsCount,
            'linked_user' => $client->user_id !== null,
            'sales_pending_debt_count' => $salesPendingDebtCount,
        ];

        $blockers = [];

        if (abs($balance) > 0.0001) {
            $fmt = number_format(abs($balance), 2, ',', '.');
            if ($balance > 0) {
                $blockers[] = [
                    'code' => 'non_zero_balance_debt',
                    'message' => "Saldo de crédito pendiente: {$fmt} Bs. Registrá cobranzas o ajustes hasta dejar el saldo en cero.",
                ];
            } else {
                $blockers[] = [
                    'code' => 'non_zero_balance_credit',
                    'message' => "Hay saldo a favor o irregular ({$fmt} Bs.). Corregí el saldo con un ajuste antes de eliminar.",
                ];
            }
        }

        if ($client->credit_enabled) {
            $blockers[] = [
                'code' => 'credit_still_enabled',
                'message' => 'La venta a crédito sigue habilitada. Deshabilitá el crédito en la ficha del cliente, guardá y volvé a intentar.',
            ];
        }

        if ($client->user_id !== null) {
            $blockers[] = [
                'code' => 'linked_system_user',
                'message' => 'El cliente está vinculado a un usuario del sistema. Quitá la vinculación (campo usuario) antes de eliminar.',
            ];
        }

        if ($salesPendingDebtCount > 0) {
            $blockers[] = [
                'code' => 'open_sales_debt',
                'message' => "Hay {$salesPendingDebtCount} venta(s) con saldo pendiente. Registrá los pagos o gestioná la anulación antes de eliminar el cliente.",
            ];
        }

        return [
            'can_delete' => $blockers === [],
            'blockers' => $blockers,
            'metrics' => $metrics,
        ];
    }
}
