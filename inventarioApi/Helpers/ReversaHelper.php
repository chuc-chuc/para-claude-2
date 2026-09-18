<?php

namespace App\inventarioApi\Helpers;

use PDO;
use RuntimeException;

/**
 * ReversaHelper
 *
 * Lógica interna de reversa TOTAL de entregas (no parciales).
 * Devuelve la mercancía entregada a sus lotes de origen y asienta el
 * movimiento tipo 3 (Alta por reversa) por cada lote restaurado.
 *
 * Estrategia por tipo de producto:
 *   - Correlativo (tipo 1): restaura sobre los lotes ORIGINALES usando el
 *     rango guardado por lote en solicitudes_detalle_lotes (sin límite de
 *     cuántos lotes cruzó la entrega). Solo es válido si cada rango es
 *     contiguo con el puntero correlativo_siguiente de su propio lote; si
 *     hay correlativos posteriores emitidos del mismo lote (hueco no
 *     representable), se rechaza. Entregas registradas antes de esta
 *     corrección (sin el rango guardado por lote) caen a un método legado
 *     limitado a 2 lotes, leyendo las columnas resumen de solicitudes_detalle.
 *   - Expiración (tipo 2) y Normal (tipo 3): lee solicitudes_detalle_lotes y
 *     devuelve cada cantidad a su lote exacto (preserva PEPS/FIFO y fechas).
 *
 * Todos los métodos deben ejecutarse dentro de una transacción abierta por la
 * clase principal. Este helper nunca hace commit ni rollback.
 *
 * Dependencias: MovimientoHelper.
 */
class ReversaHelper
{
    private PDO              $connect;
    private string           $idUsuario;
    private MovimientoHelper $movimientoHelper;

    /** Unidad fija usada por los movimientos de productos correlativos. */
    private const UNIDAD_CORRELATIVO = 1;

    public function __construct(
        PDO $connect,
        string $idUsuario,
        MovimientoHelper $movimientoHelper
    ) {
        $this->connect          = $connect;
        $this->idUsuario        = $idUsuario;
        $this->movimientoHelper = $movimientoHelper;
    }

    // =========================================================================
    // CORRELATIVO (tipo 1)
    // =========================================================================

    /**
     * Revierte una entrega de producto correlativo restaurando sobre el/los
     * lote(s) original(es). Lee la trazabilidad completa de
     * solicitudes_detalle_lotes — sin límite de lotes cruzados.
     *
     * Compatibilidad: entregas registradas ANTES de esta corrección no
     * guardaron el rango por lote en solicitudes_detalle_lotes (solo la
     * cantidad) — para esas, cae al método legado que lee las columnas
     * resumen de solicitudes_detalle (válido porque ese formato antiguo
     * nunca pudo representar más de 2 lotes de todas formas).
     *
     * @param object $detalle  Fila de solicitudes_detalle (con rangos y lotes, para el camino legado)
     * @param int    $idBodega
     * @param int    $idProducto
     * @param int    $idDetalle
     * @return float Total de correlativos restaurados
     *
     * @throws RuntimeException si algún rango no es contiguo con el puntero
     *                          (hay correlativos posteriores emitidos = hueco)
     */
    public function revertirEntregaCorrelativo(
        object $detalle, int $idBodega, int $idProducto, int $idDetalle
    ): float {
        $stmt = $this->connect->prepare(
            "SELECT id_lote_corr, correlativo_inicial, correlativo_final
             FROM   bodega_inventario.solicitudes_detalle_lotes
             WHERE  id_solicitud_det = ? AND id_lote_corr IS NOT NULL"
        );
        $stmt->execute([$idDetalle]);
        $filas = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $esFormatoNuevo = !empty($filas) && count(array_filter(
                $filas, static fn ($f) => $f['correlativo_inicial'] === null
            )) === 0;

        if (!$esFormatoNuevo) {
            return $this->_revertirEntregaCorrelativoLegado($detalle, $idBodega, $idProducto, $idDetalle);
        }

        $total = 0.0;
        foreach ($filas as $f) {
            $total += $this->_restaurarRangoCorrelativo(
                (int)$f['id_lote_corr'],
                (int)$f['correlativo_inicial'],
                (int)$f['correlativo_final'],
                $idBodega, $idProducto, $idDetalle
            );
        }

        return $total;
    }

    /**
     * Camino legado (entregas anteriores a la corrección del tope de 2 lotes):
     * lee el par principal y, si existe, el par secundario (_2) directo de
     * las columnas resumen de solicitudes_detalle. Solo cubre hasta 2 lotes
     * — es exactamente lo que ese formato antiguo alcanzó a guardar.
     */
    private function _revertirEntregaCorrelativoLegado(
        object $detalle, int $idBodega, int $idProducto, int $idDetalle
    ): float {
        $total = 0.0;

        $total += $this->_restaurarRangoCorrelativo(
            (int)$detalle->id_lote_correlativo,
            (int)$detalle->correlativo_inicial_asignado,
            (int)$detalle->correlativo_final_asignado,
            $idBodega, $idProducto, $idDetalle
        );

        if ($detalle->id_lote_correlativo_2 !== null) {
            $total += $this->_restaurarRangoCorrelativo(
                (int)$detalle->id_lote_correlativo_2,
                (int)$detalle->correlativo_inicial_asignado_2,
                (int)$detalle->correlativo_final_asignado_2,
                $idBodega, $idProducto, $idDetalle
            );
        }

        return $total;
    }

    /**
     * Restaura un único rango [ini..fin] sobre su lote correlativo.
     * Solo es válido si fin + 1 == correlativo_siguiente (rango contiguo,
     * sin correlativos emitidos después en ese lote).
     */
    private function _restaurarRangoCorrelativo(
        int $idLote, int $ini, int $fin,
        int $idBodega, int $idProducto, int $idDetalle
    ): float {
        $n = $fin - $ini + 1;

        // Bloquear el lote y leer su puntero actual
        $stmt = $this->connect->prepare(
            "SELECT correlativo_siguiente
             FROM   bodega_inventario.lotes_correlativo
             WHERE  id = ?
             FOR UPDATE"
        );
        $stmt->execute([$idLote]);
        $lote = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$lote) {
            throw new RuntimeException(
                "No se puede revertir: el lote correlativo {$idLote} ya no existe"
            );
        }

        // Validar contigüidad con el puntero (caso normal sin hueco)
        if (($fin + 1) !== (int)$lote['correlativo_siguiente']) {
            throw new RuntimeException(
                "No se puede revertir: hay correlativos posteriores emitidos del lote {$idLote}. " .
                "Revierta primero las entregas más recientes de ese lote."
            );
        }

        // Devolver el rango: sube disponible y retrocede el puntero
        $this->connect->prepare(
            "UPDATE bodega_inventario.lotes_correlativo
             SET    cantidad_disponible   = cantidad_disponible   + ?,
                    correlativo_siguiente = correlativo_siguiente - ?
             WHERE  id = ?"
        )->execute([$n, $n, $idLote]);

        // Movimiento tipo 3 (Alta por reversa) con el rango restaurado
        $this->movimientoHelper->registrarReversaEntrega(
            $idBodega, $idProducto, self::UNIDAD_CORRELATIVO,
            (float)$n, $idDetalle, $ini, $fin
        );

        return (float)$n;
    }

    // =========================================================================
    // EXPIRACIÓN (tipo 2) Y NORMAL (tipo 3)
    // =========================================================================

    /**
     * Revierte una entrega de producto con expiración o normal devolviendo
     * cada cantidad a su lote exacto según solicitudes_detalle_lotes.
     *
     * @return float Total restaurado (suma de las cantidades de los lotes)
     */
    public function revertirEntregaPorLotes(
        int $idDetalle, int $idBodega, int $idProducto, int $idUnidad
    ): float {
        $stmt = $this->connect->prepare(
            "SELECT id_lote_exp, id_lote_normal, cantidad
             FROM   bodega_inventario.solicitudes_detalle_lotes
             WHERE  id_solicitud_det = ?
               AND  id_lote_corr IS NULL"
        );
        $stmt->execute([$idDetalle]);
        $filas = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $total = 0.0;

        $stmtNormal = $this->connect->prepare(
            "UPDATE bodega_inventario.lotes_normal
             SET    cantidad_disponible = cantidad_disponible + ?
             WHERE  id = ?"
        );
        $stmtExp = $this->connect->prepare(
            "UPDATE bodega_inventario.lotes_expiracion
             SET    cantidad_disponible = cantidad_disponible + ?
             WHERE  id = ?"
        );

        foreach ($filas as $f) {
            $cantidad = (float)$f['cantidad'];

            if ($f['id_lote_normal'] !== null) {
                $stmtNormal->execute([$cantidad, (int)$f['id_lote_normal']]);
            } elseif ($f['id_lote_exp'] !== null) {
                $stmtExp->execute([$cantidad, (int)$f['id_lote_exp']]);
            } else {
                continue; // fila inconsistente, se ignora
            }

            // Movimiento tipo 3 (Alta por reversa) por cada lote restaurado
            $this->movimientoHelper->registrarReversaEntrega(
                $idBodega, $idProducto, $idUnidad,
                $cantidad, $idDetalle
            );

            $total += $cantidad;
        }

        return $total;
    }
}