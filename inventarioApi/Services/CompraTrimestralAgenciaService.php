<?php

declare(strict_types=1);

namespace App\inventarioApi\Services;

use App\inventarioApi\Helpers\BodegaHelper;
use Exception;
use PDO;

/**
 * FLUJO 3B — Autoservicio trimestral para el Encargado de Agencia.
 *
 * Apartado NUEVO y adicional al FLUJO 3 (CompraTrimestralService, que
 * sigue existiendo tal cual, a cargo del Administrador de Bodegas):
 *
 *   - Acotado a la bodega de la agencia en sesión del usuario — nunca
 *     puede elegir otra bodega ni ve bodegas de Área.
 *   - No exige el rol Administrador de Bodegas: cualquier usuario cuya
 *     agencia en sesión tenga una bodega de tipo Agencia puede generar
 *     el pedido de SU agencia (BodegaHelper::obtenerBodegaAgencia(), sin
 *     tabla de encargados dedicada — igual que ya funciona hoy para
 *     otros flujos de agencia).
 *   - Misma regla de alza que el Administrador: si alguna línea pide más
 *     de lo sugerido, la compra nace en REQUIERE_AUTORIZACION en vez de
 *     APROBADA.
 *   - Es un canal ADICIONAL, no un reemplazo: el Administrador conserva
 *     su vista completa (Agencia + Área) por si el encargado no genera
 *     su pedido a tiempo. El control de "ya generado" en
 *     `trimestres_generados` es compartido entre ambos caminos — quien
 *     llegue primero bloquea al otro para esa bodega y ese trimestre,
 *     evitando duplicados.
 *
 * La lógica de cálculo (consumo - existencia, control de duplicados)
 * vive en un solo lugar: CompraTrimestralService::calcularSugerenciaBodega()
 * / generarOrdenBodega(). Este servicio solo resuelve la bodega del
 * usuario en sesión y valida el correlativo cuando el producto lo exige.
 */
final class CompraTrimestralAgenciaService
{
    public function __construct(
        private PDO $connect,
        private CompraTrimestralService $compraTrimestralService,
        private BodegaHelper $bodegaHelper,
    ) {
    }

    /** Preview: sugerencia trimestral de la agencia del usuario en sesión. */
    public function calcularSugerenciaPropia(): array
    {
        $idBodega = $this->resolverBodegaPropia();

        return $this->compraTrimestralService->calcularSugerenciaBodega($idBodega);
    }

    /**
     * Genera el pedido trimestral de la agencia del usuario en sesión.
     *
     * @param array<array{id_producto:int,id_unidad:int,cantidad:float,correlativo_inicial?:?int,correlativo_final?:?int}> $lineas
     */
    public function generarOrdenPropia(string $idUsuarioSesion, array $lineas): array
    {
        if (empty($lineas)) {
            throw new Exception('Debe indicar al menos una línea de producto para generar el pedido trimestral');
        }

        $idBodega = $this->resolverBodegaPropia();

        $this->validarCorrelativos($lineas);

        return $this->compraTrimestralService->generarOrdenBodega($idBodega, $idUsuarioSesion, $lineas);
    }

    // -----------------------------------------------------------------

    private function resolverBodegaPropia(): int
    {
        $idBodega = $this->bodegaHelper->obtenerBodegaAgencia();
        if ($idBodega === null) {
            throw new Exception('No se encontró una bodega de agencia activa asociada a su sesión');
        }

        return $idBodega;
    }

    /**
     * Si el producto es de control Correlativo (productos.id_tipo = 1),
     * exige el correlativo_inicial y calcula el correlativo_final a partir
     * de la cantidad — igual que crearLoteCorrelativo() en el resto del
     * sistema, para que el rango declarado siempre cuadre con la cantidad.
     * Productos que no son de control Correlativo pasan sin tocarse.
     *
     * @param array<array{id_producto:int,id_unidad:int,cantidad:float,correlativo_inicial?:?int,correlativo_final?:?int}> $lineas
     */
    private function validarCorrelativos(array &$lineas): void
    {
        $idsProducto = array_values(array_unique(array_filter(array_map(
            static fn ($l) => (int) ($l['id_producto'] ?? 0),
            $lineas
        ))));

        if (empty($idsProducto)) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($idsProducto), '?'));
        $stmt = $this->connect->prepare(
            "SELECT id, id_tipo, nombre FROM bodega_inventario.productos WHERE id IN ({$placeholders})"
        );
        $stmt->execute($idsProducto);

        $productosPorId = [];
        foreach ($stmt->fetchAll(PDO::FETCH_OBJ) as $p) {
            $productosPorId[(int) $p->id] = $p;
        }

        foreach ($lineas as $i => &$linea) {
            $idProducto = (int) ($linea['id_producto'] ?? 0);
            $producto   = $productosPorId[$idProducto] ?? null;

            if (!$producto || (int) $producto->id_tipo !== 1) {
                continue; // no es de control Correlativo, no aplica
            }

            $correlativoInicial = $linea['correlativo_inicial'] ?? null;
            $cantidad            = (float) ($linea['cantidad'] ?? 0);

            if ($cantidad <= 0) {
                continue; // línea en 0 = el encargado la descartó, ver generarOrdenBodega()
            }

            if (!$correlativoInicial || (int) $correlativoInicial < 1) {
                throw new Exception("El producto '{$producto->nombre}' es de control Correlativo — debe indicar el correlativo inicial");
            }
            if ($cantidad != (int) $cantidad) {
                throw new Exception("El producto '{$producto->nombre}' es de control Correlativo — la cantidad debe ser un número entero");
            }

            $linea['correlativo_inicial'] = (int) $correlativoInicial;
            $linea['correlativo_final']   = (int) $correlativoInicial + (int) $cantidad - 1;
        }
    }
}