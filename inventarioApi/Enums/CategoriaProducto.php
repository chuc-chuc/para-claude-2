<?php

declare(strict_types=1);

namespace App\inventarioApi\Enums;

/**
 * IDs de bodega_inventario.categorias_producto que la lógica de negocio
 * necesita referenciar de forma fija (p. ej. reportes acotados a una sola
 * categoría). Si el id de una categoría cambia en la base de datos, basta
 * con actualizar el valor aquí; no hay que tocar el resto del código.
 */
enum CategoriaProducto: int
{
    case UNIFORMES = 8;
}