<?php

declare(strict_types=1);

namespace App\inventarioApi\Services;

use App\inventarioApi\Enums\EstadoCompra;
use App\inventarioApi\Enums\TipoBodega;
use App\inventarioApi\Enums\TipoOrigenCompra;
use App\inventarioApi\Helpers\BodegaHelper;
use App\inventarioApi\Helpers\RolCompraHelper;
use App\inventarioApi\Repositories\CompraRepository;
use Exception;
use PDO;

/**
 * FLUJO 4 — Compra extraordinaria directa, sin solicitud previa. Dos
 * caminos con reglas distintas — no es un solo flujo con una bandera:
 *
 *   - crearOrdenAgencia() — Administrador de Bodegas, para CUALQUIER
 *     bodega de Agencia. Siempre nace en REQUIERE_AUTORIZACION: pasa por
 *     Gerencia/Financiero antes de llegar a mesa de trabajo.
 *
 *   - crearOrdenArea() — Encargado de Área, únicamente para SU PROPIA
 *     bodega (resuelta por sesión, sin selector). Nace directo en
 *     APROBADA, sin autorización — es el mismo que la genera y el mismo
 *     que la va a trabajar en su propia mesa de trabajo, así que no tiene
 *     sentido pedirle permiso a nadie más.
 */
final class CompraExtraordinariaService
{
    public function __construct(
        private PDO $connect,
        private CompraRepository $repo,
        private CompraService $compraService,
        private BodegaHelper $bodegaHelper,
    ) {
    }

    // =====================================================================
    // Administrador de Bodegas — cualquier bodega de Agencia
    // =====================================================================

    /** @param array<array{id_producto:int,id_unidad:int,cantidad:float}> $lineas */
    /**
     * @param array<array{id_producto:int,id_unidad:int,cantidad:float}> $lineas
     * @return array<int> IDs de las compras creadas — una por línea, todo o nada
     */
    public function crearOrdenAgencia(int $idBodega, string $idUsuarioAdmin, ?int $idPuestoSesion, array $lineas): array
    {
        if (!RolCompraHelper::esAdministradorBodegas($idPuestoSesion)) {
            throw new Exception('Solo el Administrador de Bodegas puede crear compras extraordinarias de agencia');
        }

        $bodega = $this->repo->obtenerBodegaActiva($idBodega);
        if (!$bodega) {
            throw new Exception('La bodega destino seleccionada no existe o se encuentra inactiva');
        }
        if (TipoBodega::from((int) $bodega->id_tipo) !== TipoBodega::AGENCIA) {
            throw new Exception('Esta acción es solo para bodegas de agencia — el encargado de área genera las suyas desde su propia mesa de trabajo, sin autorización');
        }

        $this->validarLineas($lineas);

        $idsCompras = [];
        foreach ($lineas as $linea) {
            $idsCompras[] = $this->compraService->crear(
                idBodega: $idBodega,
                tipoOrigen: TipoOrigenCompra::EXTRAORDINARIA,
                estadoInicial: EstadoCompra::REQUIERE_AUTORIZACION,
                lineas: [$linea], // una compra = una línea
                idUsuarioAdmin: $idUsuarioAdmin,
                requiereAutorizacion: true,
            );
        }

        return $idsCompras;
    }

    // =====================================================================
    // Encargado de Área — únicamente su propia bodega, sin autorización
    // =====================================================================

    /**
     * @param array<array{id_producto:int,id_unidad:int,cantidad:float}> $lineas
     * @return array<int> IDs de las compras creadas — una por línea, todo o nada
     */
    public function crearOrdenArea(string $idUsuarioSesion, array $lineas): array
    {
        $idBodega = $this->bodegaHelper->obtenerBodegaDelEncargado();
        if ($idBodega === null) {
            throw new Exception('Solo el encargado asignado a una bodega de área puede generar sus propias compras extraordinarias');
        }

        $this->validarLineas($lineas);

        $idsCompras = [];
        foreach ($lineas as $linea) {
            $idsCompras[] = $this->compraService->crear(
                idBodega: $idBodega,
                tipoOrigen: TipoOrigenCompra::EXTRAORDINARIA,
                estadoInicial: EstadoCompra::APROBADA,
                lineas: [$linea],
                idUsuarioSolicitante: $idUsuarioSesion,
                idUsuarioAdmin: $idUsuarioSesion,
                requiereAutorizacion: false,
            );
        }

        return $idsCompras;
    }

    // -----------------------------------------------------------------

    private function validarLineas(array $lineas): void
    {
        if (empty($lineas)) {
            throw new Exception('Debe indicar al menos una línea de producto para la compra extraordinaria');
        }

        $stmt = $this->connect->prepare(
            'SELECT COUNT(*) FROM bodega_inventario.productos_unidades WHERE id_producto = ? AND id_unidad = ? AND activo = 1'
        );

        foreach ($lineas as $i => $linea) {
            $idProducto = (int) ($linea['id_producto'] ?? 0);
            $idUnidad   = (int) ($linea['id_unidad'] ?? 0);
            $cantidad   = (float) ($linea['cantidad'] ?? 0);

            if ($idProducto < 1 || $idUnidad < 1 || $cantidad <= 0) {
                throw new Exception('Error de consistencia: la línea #' . ($i + 1) . ' tiene campos obligatorios vacíos o una cantidad inválida');
            }

            $stmt->execute([$idProducto, $idUnidad]);
            if ((int) $stmt->fetchColumn() === 0) {
                throw new Exception('La línea #' . ($i + 1) . ' tiene una combinación de producto/unidad no válida o inactiva');
            }
        }
    }
    // =====================================================================
    // Listado, edición y eliminación — solo Administrador, solo Agencia
    // =====================================================================

    /** Lista compras extraordinarias de agencia — cada fila es ya 1 producto (1 compra = 1 línea) */
    public function listarOrdenesAgencia(?int $idPuestoSesion, int $pagina = 1, int $porPagina = 20): array
    {
        if (!RolCompraHelper::esAdministradorBodegas($idPuestoSesion)) {
            throw new Exception('Solo el Administrador de Bodegas puede consultar este listado');
        }

        $pagina    = max(1, $pagina);
        $porPagina = min(50, max(1, $porPagina));
        $offset    = ($pagina - 1) * $porPagina;

        $sqlJoins = "FROM bodega_inventario.compras c
        INNER JOIN bodega_inventario.bodegas b ON b.id = c.id_bodega
        INNER JOIN bodega_inventario.estados_compra_v2 ec ON ec.id = c.id_estado
        INNER JOIN bodega_inventario.compras_detalle d ON d.id_compra = c.id
        INNER JOIN bodega_inventario.productos p ON p.id = d.id_producto
        INNER JOIN bodega_inventario.unidades_medida u ON u.id = d.id_unidad
        WHERE c.id_tipo_origen = ? AND b.id_tipo = ?";

        $params = [TipoOrigenCompra::EXTRAORDINARIA->value, TipoBodega::AGENCIA->value];

        $stmtCount = $this->connect->prepare("SELECT COUNT(*) {$sqlJoins}");
        $stmtCount->execute($params);
        $total = (int) $stmtCount->fetchColumn();

        $stmt = $this->connect->prepare(
            "SELECT
            c.id AS id_compra,
            c.id_bodega,
            b.nombre AS bodega,
            c.id_estado,
            ec.nombre AS estado,
            c.created_at,
            d.id_producto,
            p.nombre AS producto,
            d.id_unidad,
            u.abreviatura,
            d.cantidad_solicitada AS cantidad
         {$sqlJoins}
         ORDER BY c.created_at DESC, c.id DESC
         LIMIT {$porPagina} OFFSET {$offset}"
        );
        $stmt->execute($params);
        $ordenes = $stmt->fetchAll(PDO::FETCH_OBJ);

        foreach ($ordenes as $orden) {
            $orden->puede_editar = EstadoCompra::from((int) $orden->id_estado) === EstadoCompra::REQUIERE_AUTORIZACION;
        }

        return [
            'ordenes'    => $ordenes,
            'total'      => $total,
            'pagina'     => $pagina,
            'por_pagina' => $porPagina,
            'paginas'    => (int) ceil($total / $porPagina),
        ];
    }

    /**
     * Devuelve el detalle completo (cabecera + líneas) de una compra extraordinaria
     * de agencia, validando que pertenezca a ese flujo.
     */
    public function obtenerDetalleAgencia(int $idCompra, ?int $idPuestoSesion): object
    {
        if (!RolCompraHelper::esAdministradorBodegas($idPuestoSesion)) {
            throw new Exception('Solo el Administrador de Bodegas puede consultar esta compra');
        }

        return $this->compraService->obtenerDetalle($idCompra);
    }

    /** @param array<array{id_producto:int,id_unidad:int,cantidad:float}> $lineas */
    /** @param array{id_producto:int,id_unidad:int,cantidad:float,serie?:?string,resolucion?:?string,fecha_resolucion?:?string,correlativo_inicial?:?int,correlativo_final?:?int} $linea */
    public function editarOrdenAgencia(int $idCompra, ?int $idPuestoSesion, string $idUsuarioAdmin, array $linea): void
    {
        if (!RolCompraHelper::esAdministradorBodegas($idPuestoSesion)) {
            throw new Exception('Solo el Administrador de Bodegas puede editar esta compra');
        }

        $compra = $this->repo->obtenerCompraConBloqueo($idCompra);
        if (!$compra) {
            throw new Exception('La compra especificada no existe');
        }
        if (EstadoCompra::from((int) $compra->id_estado) !== EstadoCompra::REQUIERE_AUTORIZACION) {
            throw new Exception('Solo se puede editar mientras la compra está en Requiere Autorización');
        }

        $this->validarLineas([$linea]);

        $this->repo->eliminarLineas($idCompra);
        $this->repo->agregarLineas($idCompra, [[
            'id_producto'         => $linea['id_producto'],
            'id_unidad'           => $linea['id_unidad'],
            'id_bodega_destino'   => $compra->id_bodega,
            'cantidad_solicitada' => $linea['cantidad'],
            'justificacion'       => null,
            'serie'               => $linea['serie'] ?? null,
            'resolucion'          => $linea['resolucion'] ?? null,
            'fecha_resolucion'    => $linea['fecha_resolucion'] ?? null,
            'correlativo_inicial' => $linea['correlativo_inicial'] ?? null,
            'correlativo_final'   => $linea['correlativo_final'] ?? null,
        ]]);
    }

    /** Soft-delete: pasa la compra a CANCELADA — no borra filas físicamente. */
    public function eliminarOrdenAgencia(int $idCompra, ?int $idPuestoSesion): void
    {
        if (!RolCompraHelper::esAdministradorBodegas($idPuestoSesion)) {
            throw new Exception('Solo el Administrador de Bodegas puede eliminar esta compra');
        }

        $compra = $this->repo->obtenerCompraConBloqueo($idCompra);
        if (!$compra) {
            throw new Exception('La compra especificada no existe');
        }
        if (EstadoCompra::from((int) $compra->id_estado) !== EstadoCompra::REQUIERE_AUTORIZACION) {
            throw new Exception('Solo se puede eliminar mientras la compra está en Requiere Autorización');
        }

        $this->repo->actualizarEstado($idCompra, EstadoCompra::CANCELADA);
    }
}