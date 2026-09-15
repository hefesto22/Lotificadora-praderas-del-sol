{{--
    El CSS del recibo, aparte para que lo compartan los DOS documentos.

    ═══ POR QUE ESTA SEPARADO (9-sep-2026) ═══

    Desde hoy el mismo recibo se imprime de dos maneras: de a uno
    —`documentos/recibo.blade.php`— y varios de una pasada
    —`documentos/recibos.blade.php`, el papel de un cobro que salió en varios
    recibos por tener titulares distintos—.

    🔴 Copiar el `<style>` en los dos habría sido la peor forma de tenerlo: el
    día que alguien corrija el tamaño de la letra en uno, el otro sigue
    imprimiendo como antes, y nadie lo nota hasta que compara dos papeles del
    mismo día sobre el mostrador.

    Sigue sin depender de ningún build, que es la regla del documento: es CSS
    a mano, adentro del HTML. Ver el encabezado de `recibo.blade.php`.
--}}
    <style>
        @page { size: letter; margin: 12mm; }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            padding: 1.5rem 1rem;
            background: #f4f4f5;
            color: #18181b;
            font-family: var(--olympo-fuente);
            font-size: 13px;
            line-height: 1.5;
        }

        .hoja {
            max-width: 190mm;
            margin: 0 auto;
            padding: 1.75rem 2rem 2rem;
            background: #fff;
            border: 1px solid #e4e4e7;
            border-radius: .5rem;
        }

        /* ── La barra de arriba no se imprime ── */
        .barra {
            max-width: 190mm;
            margin: 0 auto .875rem;
            display: flex;
            gap: .5rem;
            justify-content: flex-end;
        }
        .barra button, .barra a {
            padding: .5rem .9rem;
            border: 1px solid #d4d4d8;
            border-radius: .375rem;
            background: #fff;
            color: #27272a;
            font: inherit;
            font-size: 13px;
            text-decoration: none;
            cursor: pointer;
        }
        .barra button { background: #18181b; border-color: #18181b; color: #fff; font-weight: 600; }

        /* ── Encabezado ── */
        .encabezado { display: flex; justify-content: space-between; gap: 1.5rem; align-items: flex-start; }
        /* Membrete: el logo a la IZQUIERDA y los datos al lado, en una sola
           columna que baja. `.datos` es block a proposito —y esta dicho—
           porque `.emisor` es flex: sin esto, cada renglon del emisor se
           volvia una columna del encabezado y la direccion terminaba
           flotando al lado del nombre.

           `height` fija con `width:auto` y `object-fit:contain`: asi un logo
           alto, uno ancho y uno cuadrado ocupan la misma franja y el membrete
           se ve igual en los tres desarrollos. */
        .emisor { max-width: 62%; display: flex; gap: .75rem; align-items: flex-start; }
        .emisor img { height: 44px; width: auto; max-width: 140px; object-fit: contain; flex: none; }
        .emisor .datos { display: block; min-width: 0; }
        .emisor .residencial { font-size: 14px; font-weight: 700; letter-spacing: -.01em; line-height: 1.25; }
        .emisor .linea { color: #52525b; font-size: 11.5px; line-height: 1.45; }

        .folio { text-align: right; white-space: nowrap; }
        .folio .rotulo { font-size: 10px; font-weight: 600; letter-spacing: .08em; text-transform: uppercase; color: #71717a; }
        .folio .numero { font-size: 22px; font-weight: 700; font-variant-numeric: tabular-nums; }
        .folio .fecha { font-size: 12px; color: #52525b; }

        /* Un recibo anulado se puede seguir imprimiendo —hace falta para
           mostrar que ese número no vale— pero el papel tiene que gritarlo,
           no susurrarlo en una esquina. */
        .anulado {
            display: block; margin-top: .5rem;
            padding: .35rem .6rem; border: 2px solid #dc2626; border-radius: .375rem;
            background: #fef2f2; color: #b91c1c;
            font-size: 13px; font-weight: 800; letter-spacing: .12em; text-align: center;
        }
        .anulado small {
            display: block; margin-top: .2rem;
            font-size: 9px; font-weight: 500; letter-spacing: 0; color: #7f1d1d;
        }

        hr { border: 0; border-top: 1px solid #e4e4e7; margin: 1.125rem 0; }

        /* ── Datos ── */
        .datos { display: grid; grid-template-columns: 1fr 1fr; gap: .5rem 1.5rem; }
        .dato .rotulo { font-size: 10px; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; color: #71717a; }
        .dato .valor { font-weight: 600; }

        /* ── Detalle ── */
        h2 { margin: 1.25rem 0 .5rem; font-size: 11px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; color: #71717a; }

        table { width: 100%; border-collapse: collapse; font-size: 12.5px; }
        th {
            padding: 0 .5rem .375rem; text-align: right; white-space: nowrap;
            font-size: 10px; font-weight: 600; letter-spacing: .06em; text-transform: uppercase;
            color: #71717a; border-bottom: 1px solid #e4e4e7;
        }
        td { padding: .4rem .5rem; text-align: right; font-variant-numeric: tabular-nums; border-bottom: 1px dashed #e4e4e7; }
        th:first-child, td:first-child { text-align: left; padding-left: 0; }
        th:last-child, td:last-child { padding-right: 0; }
        tr:last-child td { border-bottom: 0; }
        .capital td { color: #1d4ed8; font-weight: 600; }

        /* ── Total ── */
        .total { margin-top: 1rem; padding: .75rem 1rem; background: #fafafa; border: 1px solid #e4e4e7; border-radius: .5rem; }
        .total .cifra { display: flex; justify-content: space-between; align-items: baseline; gap: 1rem; }
        .total .cifra .rotulo { font-size: 11px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; color: #71717a; }
        .total .cifra .monto { font-size: 20px; font-weight: 700; font-variant-numeric: tabular-nums; }
        .total .letras { margin-top: .25rem; font-size: 11px; font-weight: 600; color: #3f3f46; letter-spacing: .02em; }

        .nota { margin-top: .75rem; font-size: 11px; line-height: 1.6; color: #71717a; }

        /* ── Lo que la factura tiene que decir y el recibo no ──
           Acuerdo 481-2017, Art. 10. Va en un recuadro propio porque son
           datos que nadie lee de corrido: se buscan. Un auditor mira la CAI,
           el cliente mira el numero, y los dos tienen que encontrarlos sin
           leer el resto del papel. */
        .fiscal {
            margin-top: 1rem; padding: .625rem .875rem;
            border: 1px solid #d4d4d8; border-radius: .375rem;
            font-size: 10.5px; line-height: 1.55; color: #3f3f46;
        }
        .fiscal .cai { font-family: ui-monospace, "SFMono-Regular", Menlo, Consolas, monospace; word-break: break-all; }
        .fiscal .rotulo { font-weight: 700; color: #52525b; }
        .fiscal .destino { margin-top: .375rem; color: #71717a; }

        /* El desglose del impuesto. Grid de dos columnas para que las cifras
           queden alineadas a la derecha sin una tabla mas. */
        .impuesto { margin-top: .625rem; display: grid; grid-template-columns: 1fr auto; gap: .15rem .75rem; font-size: 11.5px; }
        .impuesto .cifra { text-align: right; font-variant-numeric: tabular-nums; }
        .impuesto .fuerte { font-weight: 700; }
        /* El total se despega del desglose: son la misma caja pero no la
           misma pregunta. */
        .impuesto + .cifra { margin-top: .5rem; padding-top: .5rem; border-top: 1px solid #e4e4e7; }

        /* ── Firmas ── */
        .firmas { display: grid; grid-template-columns: 1fr 1fr; gap: 3rem; margin-top: 3rem; }
        .firma { border-top: 1px solid #a1a1aa; padding-top: .375rem; text-align: center; font-size: 11px; color: #52525b; }

        .corte { margin-top: 1.5rem; border-top: 1px dashed #d4d4d8; }

        /* ── Impresión ── */
        @media print {
            body { padding: 0; background: #fff; font-size: 12px; }
            .barra { display: none !important; }
            .hoja { max-width: none; border: 0; border-radius: 0; padding: 0; }
            .total { background: transparent; }
        }
    </style>
