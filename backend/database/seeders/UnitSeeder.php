<?php

namespace Database\Seeders;

use App\Models\Unit;
use App\Models\UnitConversion;
use Illuminate\Database\Seeder;

/**
 * Demo para una tienda / boutique de ropa femenina: piezas sueltas, packs, docenas
 * de temporada, telas para confección o arreglos, y (opcional) peso en insumos.
 *
 * Regla del factor en unit_conversions:
 *   cantidad_destino = cantidad_origen × factor
 *   → 1 unidad «origen» equivale a «factor» unidades «destino».
 *
 * Solo una fila por par de unidades (directa o inversa); el sistema invierte el cálculo cuando hace falta.
 */
class UnitSeeder extends Seeder
{
    public function run(): void
    {
        // ——— CANTIDAD: cómo contás blusas, vestidos, medias, packs ———

        $unidad = Unit::updateOrCreate(
            ['name' => 'Unidad'],
            [
                'description' => 'Una prenda suelta: blusa, vestido, pollera, blazer, top, etc. Es la unidad habitual de venta en mostrador y de stock por talle/color cuando lo implementes.',
                'dimension' => Unit::DIMENSION_COUNT,
                'is_active' => true,
            ],
        );

        $par = Unit::updateOrCreate(
            ['name' => 'Par'],
            [
                'description' => 'Dos piezas iguales: medias panti, calcetines, guantes finos, orejeras. 1 par = 2 unidades para inventario.',
                'dimension' => Unit::DIMENSION_COUNT,
                'is_active' => true,
            ],
        );

        $docena = Unit::updateOrCreate(
            ['name' => 'Docena'],
            [
                'description' => 'Doce piezas del mismo artículo (ej. docena de bodies, camisolas básicas). Típico en compras mayoristas a proveedor.',
                'dimension' => Unit::DIMENSION_COUNT,
                'is_active' => true,
            ],
        );

        $paquete6 = Unit::updateOrCreate(
            ['name' => 'Paquete x6'],
            [
                'description' => 'Pack cerrado de 6 piezas (ej. surtido de blusas manga corta, pack promocional de lencería básica). Al recibir compras en «paquete», el stock puede registrarse en unidades.',
                'dimension' => Unit::DIMENSION_COUNT,
                'is_active' => true,
            ],
        );

        $caja24 = Unit::updateOrCreate(
            ['name' => 'Caja x24'],
            [
                'description' => 'Caja de distribución con 24 prendas sueltas (ej. llegada de remeras dama o vestidos de temporada). Útil cuando el proveedor factura por caja.',
                'dimension' => Unit::DIMENSION_COUNT,
                'is_active' => true,
            ],
        );

        UnitConversion::updateOrCreate(
            ['from_unit_id' => $par->id, 'to_unit_id' => $unidad->id],
            ['factor' => 2],
        );

        UnitConversion::updateOrCreate(
            ['from_unit_id' => $docena->id, 'to_unit_id' => $unidad->id],
            ['factor' => 12],
        );

        UnitConversion::updateOrCreate(
            ['from_unit_id' => $paquete6->id, 'to_unit_id' => $unidad->id],
            ['factor' => 6],
        );

        UnitConversion::updateOrCreate(
            ['from_unit_id' => $caja24->id, 'to_unit_id' => $unidad->id],
            ['factor' => 24],
        );

        // ——— LONGITUD: telas (vestidos, forros, encajes) ———

        $metro = Unit::updateOrCreate(
            ['name' => 'Metro'],
            [
                'description' => 'Metro lineal de tela: seda, gasa, encaje, jersey, lino, etc. Compra típica a mercería o taller para colección o pedidos a medida.',
                'dimension' => Unit::DIMENSION_LENGTH,
                'is_active' => true,
            ],
        );

        $centimetro = Unit::updateOrCreate(
            ['name' => 'Centímetro'],
            [
                'description' => 'Corte o consumo en centímetros (refuerzos, vistas, elásticos finos).',
                'dimension' => Unit::DIMENSION_LENGTH,
                'is_active' => true,
            ],
        );

        UnitConversion::updateOrCreate(
            ['from_unit_id' => $metro->id, 'to_unit_id' => $centimetro->id],
            ['factor' => 100],
        );

        // ——— MASA: poco en prendas terminadas; útil en insumos / bisutería ———

        $kilo = Unit::updateOrCreate(
            ['name' => 'Kilogramo'],
            [
                'description' => 'Peso en kg: hilos a granel, cadena metálica para bisutería, órdenes de insumos donde el proveedor cobra por peso.',
                'dimension' => Unit::DIMENSION_MASS,
                'is_active' => true,
            ],
        );

        $gramo = Unit::updateOrCreate(
            ['name' => 'Gramo'],
            [
                'description' => 'Peso en gramos (muestras pequeñas, charms, pedrería suelta).',
                'dimension' => Unit::DIMENSION_MASS,
                'is_active' => true,
            ],
        );

        UnitConversion::updateOrCreate(
            ['from_unit_id' => $kilo->id, 'to_unit_id' => $gramo->id],
            ['factor' => 1000],
        );
    }
}
