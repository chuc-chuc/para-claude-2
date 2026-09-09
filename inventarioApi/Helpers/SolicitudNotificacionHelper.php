<?php

namespace App\inventarioApi\Helpers;

use Exception;
use PDO;

/**
 * SolicitudNotificacionHelper
 *
 * Traduce los eventos críticos del ciclo de vida de una solicitud de bodega a
 * notificaciones concretas: resuelve a quién avisar y con qué texto, para que
 * los endpoints se limiten a una línea.
 *
 * Ciclo (estados_solicitud):
 *   1 Reservada -> 2 Entregada -> 5 Revertida
 *   1 Reservada -> 3 Rechazada
 *   1 Reservada -> 4 Cancelada
 *
 * Quién recibe qué:
 *   Creada      -> encargados de la bodega (tienen que actuar)
 *   Entregada   -> solicitante
 *   Rechazada   -> solicitante
 *   Cancelada   -> la contraparte (ver notificarCancelada)
 *   Revertida   -> solicitante
 *   Entrega directa -> receptor
 *
 * Igual que NotificacionHelper, ningún método lanza excepciones: un fallo al
 * notificar no debe tumbar la operación de inventario.
 *
 * Dependencias: NotificacionHelper.
 */
class SolicitudNotificacionHelper
{
    public const ESTADO_RESERVADA = 1;
    public const ESTADO_ENTREGADA = 2;
    public const ESTADO_RECHAZADA = 3;
    public const ESTADO_CANCELADA = 4;
    public const ESTADO_REVERTIDA = 5;

    /** tipos_bodega: 1 = agencia, 2 = área */
    private const TIPO_BODEGA_AREA = 2;

    private PDO                $connect;
    private string             $idUsuario;
    private NotificacionHelper $notificacion;

    public function __construct(PDO $connect, string $idUsuario, NotificacionHelper $notificacion)
    {
        $this->connect      = $connect;
        $this->idUsuario    = $idUsuario;
        $this->notificacion = $notificacion;
    }

    // =========================================================================
    // EVENTOS
    // =========================================================================

    /** Solicitud nueva: avisa a los encargados de la bodega que deben atenderla */
    public function notificarCreada(int $idSolicitud): void
    {
        $s = $this->_solicitud($idSolicitud);
        if (!$s) return;

        $this->notificacion->enviarVarios(
            $this->_encargadosDeBodega((int)$s['id_bodega']),
            "Nueva solicitud #{$idSolicitud} en {$s['bodega']} pendiente de atender"
        );
    }

    /** Entrega despachada: avisa al solicitante */
    public function notificarEntregada(int $idSolicitud): void
    {
        $s = $this->_solicitud($idSolicitud);
        if (!$s) return;

        $this->notificacion->enviar(
            $s['id_usuario'],
            "Tu solicitud #{$idSolicitud} de {$s['bodega']} fue entregada"
        );
    }

    /** Rechazo: avisa al solicitante e incluye el motivo si lo hay */
    public function notificarRechazada(int $idSolicitud, ?string $motivo = null): void
    {
        $s = $this->_solicitud($idSolicitud);
        if (!$s) return;

        $texto = "Tu solicitud #{$idSolicitud} de {$s['bodega']} fue rechazada";
        if ($motivo !== null && trim($motivo) !== '') {
            $texto .= ': ' . trim($motivo);
        }

        $this->notificacion->enviar($s['id_usuario'], $texto);
    }

    /**
     * Cancelación: el destinatario depende de quién cancela.
     *  - Si cancela el solicitante -> avisa a los encargados (dejan de reservar stock)
     *  - Si cancela un encargado   -> avisa al solicitante (le deshicieron su pedido)
     */
    public function notificarCancelada(int $idSolicitud): void
    {
        $s = $this->_solicitud($idSolicitud);
        if (!$s) return;

        $esElSolicitante = ($s['id_usuario'] === $this->idUsuario);

        if ($esElSolicitante) {
            $this->notificacion->enviarVarios(
                $this->_encargadosDeBodega((int)$s['id_bodega']),
                "La solicitud #{$idSolicitud} de {$s['bodega']} fue cancelada por el solicitante"
            );
            return;
        }

        $this->notificacion->enviar(
            $s['id_usuario'],
            "Tu solicitud #{$idSolicitud} de {$s['bodega']} fue cancelada"
        );
    }

    /** Reversa de una entrega ya despachada: avisa al solicitante */
    public function notificarRevertida(int $idSolicitud): void
    {
        $s = $this->_solicitud($idSolicitud);
        if (!$s) return;

        $this->notificacion->enviar(
            $s['id_usuario'],
            "Se revirtió la entrega de tu solicitud #{$idSolicitud} de {$s['bodega']}"
        );
    }

    /**
     * Entrega directa: avisa al receptor que se le cargó un consumo sin que
     * él lo pidiera. En estas solicitudes `id_usuario` ya es el receptor.
     */
    public function notificarEntregaDirecta(int $idSolicitud, float $cantidad): void
    {
        $s = $this->_solicitud($idSolicitud);
        if (!$s) return;

        $cant = rtrim(rtrim(number_format($cantidad, 2, '.', ''), '0'), '.');

        $this->notificacion->enviar(
            $s['id_usuario'],
            "Se registró una entrega directa a tu nombre en {$s['bodega']} ({$cant} unidad/es)"
        );
    }

    // =========================================================================
    // PRIVADOS
    // =========================================================================

    /** Cabecera + nombre y tipo de la bodega, en una sola consulta */
    private function _solicitud(int $idSolicitud): ?array
    {
        try {
            $stmt = $this->connect->prepare(
                "SELECT s.id, s.id_usuario, s.id_bodega, s.id_estado, s.es_entrega_directa,
                        b.nombre AS bodega, b.id_tipo
                 FROM   bodega_inventario.solicitudes s
                 INNER JOIN bodega_inventario.bodegas b ON b.id = s.id_bodega
                 WHERE  s.id = ?
                 LIMIT  1"
            );
            $stmt->execute([$idSolicitud]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            return $row ?: null;
        } catch (Exception $e) {
            error_log("[SolicitudNotificacionHelper] _solicitud({$idSolicitud}): " . $e->getMessage());
            return null;
        }
    }

    /**
     * Encargados activos de una bodega.
     *
     * ⚠️ Solo existe tabla de encargados para bodegas de ÁREA
     * (inv_encargados_bodega_area). Para bodegas de agencia no hay equivalente,
     * así que devuelve vacío y nadie recibe el aviso de "solicitud creada".
     * Cuando definas cómo se identifica al encargado de agencia, agrégalo aquí
     * y todos los eventos lo toman automáticamente.
     *
     * @return string[]
     */
    private function _encargadosDeBodega(int $idBodega): array
    {
        try {
            $stmt = $this->connect->prepare(
                "SELECT e.id_usuario
                 FROM   bodega_inventario.inv_encargados_bodega_area e
                 INNER JOIN bodega_inventario.bodegas b ON b.id = e.id_bodega
                 WHERE  e.id_bodega = ? AND e.activo = 1 AND b.id_tipo = ?"
            );
            $stmt->execute([$idBodega, self::TIPO_BODEGA_AREA]);
            $encargados = $stmt->fetchAll(PDO::FETCH_COLUMN);

            if (!$encargados) {
                error_log("[SolicitudNotificacionHelper] Bodega {$idBodega} sin encargados activos; nadie fue notificado");
            }

            return $encargados ?: [];
        } catch (Exception $e) {
            error_log("[SolicitudNotificacionHelper] _encargadosDeBodega({$idBodega}): " . $e->getMessage());
            return [];
        }
    }

    /** Traslado aprobado o rechazado por el Administrador de Bodegas: avisa al encargado de la bodega ORIGEN */
    public function notificarTrasladoGestionado(int $idTraslado, bool $aprobado, ?string $comentario = null): void
    {
        $t = $this->_traslado($idTraslado);
        if (!$t) return;

        $accion = $aprobado ? 'aprobado' : 'rechazado';
        $texto  = "El traslado #{$idTraslado} ({$t['bodega_origen']} -> {$t['bodega_destino']}) fue {$accion}";
        if (!$aprobado && $comentario) {
            $texto .= ': ' . $comentario;
        }

        $this->notificacion->enviarVarios($this->_encargadosDeBodega((int)$t['id_bodega_origen']), $texto);
    }

    /** Traslado aprobado: avisa al encargado de la bodega DESTINO que ya puede confirmar recepción */
    public function notificarTrasladoListoRecepcion(int $idTraslado): void
    {
        $t = $this->_traslado($idTraslado);
        if (!$t) return;

        $this->notificacion->enviarVarios(
            $this->_encargadosDeBodega((int)$t['id_bodega_destino']),
            "El traslado #{$idTraslado} de {$t['bodega_origen']} está aprobado y listo para que confirmes la recepción en {$t['bodega_destino']}"
        );
    }

    /** Solicitud de compra aprobada o rechazada: avisa a quien la solicitó */
    public function notificarCompraGestionada(int $idCompra, bool $aprobado, ?string $comentario = null): void
    {
        $c = $this->_compra($idCompra);
        if (!$c) return;

        $accion = $aprobado ? 'aprobada' : 'rechazada';
        $texto  = "Tu solicitud de compra #{$idCompra} de {$c['bodega']} fue {$accion}";
        if (!$aprobado && $comentario) {
            $texto .= ': ' . $comentario;
        }

        $this->notificacion->enviar($c['id_usuario_solicitante'], $texto);
    }

    /** Recepción parcial de un alta: avisa a los Administradores de Bodegas */
    public function notificarRecepcionParcial(int $idAlta, float $cantidadRecibida, float $cantidadEsperada): void
    {
        $stmt = $this->connect->prepare(
            "SELECT a.id, b.nombre AS bodega
         FROM   bodega_inventario.altas a
         INNER JOIN bodega_inventario.bodegas b ON b.id = a.id_bodega
         WHERE  a.id = ? LIMIT 1"
        );
        $stmt->execute([$idAlta]);
        $a = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$a) return;

        $this->notificacion->enviarVarios(
            $this->_administradoresBodegas(),
            "Recepción parcial en el alta #{$idAlta} de {$a['bodega']}: {$cantidadRecibida} de {$cantidadEsperada} esperadas"
        );
    }

    /** Aviso al intentar cerrar el mes habiendo solicitudes pendientes: avisa a Administradores y Contabilidad */
    public function notificarCierreConPendientes(int $totalPendientes): void
    {
        $this->notificacion->enviarVarios(
            $this->_administradoresBodegas(),
            "El cierre mensual no se pudo ejecutar: hay {$totalPendientes} solicitud(es) Reservada(s) sin atender"
        );
    }

// ---- privados nuevos ----

    private function _traslado(int $idTraslado): ?array
    {
        try {
            $stmt = $this->connect->prepare(
                "SELECT t.id, t.id_bodega_origen, t.id_bodega_destino,
                    bo.nombre AS bodega_origen, bd.nombre AS bodega_destino
             FROM   bodega_inventario.traslados t
             INNER JOIN bodega_inventario.bodegas bo ON bo.id = t.id_bodega_origen
             INNER JOIN bodega_inventario.bodegas bd ON bd.id = t.id_bodega_destino
             WHERE  t.id = ? LIMIT 1"
            );
            $stmt->execute([$idTraslado]);
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Exception $e) {
            error_log("[SolicitudNotificacionHelper] _traslado({$idTraslado}): " . $e->getMessage());
            return null;
        }
    }

    private function _compra(int $idCompra): ?array
    {
        try {
            $stmt = $this->connect->prepare(
                "SELECT c.id, c.id_usuario_solicitante, b.nombre AS bodega
             FROM   bodega_inventario.compras c
             INNER JOIN bodega_inventario.bodegas b ON b.id = c.id_bodega
             WHERE  c.id = ? LIMIT 1"
            );
            $stmt->execute([$idCompra]);
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Exception $e) {
            error_log("[SolicitudNotificacionHelper] _compra({$idCompra}): " . $e->getMessage());
            return null;
        }
    }

    /** Usuarios activos con puesto de Administrador de Bodegas (dbintranet.usuarios) */
    private function _administradoresBodegas(): array
    {
        try {
            $stmt = $this->connect->prepare(
                "SELECT idUsuarios FROM dbintranet.usuarios
             WHERE idPuesto = ? AND activo = 1"
            );
            $stmt->execute([\App\inventarioApi\Helpers\RolCompraHelper::PUESTO_ADMINISTRADOR_BODEGAS]);
            return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } catch (Exception $e) {
            error_log("[SolicitudNotificacionHelper] _administradoresBodegas(): " . $e->getMessage());
            return [];
        }
    }

    /** Alerta de vencimiento notificada manualmente por el Administrador: avisa al encargado de la bodega */
    public function notificarAlertaVencimiento(int $idLote, ?string $comentario = null): void
    {
        $stmt = $this->connect->prepare(
            "SELECT le.id_bodega, b.nombre AS bodega, p.nombre AS producto,
                le.fecha_expiracion, le.cantidad_disponible,
                DATEDIFF(le.fecha_expiracion, CURDATE()) AS dias_restantes
         FROM bodega_inventario.lotes_expiracion le
         INNER JOIN bodega_inventario.bodegas b ON b.id = le.id_bodega
         INNER JOIN bodega_inventario.productos p ON p.id = le.id_producto
         WHERE le.id = ? LIMIT 1"
        );
        $stmt->execute([$idLote]);
        $l = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$l) return;

        $diasRestantes = (int)$l['dias_restantes'];
        $fraseVencimiento = $diasRestantes < 0
            ? "venció hace " . abs($diasRestantes) . " día(s)"
            : "vence en {$diasRestantes} día(s)";

        $texto = "Alerta de vencimiento: {$l['producto']} en {$l['bodega']} {$fraseVencimiento} "
            . "({$l['cantidad_disponible']} unidades disponibles)";
        if ($comentario) {
            $texto .= ' — ' . $comentario;
        }

        $this->notificacion->enviarVarios($this->_encargadosDeBodega((int)$l['id_bodega']), $texto);
    }
}