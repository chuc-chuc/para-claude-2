<?php

namespace App\inventarioApi\Helpers;

use Exception;
use PDO;

/**
 * AjusteExistenciaHelper
 *
 * Corrección manual de cantidad y/o precio de un lote YA existente en
 * bodega (normal, expiración o correlativo), para cuadrar el inventario
 * físico contra el sistema (conteos, mercancía dañada no reportada,
 * precio mal capturado en la compra, etc.).
 *
 * Reglas de negocio:
 *   - Solo el Administrador de Bodegas puede ejecutar ajustes (validado
 *     por el llamador con RolCompraHelper::esAdministradorBodegas()).
 *   - No se puede ajustar un lote de un período ya cerrado (se valida
 *     igual que una reversa, con CierreHelper::esPosteriorAlUltimoCierre()).
 *   - Para lotes tipo Correlativo NO se permite ajustar la cantidad, solo
 *     el precio: la "cantidad" ahí es un rango de folios/documentos
 *     numerados (correlativo_inicial..correlativo_final) y estirarlo o
 *     encogerlo es una decisión distinta (qué números concretos se
 *     agregan o quitan), no un simple conteo. Si se necesita corregir el
 *     rango de un correlativo, es una operación aparte.
 *   - Todo ajuste de cantidad queda registrado en movimientos_stock (tipo
 *     "Ajuste positivo de inventario" o "Ajuste negativo de inventario",
 *     resueltos por nombre desde el catálogo) y, además, en la tabla de
 *     auditoría bodega_inventario.ajustes_existencia (con motivo, valores
 *     antes/después y usuario) — un ajuste de solo precio también genera
 *     su fila ahí, aunque no genere movimiento de stock.
 *
 * `ajustar()` debe ejecutarse dentro de una transacción abierta por la
 * clase principal. Este helper nunca hace commit ni rollback.
 *
 * Dependencias: MovimientoHelper, StockHelper, CierreHelper.
 */
class AjusteExistenciaHelper
{
    /**
     * campo_creacion = cuándo entró el lote al sistema (NO cuándo vence).
     * Es el campo correcto tanto para el bloqueo por cierre mensual como
     * para "fecha_creacion" en el listado — fecha_expiracion es un dato
     * de negocio aparte (cuándo vence la mercancía), no de cuándo se creó
     * el registro.
     */
    private const TABLAS_LOTE = [
        'normal'      => ['tabla' => 'lotes_normal',      'campo_creacion' => 'fecha_ingreso'],
        'expiracion'  => ['tabla' => 'lotes_expiracion',   'campo_creacion' => 'created_at'],
        'correlativo' => ['tabla' => 'lotes_correlativo',  'campo_creacion' => 'created_at'],
    ];

    private const NOMBRE_TIPO_POSITIVO = 'Ajuste positivo de inventario';
    private const NOMBRE_TIPO_NEGATIVO = 'Ajuste negativo de inventario';

    private PDO              $connect;
    private string           $idUsuario;
    private MovimientoHelper $movimientoHelper;
    private StockHelper      $stockHelper;
    private CierreHelper     $cierreHelper;

    /** Cache en memoria de id de tipos_movimiento resueltos por nombre (evita relookups en el mismo request). */
    private array $cacheTipoMovimiento = [];

    public function __construct(
        PDO $connect,
        string $idUsuario,
        MovimientoHelper $movimientoHelper,
        StockHelper $stockHelper,
        CierreHelper $cierreHelper
    ) {
        $this->connect          = $connect;
        $this->idUsuario        = $idUsuario;
        $this->movimientoHelper = $movimientoHelper;
        $this->stockHelper      = $stockHelper;
        $this->cierreHelper     = $cierreHelper;
    }

    // =========================================================================
    // CONSULTA — lote a ajustar (para que el front muestre los valores actuales)
    // =========================================================================

    /**
     * Devuelve el lote con sus valores actuales, o null si no existe.
     *
     * @param string $tipoLote  'normal' | 'expiracion' | 'correlativo'
     */
    public function obtenerLote(string $tipoLote, int $idLote): ?array
    {
        $info = $this->_infoTabla($tipoLote);

        $stmt = $this->connect->prepare(
            "SELECT *
             FROM   bodega_inventario.{$info['tabla']}
             WHERE  id = ?
             LIMIT  1"
        );
        $stmt->execute([$idLote]);
        $lote = $stmt->fetch(PDO::FETCH_ASSOC);

        return $lote ?: null;
    }

    /**
     * Lista los lotes de un producto que SÍ se pueden ajustar: solo los
     * ingresados después del último cierre mensual (los anteriores ya están
     * bloqueados por ajustar() de todas formas, así que ni se muestran), y
     * con la información que un Administrador de Bodegas necesita para
     * decidir el ajuste sin adivinar: fecha de creación, quién lo ingresó
     * (id_usuario_encargado, con su nombre) y de qué alta proviene.
     *
     * @param string $tipoLote  'normal' | 'expiracion' | 'correlativo'
     */
    public function listarLotesAjustables(
        int $idBodega, int $idProducto, int $idUnidad, string $tipoLote
    ): array {
        $info = $this->_infoTabla($tipoLote);
        $ultimoCierre = $this->cierreHelper->obtenerUltimoCierre();
        $campoCreacion = $info['campo_creacion'];

        $joinUsuario = "LEFT JOIN dbintranet.usuarios du ON du.idUsuarios = t.id_usuario_encargado
                         LEFT JOIN dbintranet.datospersonales dp ON dp.idDatosPersonales = du.idDatosPersonales";

        // Nota: lotes_normal NO tiene columna cantidad_reservada (a
        // diferencia de expiracion/correlativo) — por eso va en un SELECT
        // aparte y no en una condición común a los 3 tipos.
        if ($tipoLote === 'correlativo') {
            $sql = "SELECT t.id, t.serie, t.resolucion, t.fecha_resolucion,
                           t.correlativo_inicial, t.correlativo_final, t.correlativo_siguiente,
                           t.cantidad_disponible, t.cantidad_reservada, t.precio_unitario,
                           t.{$campoCreacion} AS fecha_creacion, t.id_alta,
                           COALESCE(dp.nombres, t.id_usuario_encargado) AS creado_por
                    FROM   bodega_inventario.lotes_correlativo t
                    {$joinUsuario}
                    WHERE  t.id_bodega = ? AND t.id_producto = ? AND t.cantidad_disponible > 0";
            $params = [$idBodega, $idProducto];
        } elseif ($tipoLote === 'expiracion') {
            $sql = "SELECT t.id, t.cantidad_disponible, t.cantidad_reservada, t.precio_unitario,
                           t.fecha_expiracion, t.{$campoCreacion} AS fecha_creacion, t.id_alta,
                           DATEDIFF(t.fecha_expiracion, CURDATE()) AS dias_restantes,
                           COALESCE(dp.nombres, t.id_usuario_encargado) AS creado_por
                    FROM   bodega_inventario.lotes_expiracion t
                    {$joinUsuario}
                    WHERE  t.id_bodega = ? AND t.id_producto = ? AND t.id_unidad = ? AND t.cantidad_disponible > 0";
            $params = [$idBodega, $idProducto, $idUnidad];
        } else {
            $sql = "SELECT t.id, t.cantidad_disponible, t.precio_unitario,
                           t.{$campoCreacion} AS fecha_ingreso, t.{$campoCreacion} AS fecha_creacion, t.id_alta,
                           COALESCE(dp.nombres, t.id_usuario_encargado) AS creado_por
                    FROM   bodega_inventario.lotes_normal t
                    {$joinUsuario}
                    WHERE  t.id_bodega = ? AND t.id_producto = ? AND t.id_unidad = ? AND t.cantidad_disponible > 0";
            $params = [$idBodega, $idProducto, $idUnidad];
        }

        if ($ultimoCierre !== null) {
            $sql .= " AND t.{$campoCreacion} > ?";
            $params[] = $ultimoCierre;
        }
        $sql .= " ORDER BY t.{$campoCreacion} ASC";

        $stmt = $this->connect->prepare($sql);
        $stmt->execute($params);
        $lotes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'tipo'          => $tipoLote,
            'lotes'         => $lotes,
            'ultimo_cierre' => $ultimoCierre,
        ];
    }

    // =========================================================================
    // AJUSTE PRINCIPAL
    // =========================================================================

    /**
     * @param array $datos {
     *   tipo_lote: string       'normal' | 'expiracion' | 'correlativo'
     *   id_lote: int
     *   nueva_cantidad: ?float  null = no tocar la cantidad
     *   nuevo_precio: ?float    null = no tocar el precio; '' o -1 no son válidos, debe ser >= 0
     *   motivo: string          obligatorio, >= 10 caracteres
     * }
     * @return array  Resumen del ajuste aplicado
     * @throws Exception  Si el lote no existe, el período está cerrado, o los datos son inválidos
     */
    public function ajustar(array $datos): array
    {
        $tipoLote      = $datos['tipo_lote'] ?? '';
        $idLote        = (int)($datos['id_lote'] ?? 0);
        $nuevaCantidad = array_key_exists('nueva_cantidad', $datos) && $datos['nueva_cantidad'] !== null
            ? (float)$datos['nueva_cantidad'] : null;
        $nuevoPrecio   = array_key_exists('nuevo_precio', $datos) && $datos['nuevo_precio'] !== null
            ? (float)$datos['nuevo_precio'] : null;
        $motivo        = trim($datos['motivo'] ?? '');

        $info = $this->_infoTabla($tipoLote);

        if ($idLote < 1) {
            throw new Exception('Debe indicar el lote a ajustar');
        }
        if (mb_strlen($motivo) < 10) {
            throw new Exception('El motivo del ajuste es obligatorio y debe ser suficientemente descriptivo (mínimo 10 caracteres)');
        }
        if ($nuevaCantidad === null && $nuevoPrecio === null) {
            throw new Exception('Debe indicar una nueva cantidad, un nuevo precio, o ambos');
        }
        if ($nuevaCantidad !== null && $nuevaCantidad < 0) {
            throw new Exception('La nueva cantidad no puede ser negativa');
        }
        if ($nuevoPrecio !== null && $nuevoPrecio < 0) {
            throw new Exception('El nuevo precio no puede ser negativo');
        }
        if ($tipoLote === 'correlativo' && $nuevaCantidad !== null) {
            throw new Exception(
                'No se puede ajustar la cantidad de un lote Correlativo desde aquí: su cantidad depende ' .
                'del rango de folios (correlativo_inicial/final). Solo se permite corregir su precio.'
            );
        }

        // 1. Bloqueo pesimista del lote + validación de existencia
        $stmt = $this->connect->prepare(
            "SELECT *
             FROM   bodega_inventario.{$info['tabla']}
             WHERE  id = ?
             FOR UPDATE"
        );
        $stmt->execute([$idLote]);
        $lote = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$lote) {
            throw new Exception('El lote indicado no existe');
        }

        // 2. Bloqueo por cierre mensual — misma regla que las reversas
        //    (se compara contra la fecha de CREACIÓN del lote, no contra
        //    su fecha de expiración)
        $fechaLote = $lote[$info['campo_creacion']] ?? $lote['created_at'] ?? null;
        if ($fechaLote !== null && !$this->cierreHelper->esPosteriorAlUltimoCierre((string)$fechaLote)) {
            $ultimoCierre = $this->cierreHelper->obtenerUltimoCierre();
            throw new Exception("Restricción contable: este lote pertenece a un periodo bloqueado por el último cierre mensual ({$ultimoCierre})");
        }

        $idBodega   = (int)$lote['id_bodega'];
        $idProducto = (int)$lote['id_producto'];
        $idUnidad   = isset($lote['id_unidad']) ? (int)$lote['id_unidad'] : null;

        $cantidadAnterior = (float)$lote['cantidad_disponible'];
        $precioAnterior   = $lote['precio_unitario'] !== null ? (float)$lote['precio_unitario'] : null;

        $cantidadNueva = $nuevaCantidad ?? $cantidadAnterior;
        $precioNuevo   = $nuevoPrecio   ?? $precioAnterior;

        $deltaCantidad = round($cantidadNueva - $cantidadAnterior, 4);

        // 3. Mutar el lote (cantidad y/o precio)
        $this->connect->prepare(
            "UPDATE bodega_inventario.{$info['tabla']}
             SET    cantidad_disponible = ?,
                    precio_unitario     = ?
             WHERE  id = ?"
        )->execute([$cantidadNueva, $precioNuevo, $idLote]);

        // 4. Reflejar el delta de cantidad en el resumen de stock + Kardex
        $idMovimiento = null;
        if (abs($deltaCantidad) > 0.0001 && $idUnidad !== null) {
            $this->stockHelper->ajustarCantidadTotal($idBodega, $idProducto, $idUnidad, $deltaCantidad);

            $nombreTipo = $deltaCantidad > 0 ? self::NOMBRE_TIPO_POSITIVO : self::NOMBRE_TIPO_NEGATIVO;
            $idTipoMovimiento = $this->_resolverIdTipoMovimiento($nombreTipo);

            $this->movimientoHelper->registrar(
                $idTipoMovimiento,
                $idBodega, $idProducto, $idUnidad,
                abs($deltaCantidad),
                $info['tabla'], $idLote,
                null, null, null,
                $precioNuevo
            );

            $idMovimiento = (int)$this->connect->lastInsertId();
        }

        // 5. Auditoría — siempre queda constancia, haya o no movimiento de cantidad
        $this->connect->prepare(
            "INSERT INTO bodega_inventario.ajustes_existencia
                 (id_bodega, id_producto, id_unidad, tipo_lote, id_lote,
                  cantidad_anterior, cantidad_nueva, precio_anterior, precio_nuevo,
                  id_movimiento, motivo, id_usuario, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)"
        )->execute([
            $idBodega, $idProducto, $idUnidad, $tipoLote, $idLote,
            $cantidadAnterior, $cantidadNueva, $precioAnterior, $precioNuevo,
            $idMovimiento, $motivo, $this->idUsuario,
        ]);

        return [
            'id_lote'            => $idLote,
            'tipo_lote'          => $tipoLote,
            'cantidad_anterior'  => $cantidadAnterior,
            'cantidad_nueva'     => $cantidadNueva,
            'precio_anterior'    => $precioAnterior,
            'precio_nuevo'       => $precioNuevo,
            'id_movimiento'      => $idMovimiento,
        ];
    }

    // =========================================================================
    // RESOLUCIÓN DE CATÁLOGO
    // =========================================================================

    /**
     * Resuelve (y cachea en memoria) el id de tipos_movimiento por nombre.
     * Ver inventarioApi/Helpers/AjusteExistenciaHelper.php — requiere que
     * existan las filas 'Ajuste positivo de inventario' y
     * 'Ajuste negativo de inventario' en bodega_inventario.tipos_movimiento.
     */
    private function _resolverIdTipoMovimiento(string $nombre): int
    {
        if (isset($this->cacheTipoMovimiento[$nombre])) {
            return $this->cacheTipoMovimiento[$nombre];
        }

        $stmt = $this->connect->prepare(
            "SELECT id FROM bodega_inventario.tipos_movimiento WHERE nombre = ? LIMIT 1"
        );
        $stmt->execute([$nombre]);
        $id = $stmt->fetchColumn();

        if ($id === false) {
            throw new Exception("No existe en el catálogo tipos_movimiento el registro '{$nombre}'. Debe crearse antes de usar el módulo de ajustes.");
        }

        return $this->cacheTipoMovimiento[$nombre] = (int)$id;
    }

    private function _infoTabla(string $tipoLote): array
    {
        if (!isset(self::TABLAS_LOTE[$tipoLote])) {
            throw new Exception("Tipo de lote inválido: {$tipoLote}");
        }

        return self::TABLAS_LOTE[$tipoLote];
    }
}
