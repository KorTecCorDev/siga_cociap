<?php

namespace App\Siagie;

use RuntimeException;
use ZipArchive;

/**
 * XlsxQuirurgico
 *
 * Lectura y escritura QUIRÚRGICA de un .xlsx (ZIP de XMLs) sin reescribir el
 * libro con una librería: el archivo se re-sube al SIAGIE (registro oficial
 * UGEL-MINEDU) y cualquier alteración de estructura (protección, hoja oculta,
 * estilos, validaciones) puede hacer que lo rechace. Aquí SOLO se insertan
 * valores dentro de celdas existentes (o se crea la celda puntual si falta),
 * todo lo demás queda intacto.
 *
 * Las plantillas del SIAGIE declaran las celdas de notas como shared string
 * (`<x:c r="D3" s="68" t="s"/>`), así que los textos se agregan a
 * sharedStrings.xml y la celda referencia el índice — el mismo mecanismo que
 * usa el propio SIAGIE.
 */
class XlsxQuirurgico
{
    private string $ruta;
    /** @var array entry => contenido XML (solo partes leídas/modificadas) */
    private array $xml = [];
    /** @var array nombreHoja => entry dentro del zip */
    private array $hojas = [];
    /** @var array índice => texto (sharedStrings) */
    private array $sst = [];
    /** @var array texto => índice (para reusar en vez de duplicar) */
    private array $sstPorTexto = [];
    private int $sstCountOriginal = 0;
    private int $sstNuevasRefs = 0;
    private bool $sstModificado = false;
    /** @var array entry => [ref => texto] escrituras pendientes */
    private array $escrituras = [];
    /** @var array entry => [[buscar, reemplazo]] cambios en el pie de página */
    private array $pies = [];
    /** @var array entry => [fila => ['ht' => ?float, 'oculta' => bool]] */
    private array $filas = [];

    public function __construct(string $ruta)
    {
        $this->ruta = $ruta;
        $this->abrir();
    }

    private function abrir(): void
    {
        $zip = new ZipArchive();
        if ($zip->open($this->ruta) !== true) {
            throw new RuntimeException("No se pudo abrir el xlsx: {$this->ruta}");
        }

        $workbook = $zip->getFromName('xl/workbook.xml');
        $rels     = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($workbook === false || $rels === false) {
            $zip->close();
            throw new RuntimeException('El archivo no tiene estructura de xlsx (falta workbook)');
        }
        $this->xml['xl/workbook.xml'] = $workbook;

        // rId → entry de hoja (el Target puede venir relativo o absoluto)
        $porRid = [];
        foreach ($this->matchAll('/<Relationship[^>]*>/', $rels) as $rel) {
            if (preg_match('/Id="([^"]+)"/', $rel, $mi) && preg_match('/Target="([^"]*worksheets\/[^"]+\.xml)"/', $rel, $mt)) {
                $porRid[$mi[1]] = 'xl/' . preg_replace('#^/?(xl/)?#', '', $mt[1]);
            }
        }
        foreach ($this->matchAll('/<(?:\w+:)?sheet [^>]*>/', $workbook) as $sheet) {
            if (preg_match('/name="([^"]+)"/', $sheet, $mn) && preg_match('/r:id="([^"]+)"/', $sheet, $mr)) {
                if (isset($porRid[$mr[1]])) {
                    $this->hojas[$this->desescapar($mn[1])] = $porRid[$mr[1]];
                }
            }
        }

        // sharedStrings: cada <si> puede tener varios <t> (rich text)
        $ss = $zip->getFromName('xl/sharedStrings.xml');
        if ($ss !== false) {
            $this->xml['xl/sharedStrings.xml'] = $ss;
            if (preg_match('/<(?:\w+:)?sst[^>]*\bcount="(\d+)"/', $ss, $mc)) {
                $this->sstCountOriginal = (int) $mc[1];
            }
            foreach ($this->matchAll('/<(?:\w+:)?si>.*?<\/(?:\w+:)?si>/s', $ss) as $i => $si) {
                $texto = '';
                foreach ($this->matchAll('/<(?:\w+:)?t[^>]*>(.*?)<\/(?:\w+:)?t>/s', $si, 1) as $t) {
                    $texto .= $this->desescapar($t);
                }
                $this->sst[$i] = $texto;
                if (!isset($this->sstPorTexto[$texto])) {
                    $this->sstPorTexto[$texto] = $i;
                }
            }
        }
        $zip->close();
    }

    /** @return string[] nombres de hoja en orden del libro */
    public function nombresDeHojas(): array
    {
        return array_keys($this->hojas);
    }

    /**
     * Celdas con valor de una hoja: [fila => [columna => valor-texto]].
     * Resuelve shared strings e inline strings; los numéricos van tal cual.
     */
    public function leerCeldas(string $hoja): array
    {
        $xml    = $this->xmlDeHoja($hoja);
        $celdas = [];
        // `[^>]*(?<!\/)>`: una fila vacía autocerrada (`<row r="4" .../>`) no abre
        // bloque; sin esto se comía el contenido de la fila siguiente (28/09/2026).
        foreach ($this->matchAll('/<(?:\w+:)?row r="(\d+)"[^>]*(?<!\/)>(.*?)<\/(?:\w+:)?row>/s', $xml, null) as $row) {
            $fila = (int) $row[1];
            foreach ($this->matchAll('/<(?:\w+:)?c r="([A-Z]+)\d+"([^>]*?)(?:\/>|>(.*?)<\/(?:\w+:)?c>)/s', $row[2], null) as $c) {
                $col    = $c[1];
                $attrs  = $c[2];
                $inner  = $c[3] ?? '';
                $valor  = null;
                if (preg_match('/<(?:\w+:)?v>(.*?)<\/(?:\w+:)?v>/s', $inner, $mv)) {
                    $valor = $this->desescapar($mv[1]);
                    if (preg_match('/\bt="s"/', $attrs)) {
                        $valor = $this->sst[(int) $valor] ?? '';
                    }
                } elseif (preg_match('/\bt="inlineStr"/', $attrs) && preg_match('/<(?:\w+:)?t[^>]*>(.*?)<\/(?:\w+:)?t>/s', $inner, $mt)) {
                    $valor = $this->desescapar($mt[1]);
                }
                if ($valor !== null && $valor !== '') {
                    $celdas[$fila][$col] = $valor;
                }
            }
        }
        return $celdas;
    }

    /** Encola la escritura de un texto en una celda (p. ej. "D3"). */
    public function escribir(string $hoja, string $ref, string $texto): void
    {
        $entry = $this->hojas[$hoja] ?? null;
        if ($entry === null) {
            throw new RuntimeException("Hoja inexistente: {$hoja}");
        }
        $this->escrituras[$entry][$ref] = $texto;
    }

    /**
     * Encola un reemplazo de texto dentro del pie/encabezado de página de una
     * hoja (`<headerFooter>`), y SOLO ahí. Nació para el año que la plantilla de
     * asistencia trae escrito en su pie («CAVVG/HZ/2026», 28/09/2026). Si el
     * texto no está, no hace nada: `pieContiene()` permite comprobarlo.
     */
    public function reemplazarEnPie(string $hoja, string $buscar, string $reemplazo): void
    {
        $entry = $this->hojas[$hoja] ?? null;
        if ($entry === null) {
            throw new RuntimeException("Hoja inexistente: {$hoja}");
        }
        $this->pies[$entry][] = [$buscar, $reemplazo];
    }

    /**
     * Fija el alto (en puntos) de una fila EXISTENTE de la hoja. Nació para la
     * planilla de asistencia (28/09/2026): el alto de las filas de estudiantes se
     * ajusta para llenar la hoja. Solo toca los atributos del `<row>`.
     */
    public function altoFila(string $hoja, int $fila, float $puntos): void
    {
        $entry = $this->hojas[$hoja] ?? throw new RuntimeException("Hoja inexistente: {$hoja}");
        $this->filas[$entry][$fila]['ht'] = round($puntos, 2);
    }

    /** Oculta una fila EXISTENTE de la hoja (no se imprime). Mismo origen que altoFila(). */
    public function ocultarFila(string $hoja, int $fila): void
    {
        $entry = $this->hojas[$hoja] ?? throw new RuntimeException("Hoja inexistente: {$hoja}");
        $this->filas[$entry][$fila]['oculta'] = true;
    }

    /** ¿El pie/encabezado de página de la hoja contiene ese texto (ya escapado a XML)? */
    public function pieContiene(string $hoja, string $texto): bool
    {
        return preg_match('/<(?:\w+:)?headerFooter\b.*?<\/(?:\w+:)?headerFooter>/s', $this->xmlDeHoja($hoja), $m) === 1
            && str_contains($m[0], $this->escapar($texto));
    }

    /** Cantidad total de escrituras encoladas. */
    public function totalEscrituras(): int
    {
        return array_sum(array_map('count', $this->escrituras));
    }

    /**
     * Aplica las escrituras sobre una COPIA temporal del archivo original y
     * devuelve su ruta. El original NO se toca aquí: el llamador verifica la
     * copia, respalda el original y recién entonces lo reemplaza.
     */
    public function guardarEnTemporal(): string
    {
        // 1. Aplicar escrituras en memoria, hoja por hoja
        $modificadas = [];
        foreach ($this->escrituras as $entry => $refs) {
            $xml = $this->xmlDeEntry($entry);
            foreach ($refs as $ref => $texto) {
                $xml = $this->insertarValor($xml, $ref, $texto);
            }
            $modificadas[$entry] = $xml;
        }
        // Alto y visibilidad de filas: solo los atributos del <row>.
        foreach ($this->filas as $entry => $cambios) {
            $xml = $modificadas[$entry] ?? $this->xmlDeEntry($entry);
            foreach ($cambios as $fila => $c) {
                $xml = $this->ajustarFila($xml, (int) $fila, $c['ht'] ?? null, $c['oculta'] ?? false);
            }
            $modificadas[$entry] = $xml;
        }
        // Reemplazos en el pie: solo dentro de <headerFooter>, nunca en las celdas.
        foreach ($this->pies as $entry => $cambios) {
            $xml = $modificadas[$entry] ?? $this->xmlDeEntry($entry);
            $xml = preg_replace_callback(
                '/<(?:\w+:)?headerFooter\b.*?<\/(?:\w+:)?headerFooter>/s',
                function (array $m) use ($cambios): string {
                    $pie = $m[0];
                    foreach ($cambios as [$buscar, $reemplazo]) {
                        $pie = str_replace($this->escapar($buscar), $this->escapar($reemplazo), $pie);
                    }
                    return $pie;
                },
                $xml
            );
            $modificadas[$entry] = $xml;
        }

        // 2. sharedStrings: anexar <si> nuevos y actualizar count/uniqueCount
        if ($this->sstModificado || $this->sstNuevasRefs > 0) {
            $modificadas['xl/sharedStrings.xml'] = $this->reconstruirSharedStrings();
        }

        // 3. Copia temporal + reemplazo de entries (todo lo demás queda intacto)
        $tmp = $this->ruta . '.tmp_siagie';
        if (!copy($this->ruta, $tmp)) {
            throw new RuntimeException("No se pudo crear la copia temporal: {$tmp}");
        }
        $zip = new ZipArchive();
        if ($zip->open($tmp) !== true) {
            @unlink($tmp);
            throw new RuntimeException("No se pudo abrir la copia temporal: {$tmp}");
        }
        foreach ($modificadas as $entry => $contenido) {
            if (!$zip->addFromString($entry, $contenido)) {
                $zip->close();
                @unlink($tmp);
                throw new RuntimeException("No se pudo escribir {$entry} en la copia temporal");
            }
        }
        $zip->close();
        return $tmp;
    }

    /**
     * Inserta el valor en la celda del XML de la hoja. La celda de la
     * plantilla normalmente existe vacía y con estilo (`<x:c r="D3" s="68"
     * t="s"/>`); si no existiera, se crea heredando el estilo de su columna.
     */
    private function insertarValor(string $xml, string $ref, string $texto): string
    {
        $idx = $this->indiceSst($texto);
        $pfx = str_contains($xml, '<x:worksheet') ? 'x:' : '';

        // Celda existente (autocerrada o con contenido)
        $patron = '/<((?:\w+:)?)c r="' . preg_quote($ref, '/') . '"([^>]*?)(\/>|>(.*?)<\/(?:\w+:)?c>)/s';
        if (preg_match($patron, $xml, $m, PREG_OFFSET_CAPTURE)) {
            $p     = $m[1][0];
            $attrs = $m[2][0];
            $inner = $m[4][0] ?? '';
            if (preg_match('/<(?:\w+:)?v>\s*\S/', $inner)) {
                throw new RuntimeException("La celda {$ref} ya tiene valor — no se sobreescribe");
            }
            // Forzar t="s" conservando el resto de atributos (estilo incluido)
            $attrs = preg_match('/\bt="[^"]*"/', $attrs)
                ? preg_replace('/\bt="[^"]*"/', 't="s"', $attrs)
                : $attrs . ' t="s"';
            $nueva = "<{$p}c r=\"{$ref}\"{$attrs}><{$p}v>{$idx}</{$p}v></{$p}c>";
            return substr_replace($xml, $nueva, $m[0][1], strlen($m[0][0]));
        }

        // Celda ausente: crearla dentro de su fila, en orden de columna,
        // heredando el estilo que usa esa columna en otras filas.
        if (!preg_match('/^([A-Z]+)(\d+)$/', $ref, $mr)) {
            throw new RuntimeException("Referencia de celda inválida: {$ref}");
        }
        [, $col, $fila] = $mr;
        $estilo = preg_match('/<(?:\w+:)?c r="' . $col . '\d+"[^>]*\bs="(\d+)"/', $xml, $ms) ? " s=\"{$ms[1]}\"" : '';
        $celda  = "<{$pfx}c r=\"{$ref}\"{$estilo} t=\"s\"><{$pfx}v>{$idx}</{$pfx}v></{$pfx}c>";

        $patronFila = '/<((?:\w+:)?)row r="' . $fila . '"([^>]*)>(.*?)(<\/(?:\w+:)?row>)/s';
        if (!preg_match($patronFila, $xml, $mf, PREG_OFFSET_CAPTURE)) {
            throw new RuntimeException("La fila {$fila} no existe en la hoja — no se puede crear {$ref}");
        }
        $cuerpo  = $mf[3][0];
        $miNum   = $this->columnaANumero($col);
        $offset  = strlen($cuerpo); // por defecto, al final de la fila
        foreach ($this->matchAll('/<(?:\w+:)?c r="([A-Z]+)\d+"/', $cuerpo, null, PREG_OFFSET_CAPTURE) as $c) {
            if ($this->columnaANumero($c[1][0]) > $miNum) {
                $offset = $c[0][1];
                break;
            }
        }
        $cuerpoNuevo = substr($cuerpo, 0, $offset) . $celda . substr($cuerpo, $offset);
        $filaNueva   = '<' . $mf[1][0] . 'row r="' . $fila . '"' . $mf[2][0] . '>' . $cuerpoNuevo . $mf[4][0];
        return substr_replace($xml, $filaNueva, $mf[0][1], strlen($mf[0][0]));
    }

    /** Reescribe los atributos ht/customHeight/hidden de un `<row>` existente. */
    private function ajustarFila(string $xml, int $fila, ?float $ht, bool $oculta): string
    {
        $patron = '/<((?:\w+:)?)row r="' . $fila . '"([^>]*?)(\/?)>/';
        if (!preg_match($patron, $xml, $m, PREG_OFFSET_CAPTURE)) {
            throw new RuntimeException("La fila {$fila} no existe en la hoja");
        }
        $attrs = $m[2][0];
        if ($ht !== null) {
            $attrs = preg_replace('/\s(?:ht|customHeight)="[^"]*"/', '', $attrs)
                . ' ht="' . $ht . '" customHeight="1"';
        }
        if ($oculta) {
            $attrs = preg_replace('/\shidden="[^"]*"/', '', $attrs) . ' hidden="1"';
        }
        $nueva = '<' . $m[1][0] . 'row r="' . $fila . '"' . $attrs . $m[3][0] . '>';
        return substr_replace($xml, $nueva, $m[0][1], strlen($m[0][0]));
    }

    /** Índice en sharedStrings del texto (reusa el existente o anexa uno). */
    private function indiceSst(string $texto): int
    {
        $this->sstNuevasRefs++;
        if (isset($this->sstPorTexto[$texto])) {
            return $this->sstPorTexto[$texto];
        }
        $idx = count($this->sst);
        $this->sst[$idx]            = $texto;
        $this->sstPorTexto[$texto]  = $idx;
        $this->sstModificado        = true;
        return $idx;
    }

    private function reconstruirSharedStrings(): string
    {
        $xml = $this->xml['xl/sharedStrings.xml']
            ?? '<?xml version="1.0" encoding="utf-8" standalone="yes"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="0" uniqueCount="0"></sst>';
        $pfx = preg_match('/<(\w+:)sst/', $xml, $mp) ? $mp[1] : '';

        // Anexar los <si> nuevos justo antes del cierre
        $nuevos = '';
        if (preg_match('/<(?:\w+:)?sst[^>]*\buniqueCount="(\d+)"/', $xml, $mu)) {
            $desde = (int) $mu[1];
        } else {
            $desde = substr_count($xml, $pfx === '' ? '<si>' : "<{$pfx}si>");
        }
        for ($i = $desde; $i < count($this->sst); $i++) {
            $nuevos .= "<{$pfx}si><{$pfx}t xml:space=\"preserve\">" . $this->escapar($this->sst[$i]) . "</{$pfx}t></{$pfx}si>";
        }
        if ($nuevos !== '') {
            $xml = preg_replace('/<\/((?:\w+:)?)sst>/', $nuevos . '</$1sst>', $xml, 1);
        }

        // Actualizar los contadores del <sst> (el SIAGIE puede validarlos)
        $count  = $this->sstCountOriginal + $this->sstNuevasRefs;
        $unique = count($this->sst);
        $xml = preg_replace('/(<(?:\w+:)?sst[^>]*\bcount=")\d+(")/', '${1}' . $count . '$2', $xml, 1);
        $xml = preg_replace('/(<(?:\w+:)?sst[^>]*\buniqueCount=")\d+(")/', '${1}' . $unique . '$2', $xml, 1);
        return $xml;
    }

    // ── utilitarios ─────────────────────────────────────────────

    private function xmlDeHoja(string $hoja): string
    {
        $entry = $this->hojas[$hoja] ?? null;
        if ($entry === null) {
            throw new RuntimeException("Hoja inexistente: {$hoja}");
        }
        return $this->xmlDeEntry($entry);
    }

    private function xmlDeEntry(string $entry): string
    {
        if (!isset($this->xml[$entry])) {
            $zip = new ZipArchive();
            if ($zip->open($this->ruta) !== true) {
                throw new RuntimeException("No se pudo reabrir el xlsx: {$this->ruta}");
            }
            $contenido = $zip->getFromName($entry);
            $zip->close();
            if ($contenido === false) {
                throw new RuntimeException("Entry inexistente en el xlsx: {$entry}");
            }
            $this->xml[$entry] = $contenido;
        }
        return $this->xml[$entry];
    }

    /** matchAll con grupo opcional: devuelve el grupo N o el match completo. */
    private function matchAll(string $patron, string $sujeto, ?int $grupo = 0, int $flags = 0): array
    {
        if ($flags === PREG_OFFSET_CAPTURE) {
            preg_match_all($patron, $sujeto, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
            return $m;
        }
        preg_match_all($patron, $sujeto, $m, $grupo === null ? PREG_SET_ORDER : PREG_PATTERN_ORDER);
        return $grupo === null ? $m : $m[$grupo];
    }

    private function columnaANumero(string $col): int
    {
        $n = 0;
        foreach (str_split($col) as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }
        return $n;
    }

    private function escapar(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function desescapar(string $s): string
    {
        return html_entity_decode($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
