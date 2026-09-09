{{--
    Varios recibos de un mismo cobro, para una sola pasada de impresora.

    ═══ POR QUE HAY UN SEGUNDO DOCUMENTO (9-sep-2026) ═══

    «Al pagar debería de abrirse de una la ventana para imprimir los recibos»
    — Mauricio, mirando cuatro notificaciones apiladas después de UN cobro.

    Un cobro sale en un recibo POR TITULAR, así que un contrato con varios
    representados emite varios papeles de un solo pago. Con la ruta de a uno
    eso son cuatro clics y cuatro diálogos que hay que cerrar de a uno.

    ⚠️ UNA HOJA POR RECIBO, siempre. Dos titulares no comparten hoja aunque
    sobre espacio: es el papel de otra persona, y se lo lleva doblado.

    La hoja y el CSS son los MISMOS que los del documento de a uno —
    `documentos.partes.*`—: dos plantillas que dicen lo mismo terminan no
    diciéndolo, y quien lo descubre es el cliente comparando dos papeles del
    mismo día sobre el mostrador.
--}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ count($papeles) }} recibos</title>
    @include('comun.fuente')
    @include('documentos.partes.recibo-estilos')
    <style>
        /* En pantalla, aire entre hojas para que se vean como lo que son:
           papeles distintos, no un documento largo. */
        .hoja + .hoja { margin-top: 1.25rem; }

        @media print {
            /* Cada hoja que sigue a otra empieza en página nueva. Por el
               hermano adyacente y no por `:first-of-type`, que miraría el
               primer <div> del body —la barra— y no la primera hoja. */
            .hoja + .hoja { break-before: page; page-break-before: always; }
            .hoja + .hoja { margin-top: 0; }
        }
    </style>
</head>
<body>

<div class="barra">
    <a href="{{ url()->previous() }}">Volver</a>
    <button type="button" onclick="window.print()">Imprimir</button>
</div>

@foreach ($papeles as $papel)
    @include('documentos.partes.recibo-hoja', $papel)
@endforeach

<script>
    // Igual que el documento de a uno: el flujo de ventanilla es cobrar e
    // imprimir, así que el diálogo sale solo. Quien solo quiere mirar lo
    // cancela — para eso está la ficha del recibo en el panel, que no imprime.
    window.addEventListener('load', function () { window.print(); });
</script>

</body>
</html>
