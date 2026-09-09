{{--
    El recibo, como sale por la impresora de la ventanilla.

    ═══ POR QUE EL CSS ESTA ACA ADENTRO ═══

    Esta página no vive dentro del panel: no tiene el CSS de Filament ni el de
    Tailwind, y no debe tenerlos. Un documento que se entrega no puede
    depender de que un build de assets haya corrido — el día que Vite falle,
    el recibo tiene que seguir saliendo igual.

    ⚠️ La ÚNICA excepción, desde el 11-ago-2026, es la tipografía
    (`@include('comun.fuente')`): el recibo que el cliente se lleva estaba
    escrito en una letra distinta a la de la pantalla donde se lo cobraron.
    No contradice el párrafo de arriba —esos archivos los publica
    `filament:assets`, no Vite, y están versionados en git—, y si faltaran,
    el `font-display: swap` deja la hoja exactamente como se veía antes.

    ═══ MEDIA CARTA SOBRE CARTA ═══

    El contenido va en una columna angosta arriba de la hoja. Así el mismo
    archivo sale bien en carta y en A4, y quien use media carta corta por la
    línea de puntos. No hay tamaño de papel decidido con la contratante; este
    es el que no obliga a decidirlo.
--}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $recibo->tipoDeDocumento()->denominacion() }} {{ $recibo->numeroDelPapel() }}</title>
    @include('comun.fuente')
    @include('documentos.partes.recibo-estilos')
</head>
<body>

<div class="barra">
    <a href="{{ url()->previous() }}">Volver</a>
    <button type="button" onclick="window.print()">Imprimir</button>
</div>

@include('documentos.partes.recibo-hoja')

<script>
    // El flujo de ventanilla es cobrar e imprimir: el diálogo sale solo. Quien
    // solo quiere mirar lo cancela, y para eso está la ficha del recibo en el
    // panel, que no imprime nada.
    window.addEventListener('load', function () { window.print(); });
</script>

</body>
</html>
