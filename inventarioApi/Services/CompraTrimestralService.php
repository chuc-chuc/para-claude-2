<?php

declare(strict_types=1);

namespace App\inventarioApi\Services;

use App\inventarioApi\Enums\EstadoCompra;
use App\inventarioApi\Enums\TipoOrigenCompra;
use App\inventarioApi\Helpers\RolCompraHelper;
use App\inventarioApi\Helpers\TrimestreConsumoHelper;
use Exception;
use PDO;

/**
 * FLUJO 3 — Sugerencia automática de compra por consumo del trimestre
 * recién cerrado, una compra por bodega.
 *
 * Dos entradas a la MISMA lógica:
 *   - Administrador (calcularSugerencias/generarOrdenes más abajo):
 *     recorre TODAS las bodegas (Agencia + Área).
 *   - Encargado de Agencia (calcularSugerenciaBodega/generarOrdenBodega,
 *     ver sección dedicada): la misma regla, acotada a UNA bodega. Es un
 *     canal adicional, no reemplaza al Administrador — ver
 *     CompraTrimestralAgenciaService para el flujo completo de acceso.
 *
 * Regla de cálculo (OJO: NO es un promedio):
 *   cantidad_sugerida = consumo TOTAL del trimestre objetivo - existencia actual
 *   Ej.: se consumieron 10 en el trimestre, hay 7 en existencia → se sugieren 3,
 *   para cubrir un volumen equivalente al del trimestre que se está reponiendo.
 *
 * Qué trimestre es el "objetivo" (de qué meses se toma el consumo) lo
 * resuelve TrimestreConsumoHelper — así esa regla vive en un solo lugar.
 *
 * Dos pasos:
 *   1. calcularSugerencias()  — solo lectura. Excluye bodegas que YA
 *      tienen una orden generada para el trimestre objetivo (ver
 *      `trimestres_generados`) — evita que, al procesar agencia por
 *      agencia en varias sesiones, se le vuelva a sugerir a alguien que
 *      ya se le generó su pedido este trimestre.
 *   2. generarOrdenes()       — recibe lo que el Administrador decidió
 *      pedir. Si alguna línea queda por ENCIMA de su cantidad sugerida,
 *      esa compra nace en REQUIERE_AUTORIZACION en vez de APROBADA. Al
 *      generar, se deja constancia en `trimestres_generados` para que esa
 *      bodega no se vuelva a ofrecer este mismo trimestre.
 */
final class CompraTrimestralService
{
    /** Ajustar si el nombre exacto en tu catálogo `tipos_movimiento` difiere. */
    private const NOMBRES_MOVIMIENTO_CONSUMO = [
        'Baja por entrega',
        'Baja por entrega directa',
    ];

    public function __construct(
        private PDO $connect,
        private CompraService $compraService,
        private TrimestreConsumoHelper $trimestreHelper,
    ) {
    }

    // =========================================================================
    // Paso 1 — preview (solo lectura)
    // =========================================================================

    /**
     * @return array{
     *   periodo: array{anio:int, trimestre:int, etiqueta:string},
     *   bodegas: array<array{id_bodega:int, bodega:string, lineas: array}>,
     *   lineas_sin_necesidad: int,
     *   bodegas_ya_generadas: array<array{id_bodega:int, bodega:string}>,
     * }
     */
    public function calcularSugerencias(?int $idPuestoSesion): array
    {
        $this->exigirAdministrador($idPuestoSesion);

        $periodo          = $this->trimestreHelper->obtenerTrimestreObjetivo();
        $idsMovimiento     = $this->resolverIdsTipoMovimiento();
        $bodegasGeneradas  = $this->obtenerBodegasYaGeneradas($periodo['anio'], $periodo['trimestre']);
        $idsBodegasExcluir = array_column($bodegasGeneradas, 'id_bodega');

        $porBodega    = [];
        $sinNecesidad = 0;

        foreach ($this->calcularSugeridaPorLinea($idsMovimiento, $periodo['inicio'], $periodo['fin']) as $fila) {
            $idBodega = (int) $fila->id_bodega;

            if (in_array($idBodega, $idsBodegasExcluir, true)) {
                continue; // ya se generó su orden de este trimestre — no se vuelve a ofrecer
            }

            $sugerida = round((float) $fila->consumo_trimestre - (float) $fila->existencia, 2);

            if ($sugerida <= 0) {
                $sinNecesidad++;
                continue;
            }

            if (!isset($porBodega[$idBodega])) {
                $porBodega[$idBodega] = [
                    'id_bodega' => $idBodega,
                    'bodega'    => $fila->nombre_bodega,
                    'lineas'    => [],
                ];
            }

            $porBodega[$idBodega]['lineas'][] = [
                'id_producto'       => (int) $fila->id_producto,
                'producto'          => $fila->producto,
                'id_tipo_producto'  => (int) $fila->id_tipo_producto,
                'id_unidad'         => (int) $fila->id_unidad,
                'unidad'            => $fila->unidad,
                'abreviatura'       => $fila->abreviatura,
                'existencia'        => round((float) $fila->existencia, 2),
                'consumo_trimestre' => round((float) $fila->consumo_trimestre, 2),
                'cantidad_sugerida' => $sugerida,
            ];
        }

        return [
            'periodo'              => ['anio' => $periodo['anio'], 'trimestre' => $periodo['trimestre'], 'etiqueta' => $periodo['etiqueta']],
            'bodegas'               => array_values($porBodega),
            'lineas_sin_necesidad' => $sinNecesidad,
            'bodegas_ya_generadas' => $bodegasGeneradas,
        ];
    }

    // =========================================================================
    // Paso 2 — generar (con lo que el Administrador decidió, tras revisar el preview)
    // =========================================================================

    /**
     * @param array<array{id_bodega:int, lineas: array<array{id_producto:int,id_unidad:int,cantidad:float}>}> $ordenes
     * @return array{
     *   periodo: array{anio:int, trimestre:int, etiqueta:string},
     *   ordenes: array<array{id_compra:int,id_bodega:int,lineas:int,requiere_autorizacion:bool}>,
     *   omitidas: array<array{id_bodega:int, motivo:string}>,
     * }
     */
    public function generarOrdenes(string $idUsuarioAdmin, ?int $idPuestoSesion, array $ordenes): array
    {
        $this->exigirAdministrador($idPuestoSesion);

        if (empty($ordenes)) {
            throw new Exception('No se recibió ninguna orden para generar');
        }

        $periodo = $this->trimestreHelper->obtenerTrimestreObjetivo();

        // Recalculamos las sugeridas frescas — nunca se decide "hay alza" con
        // un número que mandó el cliente, siempre contra lo que dice hoy la BD.
        $mapaSugeridas = $this->mapaSugeridasActual($periodo['inicio'], $periodo['fin']);

        $comprasGeneradas = [];
        $omitidas         = [];

        foreach ($ordenes as $orden) {
            $idBodega      = (int) ($orden['id_bodega'] ?? 0);
            $lineasEntrada = $orden['lineas'] ?? [];

            if ($idBodega < 1 || empty($lineasEntrada)) {
                continue;
            }

            // Defensa contra doble envío (dos pestañas, doble clic, retry de red):
            // si esta bodega YA tiene orden generada para este trimestre, se omite
            // en vez de crear un duplicado.
            if ($this->bodegaYaGenerada($idBodega, $periodo['anio'], $periodo['trimestre'])) {
                $omitidas[] = [
                    'id_bodega' => $idBodega,
                    'motivo'    => 'Ya existe una orden trimestral generada para esta bodega en ' . $periodo['etiqueta'],
                ];
                continue;
            }

            $lineas  = [];
            $hayAlza = false;

            foreach ($lineasEntrada as $l) {
                $idProducto = (int) ($l['id_producto'] ?? 0);
                $idUnidad   = (int) ($l['id_unidad'] ?? 0);
                $cantidad   = round((float) ($l['cantidad'] ?? 0), 2);

                // Cantidad en 0 = el Administrador decidió que esta línea no
                // hace falta, aunque el sistema la haya sugerido. Se omite,
                // no es un error.
                if ($idProducto < 1 || $idUnidad < 1 || $cantidad <= 0) {
                    continue;
                }

                $clave    = "{$idBodega}:{$idProducto}:{$idUnidad}";
                $sugerida = $mapaSugeridas[$clave] ?? 0.0;

                if ($cantidad > $sugerida) {
                    $hayAlza = true;
                }

                $lineas[] = [
                    'id_producto'   => $idProducto,
                    'id_unidad'     => $idUnidad,
                    'cantidad'      => $cantidad,
                    // Deja registro de contra qué se comparó, visible en las
                    // mismas tablas/modales que ya muestran "justificacion".
                    'justificacion' => "Consumo de {$periodo['etiqueta']}: {$sugerida} sugerido",
                ];
            }

            if (empty($lineas)) {
                continue;
            }

            $idCompra = $this->compraService->crear(
                idBodega: $idBodega,
                tipoOrigen: TipoOrigenCompra::TRIMESTRAL,
                estadoInicial: $hayAlza ? EstadoCompra::REQUIERE_AUTORIZACION : EstadoCompra::APROBADA,
                lineas: $lineas,
                idUsuarioAdmin: $idUsuarioAdmin,
                requiereAutorizacion: $hayAlza,
            );

            $this->registrarBodegaGenerada($idBodega, $periodo['anio'], $periodo['trimestre'], $idCompra);

            $comprasGeneradas[] = [
                'id_compra'             => $idCompra,
                'id_bodega'             => $idBodega,
                'lineas'                => count($lineas),
                'requiere_autorizacion' => $hayAlza,
            ];
        }

        if (empty($comprasGeneradas) && empty($omitidas)) {
            throw new Exception('No se generó ninguna orden: todas las líneas quedaron en cero');
        }

        return [
            'periodo'  => ['anio' => $periodo['anio'], 'trimestre' => $periodo['trimestre'], 'etiqueta' => $periodo['etiqueta']],
            'ordenes'  => $comprasGeneradas,
            'omitidas' => $omitidas,
        ];
    }

    // =========================================================================
    // Encargado de Agencia — autoservicio, acotado a SU bodega
    // =========================================================================
    //
    // Mismo cálculo y misma regla de alza que arriba (calcularSugerencias /
    // generarOrdenes), pero para una sola bodega. NO exigen rol de
    // Administrador — el llamador (CompraTrimestralAgenciaService) ya
    // resolvió y validó que la bodega es la del usuario en sesión.
    //
    // Es un canal ADICIONAL: el Administrador conserva su vista completa
    // (Agencia + Área) arriba, por si el encargado no genera a tiempo. El
    // control de "ya generado" en `trimestres_generados` es compartido —
    // quien llegue primero bloquea al otro para esa bodega y ese trimestre.

    /**
     * Preview de la sugerencia trimestral para UNA sola bodega.
     *
     * @return array{periodo: array{anio:int, trimestre:int, etiqueta:string}, lineas: array, ya_generado: bool}
     */
    public function calcularSugerenciaBodega(int $idBodega): array
    {
        $periodo       = $this->trimestreHelper->obtenerTrimestreObjetivo();
        $idsMovimiento = $this->resolverIdsTipoMovimiento();
        $yaGenerado    = $this->bodegaYaGenerada($idBodega, $periodo['anio'], $periodo['trimestre']);

        $lineas = [];
        if (!$yaGenerado) {
            foreach ($this->calcularSugeridaPorLinea($idsMovimiento, $periodo['inicio'], $periodo['fin'], $idBodega) as $fila) {
                $sugerida = round((float) $fila->consumo_trimestre - (float) $fila->existencia, 2);

                if ($sugerida <= 0) {
                    continue;
                }

                $lineas[] = [
                    'id_producto'       => (int) $fila->id_producto,
                    'producto'          => $fila->producto,
                    'id_tipo_producto'  => (int) $fila->id_tipo_producto,
                    'id_unidad'         => (int) $fila->id_unidad,
                    'unidad'            => $fila->unidad,
                    'abreviatura'       => $fila->abreviatura,
                    'existencia'        => round((float) $fila->existencia, 2),
                    'consumo_trimestre' => round((float) $fila->consumo_trimestre, 2),
                    'cantidad_sugerida' => $sugerida,
                ];
            }
        }

        return [
            'periodo'     => ['anio' => $periodo['anio'], 'trimestre' => $periodo['trimestre'], 'etiqueta' => $periodo['etiqueta']],
            'lineas'      => $lineas,
            'ya_generado' => $yaGenerado,
        ];
    }

    /**
     * Genera el pedido trimestral de UNA sola bodega — mismas reglas que
     * generarOrdenes(): recalcula las sugeridas frescas contra la BD (nunca
     * confía en lo que mandó el cliente) y, si alguna línea queda por
     * encima de lo sugerido, la compra nace en REQUIERE_AUTORIZACION.
     *
     * El correlativo (correlativo_inicial/correlativo_final) es opcional
     * aquí también — el llamador es responsable de exigirlo cuando el
     * producto sea de control Correlativo (id_tipo_producto = 1); este
     * método solo lo traslada tal cual a CompraService::crear().
     *
     * @param array<array{id_producto:int,id_unidad:int,cantidad:float,correlativo_inicial?:?int,correlativo_final?:?int}> $lineasEntrada
     * @return array{periodo: array, id_compra:int, lineas:int, requiere_autorizacion:bool}
     */
    public function generarOrdenBodega(int $idBodega, string $idUsuarioSolicitante, array $lineasEntrada): array
    {
        if (empty($lineasEntrada)) {
            throw new Exception('Debe indicar al menos una línea de producto para generar el pedido trimestral');
        }

        $periodo = $this->trimestreHelper->obtenerTrimestreObjetivo();

        if ($this->bodegaYaGenerada($idBodega, $periodo['anio'], $periodo['trimestre'])) {
            throw new Exception("Ya existe una orden trimestral generada para esta bodega en {$periodo['etiqueta']}");
        }

        // Recalculamos las sugeridas frescas — misma defensa que generarOrdenes().
        $mapaSugeridas = $this->mapaSugeridasActual($periodo['inicio'], $periodo['fin'], $idBodega);

        $lineas  = [];
        $hayAlza = false;

        foreach ($lineasEntrada as $l) {
            $idProducto = (int) ($l['id_producto'] ?? 0);
            $idUnidad   = (int) ($l['id_unidad'] ?? 0);
            $cantidad   = round((float) ($l['cantidad'] ?? 0), 2);

            // Cantidad en 0 = el encargado decidió que esta línea no hace
            // falta, aunque el sistema la haya sugerido. Se omite, no es error.
            if ($idProducto < 1 || $idUnidad < 1 || $cantidad <= 0) {
                continue;
            }

            $clave    = "{$idBodega}:{$idProducto}:{$idUnidad}";
            $sugerida = $mapaSugeridas[$clave] ?? 0.0;

            if ($cantidad > $sugerida) {
                $hayAlza = true;
            }

            $lineas[] = [
                'id_producto'         => $idProducto,
                'id_unidad'           => $idUnidad,
                'cantidad'            => $cantidad,
                'justificacion'       => "Consumo de {$periodo['etiqueta']}: {$sugerida} sugerido",
                // Solo aplica si el producto es de control Correlativo — si
                // no vienen, quedan NULL y se completan al recibir el lote.
                'correlativo_inicial' => $l['correlativo_inicial'] ?? null,
                'correlativo_final'   => $l['correlativo_final'] ?? null,
            ];
        }

        if (empty($lineas)) {
            throw new Exception('No se generó ninguna orden: todas las líneas quedaron en cero');
        }

        $idCompra = $this->compraService->crear(
            idBodega: $idBodega,
            tipoOrigen: TipoOrigenCompra::TRIMESTRAL,
            estadoInicial: $hayAlza ? EstadoCompra::REQUIERE_AUTORIZACION : EstadoCompra::APROBADA,
            lineas: $lineas,
            idUsuarioSolicitante: $idUsuarioSolicitante,
            idUsuarioAdmin: $idUsuarioSolicitante,
            requiereAutorizacion: $hayAlza,
        );

        $this->registrarBodegaGenerada($idBodega, $periodo['anio'], $periodo['trimestre'], $idCompra);

        return [
            'periodo'               => ['anio' => $periodo['anio'], 'trimestre' => $periodo['trimestre'], 'etiqueta' => $periodo['etiqueta']],
            'id_compra'             => $idCompra,
            'lineas'                => count($lineas),
            'requiere_autorizacion' => $hayAlza,
        ];
    }

    // -----------------------------------------------------------------

    private function exigirAdministrador(?int $idPuestoSesion): void
    {
        if (!RolCompraHelper::esAdministradorBodegas($idPuestoSesion)) {
            throw new Exception('Solo el Administrador de Bodegas puede generar las compras trimestrales');
        }
    }

    /** @return array<string,float> clave "idBodega:idProducto:idUnidad" => cantidad sugerida (nunca negativa) */
    private function mapaSugeridasActual(string $inicio, string $fin, ?int $idBodegaFiltro = null): array
    {
        $idsMovimiento = $this->resolverIdsTipoMovimiento();
        $mapa = [];

        foreach ($this->calcularSugeridaPorLinea($idsMovimiento, $inicio, $fin, $idBodegaFiltro) as $fila) {
            $sugerida = round((float) $fila->consumo_trimestre - (float) $fila->existencia, 2);
            $clave    = "{$fila->id_bodega}:{$fila->id_producto}:{$fila->id_unidad}";
            $mapa[$clave] = max(0.0, $sugerida);
        }

        return $mapa;
    }

    /** @return array<int> */
    private function resolverIdsTipoMovimiento(): array
    {
        $placeholders = implode(',', array_fill(0, count(self::NOMBRES_MOVIMIENTO_CONSUMO), '?'));
        $stmt = $this->connect->prepare(
            "SELECT id FROM bodega_inventario.tipos_movimiento WHERE nombre IN ({$placeholders})"
        );
        $stmt->execute(self::NOMBRES_MOVIMIENTO_CONSUMO);
        $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

        if (empty($ids)) {
            throw new Exception('No se encontraron en el catálogo los tipos de movimiento configurados para el cálculo trimestral (' . implode(', ', self::NOMBRES_MOVIMIENTO_CONSUMO) . ')');
        }

        return $ids;
    }

    /**
     * Existencia y consumo TOTAL (no promedio) del trimestre [inicio, fin),
     * por bodega+producto+unidad, con nombres ya resueltos. `stock` puede
     * tener varias filas por combinación (una por lote) — se agrega con
     * SUM, igual que StockHelper::obtenerCantidadTotal().
     *
     * $idBodegaFiltro es opcional: si se indica, acota el cálculo a una
     * sola bodega (lo usa el flujo de autoservicio del Encargado de
     * Agencia — ver calcularSugerenciaBodega()). Sin filtro, se comporta
     * exactamente igual que antes (todas las bodegas, para el Administrador).
     *
     * También trae `id_tipo_producto` (1 = Correlativo, 2 = Expiración) para
     * que el llamador sepa qué líneas necesitan pedir correlativo.
     */
    private function calcularSugeridaPorLinea(array $idsMovimiento, string $inicio, string $fin, ?int $idBodegaFiltro = null): array
    {
        $placeholders = implode(',', array_fill(0, count($idsMovimiento), '?'));
        $filtroBodega = $idBodegaFiltro !== null ? ' AND s.id_bodega = ?' : '';

        $stmt = $this->connect->prepare(
            "SELECT s.id_bodega, b.nombre AS nombre_bodega,
                    s.id_producto, p.nombre AS producto, p.id_tipo AS id_tipo_producto,
                    s.id_unidad, u.nombre AS unidad, u.abreviatura,
                    SUM(s.cantidad_total) AS existencia,
                    COALESCE(consumo.total_consumido, 0) AS consumo_trimestre
             FROM bodega_inventario.stock s
             INNER JOIN bodega_inventario.bodegas b ON b.id = s.id_bodega AND b.activo = 1{$filtroBodega}
             INNER JOIN bodega_inventario.productos p ON p.id = s.id_producto
             INNER JOIN bodega_inventario.unidades_medida u ON u.id = s.id_unidad
             LEFT JOIN (
                 SELECT id_bodega, id_producto, id_unidad, SUM(cantidad) AS total_consumido
                 FROM bodega_inventario.movimientos_stock
                 WHERE id_tipo_movimiento IN ({$placeholders})
                   AND created_at >= ?
                   AND created_at < ?
                 GROUP BY id_bodega, id_producto, id_unidad
             ) consumo
                 ON consumo.id_bodega = s.id_bodega
                AND consumo.id_producto = s.id_producto
                AND consumo.id_unidad = s.id_unidad
             GROUP BY s.id_bodega, s.id_producto, s.id_unidad
             ORDER BY b.nombre ASC, p.nombre ASC"
        );

        $params = $idBodegaFiltro !== null ? [$idBodegaFiltro] : [];
        $params = array_merge($params, $idsMovimiento, [$inicio, $fin]);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_OBJ);
    }

    // -----------------------------------------------------------------
    // Control de "ya generado" por bodega + trimestre
    // -----------------------------------------------------------------

    /** @return array<array{id_bodega:int, bodega:string}> */
    private function obtenerBodegasYaGeneradas(int $anio, int $trimestre): array
    {
        $stmt = $this->connect->prepare(
            'SELECT g.id_bodega, b.nombre AS bodega
             FROM bodega_inventario.trimestres_generados g
             INNER JOIN bodega_inventario.bodegas b ON b.id = g.id_bodega
             WHERE g.anio = ? AND g.trimestre = ?'
        );
        $stmt->execute([$anio, $trimestre]);

        return array_map(
            static fn ($f) => ['id_bodega' => (int) $f->id_bodega, 'bodega' => $f->bodega],
            $stmt->fetchAll(PDO::FETCH_OBJ)
        );
    }

    private function bodegaYaGenerada(int $idBodega, int $anio, int $trimestre): bool
    {
        $stmt = $this->connect->prepare(
            'SELECT COUNT(*) FROM bodega_inventario.trimestres_generados
             WHERE id_bodega = ? AND anio = ? AND trimestre = ?'
        );
        $stmt->execute([$idBodega, $anio, $trimestre]);

        return (int) $stmt->fetchColumn() > 0;
    }

    private function registrarBodegaGenerada(int $idBodega, int $anio, int $trimestre, int $idCompra): void
    {
        $this->connect->prepare(
            'INSERT INTO bodega_inventario.trimestres_generados (id_bodega, anio, trimestre, id_compra)
             VALUES (?, ?, ?, ?)'
        )->execute([$idBodega, $anio, $trimestre, $idCompra]);
    }
}