<?php

/**
 * Datos opcionales para el PDF del ticket de venta (térmica).
 * Completá en .env lo que corresponda a tu negocio en Bolivia.
 */
return [

    /** NIT o documento que aparece como «Nº» en cabecera (ej. Nº C.I 6). */
    'documento_fiscal' => env('TICKET_DOCUMENTO_FISCAL'),

    /** Segunda línea de dirección comercial (ej. mercado, zona). */
    'direccion_linea2' => env('TICKET_DIRECCION_LINEA2'),

    /** Obligado a llevar contabilidad: SI | NO */
    'obligado_contabilidad' => env('TICKET_OBLIGADO_CONTABILIDAD', 'NO'),

    /**
     * Ambiente impreso (ej. PRODUCCIÓN). Si es null, se deduce de APP_ENV.
     */
    'ambiente' => env('TICKET_AMBIENTE'),

    /** Tipo de emisión (ej. NORMAL). */
    'tipo_emision' => env('TICKET_TIPO_EMISION', 'NORMAL'),

    /** Correo de contacto en el pie del ticket. */
    'email' => env('TICKET_EMAIL'),

    /** Teléfono de contacto en el pie del ticket. */
    'telefono' => env('TICKET_TELEFONO'),

];
