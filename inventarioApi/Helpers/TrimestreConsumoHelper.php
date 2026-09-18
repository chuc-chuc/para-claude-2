<?php

declare(strict_types=1);

namespace App\inventarioApi\Helpers;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * TrimestreConsumoHelper
 *
 * Un solo lugar para definir "qué ventana de consumo le toca revisar al
 * pedido trimestral hoy". Maneja DOS conceptos separados, cada uno con
 * su propia constante:
 *
 *   - PASO_MESES: cada cuántos meses se genera un nuevo pedido
 *     (la frecuencia sigue siendo trimestral → 3 meses, no se toca).
 *   - ANCHO_VENTANA_MESES: cuántos meses de consumo se suman para
 *     calcular la sugerida en cada pedido (4 meses).
 *
 * Como ANCHO_VENTANA_MESES (4) es mayor que PASO_MESES (3), las ventanas
 * de consumo quedan SOLAPADAS un mes con la ventana anterior: el mes en
 * que cerró un ciclo es también el mes en que arranca el siguiente.
 * Con MES_INICIO_PRIMER_TRIMESTRE = 11 (noviembre, valor actual) el
 * resultado es:
 *
 *   Nov-Feb · Feb-May · May-Ago · Ago-Nov   (cada ventana dura 4 meses,
 *                                             el pedido se sigue generando
 *                                             cada 3)
 *
 * Si el negocio decide cambiar en qué mes arranca el ciclo, se toca
 * ÚNICAMENTE `MES_INICIO_PRIMER_TRIMESTRE`. Si decide cambiar cuántos
 * meses de consumo se miden, o cada cuánto se genera el pedido, se
 * tocan `ANCHO_VENTANA_MESES` / `PASO_MESES` respectivamente — nada
 * más en el resto del sistema depende de estos valores.
 *
 * ── Cómo configurar el desfase (con PASO_MESES = 3) ─────────────────────
 * `MES_INICIO_PRIMER_TRIMESTRE` es el mes (1-12) en el que arranca el
 * primer ciclo; los otros 3 se calculan solos, cada uno PASO_MESES
 * después, dando la vuelta al año si hace falta. Ejemplos (con el ancho
 * de ventana ya en 4 meses):
 *
 *   MES_INICIO_PRIMER_TRIMESTRE = 1  (enero)
 *     → Ene-Abr · Abr-Jul · Jul-Oct · Oct-Ene
 *
 *   MES_INICIO_PRIMER_TRIMESTRE = 12  (diciembre)
 *     → Dic-Mar · Mar-Jun · Jun-Sep · Sep-Dic
 *
 *   MES_INICIO_PRIMER_TRIMESTRE = 11  (noviembre, valor actual)
 *     → Nov-Feb · Feb-May · May-Ago · Ago-Nov
 *
 * En todos los casos, la regla de "cuál es el objetivo" no cambia: siempre
 * es el ÚLTIMO ciclo ya cerrado respecto a la fecha de hoy.
 */
final class TrimestreConsumoHelper
{
    /** Mes (1-12) en que arranca el primer ciclo. Tocar solo esto para reconfigurar el desfase. */
    private const MES_INICIO_PRIMER_TRIMESTRE = 11;

    /** Cada cuántos meses se genera un nuevo pedido (frecuencia del pedido). No confundir con el ancho de la ventana de abajo. */
    private const PASO_MESES = 3;

    /** Cuántos meses de consumo se suman para calcular la sugerida de cada pedido. Si es mayor que PASO_MESES, las ventanas quedan solapadas. */
    private const ANCHO_VENTANA_MESES = 4;

    private const MESES_ABREV = [
        1 => 'Ene', 2 => 'Feb', 3 => 'Mar', 4 => 'Abr', 5 => 'May', 6 => 'Jun',
        7 => 'Jul', 8 => 'Ago', 9 => 'Sep', 10 => 'Oct', 11 => 'Nov', 12 => 'Dic',
    ];

    /**
     * @return array{anio:int, trimestre:int, inicio:string, fin:string, etiqueta:string}
     *         inicio/fin en formato 'Y-m-d H:i:s', inicio inclusive y fin exclusivo.
     *         `anio` es solo la etiqueta interna del ciclo (para identificarlo en
     *         `trimestres_generados`) — no necesariamente el año calendario del
     *         mes en que arranca, cuando el ciclo cruza fin de año (ej. Nov-Feb).
     */
    public function obtenerTrimestreObjetivo(?DateTimeImmutable $fecha = null): array
    {
        $fecha = $fecha ?? new DateTimeImmutable('now');

        [$anioActual, $trimestreActual] = $this->trimestreDeFecha($fecha);

        $trimestreObjetivo = $trimestreActual - 1;
        $anioObjetivo       = $anioActual;

        if ($trimestreObjetivo < 1) {
            $trimestreObjetivo = 4;
            $anioObjetivo--;
        }

        [$inicio, $fin] = $this->rangoDelTrimestre($anioObjetivo, $trimestreObjetivo);

        return [
            'anio'      => $anioObjetivo,
            'trimestre' => $trimestreObjetivo,
            'inicio'    => $inicio,
            'fin'       => $fin,
            'etiqueta'  => $this->etiquetaTrimestre($anioObjetivo, $trimestreObjetivo),
        ];
    }

    /**
     * A qué trimestre del ciclo (año interno, número 1-4) pertenece una fecha,
     * según el mes de arranque configurado en MES_INICIO_PRIMER_TRIMESTRE.
     *
     * @return array{0:int,1:int} [anio, trimestre]
     */
    private function trimestreDeFecha(DateTimeImmutable $fecha): array
    {
        $anio = (int) $fecha->format('Y');
        $mes  = (int) $fecha->format('n');

        $mesesDesdeAncla = $mes - self::MES_INICIO_PRIMER_TRIMESTRE;
        if ($mesesDesdeAncla < 0) {
            $mesesDesdeAncla += 12;
            $anio--; // la fecha cae dentro del ciclo que arrancó el año calendario anterior
        }

        $trimestre = intdiv($mesesDesdeAncla, self::PASO_MESES) + 1; // 1..4 — en qué ciclo cae la fecha, según cada cuánto se genera pedido

        return [$anio, $trimestre];
    }

    /**
     * Rango de fechas de la VENTANA DE CONSUMO de un ciclo dado.
     *
     * El inicio se calcula con el PASO (cada cuánto se genera pedido,
     * 3 meses) pero el ancho del rango usa ANCHO_VENTANA_MESES (4 meses)
     * — por eso, si ANCHO_VENTANA_MESES > PASO_MESES, este rango se
     * solapa un mes con el del ciclo anterior y otro con el siguiente.
     *
     * @return array{0:string,1:string} [inicio inclusive, fin exclusivo) en formato 'Y-m-d H:i:s'
     */
    public function rangoDelTrimestre(int $anio, int $trimestre): array
    {
        if ($trimestre < 1 || $trimestre > 4) {
            throw new InvalidArgumentException("Trimestre inválido: {$trimestre} (debe ser 1 a 4)");
        }

        $mesInicioAbsoluto = self::MES_INICIO_PRIMER_TRIMESTRE + ($trimestre - 1) * self::PASO_MESES;
        $anioInicio        = $anio + intdiv($mesInicioAbsoluto - 1, 12);
        $mesInicio         = (($mesInicioAbsoluto - 1) % 12) + 1;

        $inicio = sprintf('%04d-%02d-01 00:00:00', $anioInicio, $mesInicio);
        $fin    = (new DateTimeImmutable($inicio))->modify('+' . self::ANCHO_VENTANA_MESES . ' months')->format('Y-m-d H:i:s');

        return [$inicio, $fin];
    }

    /** Etiqueta legible, resuelta dinámicamente contra el rango real (soporta ciclos que cruzan de año, ej. Nov-Feb). */
    public function etiquetaTrimestre(int $anio, int $trimestre): string
    {
        [$inicio, $fin] = $this->rangoDelTrimestre($anio, $trimestre);

        $fechaInicio = new DateTimeImmutable($inicio);
        // En lugar de modificar días sobre la fecha fin:
        $fechaFin = (new DateTimeImmutable($fin))->modify('-1 second'); // último día real del trimestre

        $mesInicio  = self::MESES_ABREV[(int) $fechaInicio->format('n')];
        $anioInicio = (int) $fechaInicio->format('Y');
        $mesFin     = self::MESES_ABREV[(int) $fechaFin->format('n')];
        $anioFin    = (int) $fechaFin->format('Y');

        if ($anioInicio === $anioFin) {
            return "{$mesInicio}-{$mesFin} {$anioInicio}";
        }

        return "{$mesInicio} {$anioInicio} - {$mesFin} {$anioFin}";
    }
}