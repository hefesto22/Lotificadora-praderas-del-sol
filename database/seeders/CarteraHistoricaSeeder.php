<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Enums\EstadoLote;
use App\Domain\Enums\FormaDePago;
use App\Domain\Enums\ModalidadDeReprogramacion;
use App\Domain\Enums\TipoCorrelativo;
use App\Domain\Exceptions\ValueObjectInvalidoException;
use App\Domain\Pagos\RegistroDePagos;
use App\Domain\ValueObjects\DNI;
use App\Domain\ValueObjects\Monto;
use App\Domain\Ventas\PrecioPactado;
use App\Domain\Ventas\RegistroDeVentas;
use App\Models\Cliente;
use App\Models\Compromiso;
use App\Models\Lote;
use App\Models\Proyecto;
use App\Models\Vendedor;
use App\Models\Venta;
use Carbon\CarbonImmutable;
use Database\Seeders\Cartera\ExpedientesHistoricos;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Carga la cartera que la lotificadora vendió antes de tener sistema.
 *
 *   php artisan db:seed --class=CarteraHistoricaSeeder
 *
 * ═══ POR QUE UN SEEDER Y NO UNA PANTALLA DE CARGA ═══
 *
 * Porque son cincuenta expedientes con su historial, y digitarlos a mano en el
 * panel es un día entero de una persona **y** la garantía de que alguno quede
 * mal. Acá los datos viven en `Cartera\ExpedientesHistoricos`, se revisan
 * contra el cuaderno de a uno, quedan versionados en git y el día del arranque
 * en producción es un solo comando.
 *
 * Si algo sale mal, `olympo:limpiar-cartera` deja todo en cero y se vuelve a
 * correr. Eso no se puede hacer con una carga a mano.
 *
 * ═══ TODO PASA POR LOS SERVICES ═══
 *
 * 🔴 **No hay un solo `insert` a mano acá.** Cada venta entra por
 * `RegistroDeVentas::activar()` y cada pago por `RegistroDePagos`, igual que si
 * alguien lo hubiera tecleado en la pantalla. Es la única forma de que la
 * cartera vieja quede con las mismas reglas que la nueva: el plan de cuotas
 * calculado por el mismo motor, la imputación por el mismo FIFO, los CHECKs de
 * la base cumplidos y la bitácora escrita.
 *
 * Un seeder que inserta filas a mano produce una base que *parece* correcta y
 * que se rompe el primer día que alguien cobre sobre ella.
 *
 * ═══ 🔴 EL NUMERO DE RECIBO: LA PARTE RARA Y POR QUE ═══
 *
 * **Los recibos históricos NO llevan el número del talonario de papel.** El
 * seeder numera de corrido —1, 2, 3…— por la puerta de siempre, y al final
 * `dejarLasSeriesDondeVan()` empuja la serie hasta `OLYMPO_PROXIMO_RECIBO`,
 * que es el próximo número en blanco del talonario. Así el primer recibo que
 * el sistema emita en producción no repite uno que ya está impreso y firmado.
 *
 * El número del papel sí queda escrito: va en las **observaciones** de cada
 * recibo, que es donde se puede buscar cuando el cliente llega con el suyo en
 * la mano. `ExpedientesHistoricos` lo trae en `recibo` y `recibo_prima` como
 * transcripción, no como instrucción — el seeder no lo usa para numerar.
 *
 * ⚠️ **Ojo con esto al leer el archivo de datos:** que un `recibo` esté
 * repetido ahí NO hace fallar la carga, porque ese número nunca llega a
 * `recibos.numero`. La unicidad de la base no lo va a atajar.
 *
 * Y son STRINGS con sus ceros —«00000053», «0049», «0075-1»— porque el
 * cuaderno lleva dos talonarios y el 0049 corto no es el 00000049 largo.
 *
 * Esto reemplaza a lo que decía acá antes —que el seeder acomodaba la serie
 * ANTES de cada cobro para emitir el número del papel—. Se intentó, y lo que
 * quedó es esto; el comentario se quedó atrás y mandó a perseguir un problema
 * que no existía.
 *
 * ═══ EL PRECIO SE DERIVA DEL VALOR, NO AL REVES ═══
 *
 * Praderas cobra por LOTE. El sistema modela por vara², así que el seeder
 * divide el valor entre el área con seis decimales. Por eso hace falta la
 * migración `precio_vara_con_seis_decimales`: con dos, el lote A-1 queda en
 * L 249,999.12 y el contrato diría un número que el papel no dice.
 *
 * ═══ ES IDEMPOTENTE ═══
 *
 * Un expediente que ya existe se saltea. Correrlo dos veces no duplica nada, y
 * agregar el número 13 a la lista no vuelve a cargar los doce anteriores.
 */
class CarteraHistoricaSeeder extends Seeder
{
    public function run(): void
    {
        $proyecto = Proyecto::query()->where('codigo', ExpedientesHistoricos::PROYECTO)->first();

        if (! $proyecto instanceof Proyecto) {
            $this->command?->error('No existe el proyecto '.ExpedientesHistoricos::PROYECTO.'. Importá el plano primero.');

            return;
        }

        if (! $this->revisarTodoAntesDeCargar($proyecto)) {
            return;
        }

        $cargados = 0;
        $salteados = 0;

        foreach (ExpedientesHistoricos::todos() as $datos) {
            $numero = (int) $datos['expediente'];

            if ($this->yaExiste($proyecto, $numero)) {
                $this->command?->line("   · Expediente {$this->folio($numero)} ya estaba cargado, se saltea.");
                $salteados++;

                continue;
            }

            $this->cargar($proyecto, $datos);
            $cargados++;

            $this->command?->info("   ✓ Expediente {$this->folio($numero)} — {$this->nombre($datos)}");
        }

        $this->reservarLosQueNoSeVenden($proyecto);
        $this->dejarLasSeriesDondeVan($proyecto);

        $this->command?->newLine();
        $this->command?->info("Cartera histórica: {$cargados} expedientes cargados, {$salteados} ya estaban.");
    }

    // ─── La revisión de antes de cargar ───────────────────────────────

    /**
     * Revisa los 110 expedientes ANTES de escribir una sola fila.
     *
     * ═══ POR QUE EXISTE ═══
     *
     * Sin esto el seeder muere en el PRIMER dato malo, y como cada corrida
     * borra y recarga, arreglar cinco problemas cuesta cinco vueltas
     * completas: cargar 34 expedientes, reventar, arreglar, borrar, repetir.
     * El 23-ago-2026 fueron cuatro vueltas seguidas —el lote 0, la referencia
     * de R11, el DNI de 14 dígitos— y cada una tapaba a la siguiente.
     *
     * Acá se revisa TODO y se listan TODOS los problemas juntos. Una vuelta.
     *
     * ⚠️ **Se revisa con los objetos de verdad, no con una copia de la regla.**
     * `DNI::desdeEntrada()` es el mismo que va a correr después: si mañana le
     * agregan una validación, esta revisión la hereda sola. Reescribir acá un
     * `preg_match` de trece dígitos habría dejado pasar el año de nacimiento,
     * que es exactamente lo que pasó.
     *
     * Devuelve false —y no lanza— para que la salida sea la lista de
     * problemas y no un stack trace de un solo renglón.
     */
    private function revisarTodoAntesDeCargar(Proyecto $proyecto): bool
    {
        $problemas = $this->seriesQueQuedaronAtras();

        foreach (ExpedientesHistoricos::todos() as $datos) {
            $exp = $this->folio((int) $datos['expediente']);

            foreach ($this->quejasDelExpediente($proyecto, $datos) as $queja) {
                $problemas[] = "   · Exp. {$exp}: {$queja}";
            }
        }

        if ($problemas === []) {
            return true;
        }

        $this->command?->error(count($problemas).' cosas que hay que arreglar en ExpedientesHistoricos ANTES de cargar:');
        $this->command?->newLine();

        foreach ($problemas as $problema) {
            $this->command?->line($problema);
        }

        $this->command?->newLine();
        $this->command?->warn('No se cargó nada. La base quedó como estaba.');

        return false;
    }

    /**
     * Una serie que quedó por DETRAS de las filas que ya existen.
     *
     * Si el correlativo global de recibos dice 0 y en la tabla ya hay un
     * recibo 207, la carga emite 1, 2, 3… y revienta al llegar a 207 —con
     * ochenta y seis expedientes adentro, que es donde reventó el 23-ago-2026.
     *
     * La causa está arreglada en `olympo:limpiar-cartera`, que ya no pone las
     * series globales en cero. Esto queda igual: es una consulta, corre antes
     * de escribir nada, y atrapa a cualquier otro camino que deje una serie
     * atrás — un `truncate` a mano, un restore parcial, lo que sea.
     *
     * @return list<string>
     */
    private function seriesQueQuedaronAtras(): array
    {
        $series = [
            'recibo_interno' => 'recibos',
            'devolucion'     => 'devoluciones',
            'gasto'          => 'gastos',
        ];

        $problemas = [];

        foreach ($series as $tipo => $tabla) {
            $mayor = (int) DB::table($tabla)->max('numero');

            $serie = DB::table('correlativos')
                ->whereNull('proyecto_id')
                ->where('tipo', $tipo)
                ->value('ultimo_numero');

            if ($serie !== null && (int) $serie < $mayor) {
                $problemas[] = "   · La serie de «{$tipo}» está en {$serie} y en «{$tabla}» ya existe el "
                    ."número {$mayor}: los que emita esta carga van a chocar. Corré "
                    .'`olympo:limpiar-cartera` de nuevo, que ahora la acomoda sola.';
            }
        }

        return $problemas;
    }

    /**
     * Todo lo que le encuentro a un expediente, junto.
     *
     * @param array<string, mixed> $datos
     *
     * @return list<string>
     */
    private function quejasDelExpediente(Proyecto $proyecto, array $datos): array
    {
        $quejas = [];

        /** @var array<string, string|null> $ficha */
        $ficha = is_array($datos['cliente'] ?? null) ? $datos['cliente'] : [];

        try {
            DNI::desdeEntrada($ficha['dni'] ?? null);
        } catch (ValueObjectInvalidoException $e) {
            $quejas[] = $e->getMessage();
        }

        $telefono = $ficha['telefono'] ?? null;

        if (is_string($telefono) && preg_match('/^[23789]\d{7}$/', $telefono) !== 1) {
            $quejas[] = "el teléfono «{$telefono}» no es un número hondureño válido.";
        }

        // R11, en la prima y en cada pago.
        foreach ($this->cobrosDelExpediente($datos) as $cobro) {
            if ($cobro['forma'] !== 'efectivo' && trim((string) $cobro['referencia']) === '') {
                $quejas[] = "{$cobro['donde']} es por {$cobro['forma']} y no trae referencia (R11).";
            }
        }

        /** @var list<array<string, mixed>> $declarados */
        $declarados = is_array($datos['lotes'] ?? null) ? $datos['lotes'] : [];

        /** @var list<array<string, mixed>> $pagos */
        $pagos = is_array($datos['pagos'] ?? null) ? $datos['pagos'] : [];

        /** @var list<string> $alContado */
        $alContado = [];

        foreach ($declarados as $fila) {
            $codigo = $fila['bloque'].'-'.$fila['numero'];

            /*
             * ⚠️ `lotes` NO tiene columna `bloque`: tiene `bloque_id`, y el
             * nombre vive en `bloques.nombre`. Es la misma consulta que hace
             * `lotes()` más abajo, y va copiada de ahí a propósito — escribirla
             * «como debería ser» costó una corrida entera con «column "bloque"
             * does not exist».
             */
            $existe = Lote::query()
                ->where('proyecto_id', $proyecto->getKey())
                ->where('numero', $fila['numero'])
                ->whereIn('bloque_id', DB::table('bloques')
                    ->where('proyecto_id', $proyecto->getKey())
                    ->where('nombre', $fila['bloque'])
                    ->pluck('id'))
                ->exists();

            if (! $existe) {
                $quejas[] = "el lote {$codigo} no está en el plano.";
            }
        }

        /*
         * Plazo 0 es AL CONTADO —lo dijo Mauricio el 23-ago-2026— y el sistema
         * lo soporta: `PlanDeCuotas` devuelve un plan vacío. Pero **solo si no
         * queda nada que financiar**: con saldo, cae en `porPlazoFijo()` y
         * revienta con «el plazo de 0 meses no es válido».
         *
         * Mordió con el lote I-3 del exp. 0049, que el cuaderno pone al
         * contado pero cuyo dinero entró después, por recibo.
         */
        foreach ($declarados as $fila) {
            if ((int) ($fila['plazo'] ?? -1) !== 0) {
                continue;
            }

            $suValor = new Monto((string) $fila['valor']);
            $suPrima = new Monto((string) ($fila['prima'] ?? '0'));

            if ($suPrima->menorQue($suValor)) {
                $quejas[] = "el lote {$fila['bloque']}-{$fila['numero']} dice contado (plazo 0) pero su "
                    ."prima L {$suPrima->valor} no cubre su valor L {$suValor->valor}: sin cuotas no hay "
                    .'dónde cobrar la diferencia.';
            }

            $alContado[] = $fila['bloque'].'-'.$fila['numero'];
        }

        /*
         * Un pago sin destino se reparte entre TODOS los lotes del contrato, y
         * un lote de contado no tiene ni una cuota: el dominio lo rebota con
         * «el lote RPS-G-005 no debe nada». Con lotes al contado, el destino
         * hay que escribirlo. Mordió con el exp. 0092.
         */
        if ($alContado !== []) {
            foreach ($pagos as $pago) {
                if (($pago['lote'] ?? null) === null && ($pago['lotes'] ?? null) === null) {
                    $quejas[] = "el pago del {$pago['fecha']} no dice a qué lote va, y el contrato tiene "
                        .implode(' y ', $alContado).' al contado, que no deben nada: escribí los lotes.';
                }
            }
        }

        return $quejas;
    }

    /**
     * La prima y los pagos, en una sola lista, para revisarlos igual.
     *
     * @param array<string, mixed> $datos
     *
     * @return list<array{donde: string, forma: string, referencia: string|null}>
     */
    private function cobrosDelExpediente(array $datos): array
    {
        $cobros = [[
            'donde'      => 'la prima',
            'forma'      => (string) ($datos['forma_prima'] ?? 'efectivo'),
            'referencia' => is_string($datos['ref_prima'] ?? null) ? $datos['ref_prima'] : null,
        ]];

        /** @var list<array<string, mixed>> $pagos */
        $pagos = is_array($datos['pagos'] ?? null) ? $datos['pagos'] : [];

        foreach ($pagos as $pago) {
            $cobros[] = [
                'donde'      => 'el pago del '.($pago['fecha'] ?? 'sin fecha'),
                'forma'      => (string) ($pago['forma'] ?? 'efectivo'),
                'referencia' => is_string($pago['referencia'] ?? null) ? $pago['referencia'] : null,
            ];
        }

        return $cobros;
    }

    // ─── Un expediente ────────────────────────────────────────────────

    /**
     * Carga una venta con todo su historial.
     *
     * ⚠️ El correlativo de recibos NO se toca acá: el sistema numera de
     * corrido y la serie se deja donde va al final, una sola vez. Ver
     * `config('lotificadora.cartera.proximo_recibo')`.
     *
     * @param array<string, mixed> $datos
     */
    private function cargar(Proyecto $proyecto, array $datos): void
    {
        $lotes = $this->lotes($proyecto, $datos);
        $cliente = $this->cliente($datos);
        $vendedor = $this->vendedor($datos);
        $fecha = CarbonImmutable::parse((string) $datos['fecha']);

        /*
         * El número de expediente ES el del contrato (R7), y lo consume
         * `activar()`. Se acomoda la serie para que salga el del cuaderno.
         */
        $this->ponerLaSerieEn(TipoCorrelativo::Contrato, (int) $datos['expediente'] - 1, $proyecto);

        $venta = resolve(RegistroDeVentas::class)->activar(
            proyecto: $proyecto,
            lotes: array_values($lotes),
            clientes: [$cliente],
            prima: new Monto((string) $datos['prima']),
            plazoMeses: (int) $datos['plazo'],
            diaPago: (int) $datos['dia_pago'],
            fechaContrato: $fecha,
            observaciones: is_string($datos['observaciones'] ?? null) ? $datos['observaciones'] : null,
            precios: $this->precios($lotes, $datos),
            formaPrima: $this->forma((string) ($datos['forma_prima'] ?? 'efectivo')),
            referenciaPrima: is_string($datos['ref_prima'] ?? null) ? $datos['ref_prima'] : null,
            vendedor: $vendedor,
        );

        /** @var list<array<string, mixed>> $pagos */
        $pagos = is_array($datos['pagos'] ?? null) ? $datos['pagos'] : [];

        foreach ($pagos as $pago) {
            $this->pagar($venta, $cliente, $lotes, $pago);
        }
    }

    /**
     * Un renglón del historial de pagos.
     *
     * @param array<string, Lote> $lotes
     * @param array<string, mixed> $pago
     */
    private function pagar(Venta $venta, Cliente $cliente, array $lotes, array $pago): void
    {
        $renglones = $this->renglones($venta, $lotes, $pago);
        $forma = $this->forma((string) $pago['forma']);
        $referencia = is_string($pago['referencia'] ?? null) ? $pago['referencia'] : null;
        $fecha = CarbonImmutable::parse((string) $pago['fecha']);
        $nota = is_string($pago['observaciones'] ?? null) ? $pago['observaciones'] : null;

        $servicio = resolve(RegistroDePagos::class);

        if (($pago['tipo'] ?? 'cuota') === 'abono') {
            /*
             * R21: un abono a capital reescribe el plan pendiente y exige
             * motivo. El motivo lo pone el seeder porque el cuaderno no lo
             * trae, y decir de dónde salió el dato es mejor que inventar uno.
             */
            $modalidad = ModalidadDeReprogramacion::from(ExpedientesHistoricos::MODALIDAD_DEL_ABONO);

            $servicio->abonarAVariosLotes(
                venta: $venta,
                cliente: $cliente,
                renglones: array_map(
                    static fn (array $renglon): array => [...$renglon, 'modalidad' => $modalidad],
                    $renglones,
                ),
                motivo: 'Abono a capital registrado en el cuaderno antes del sistema.',
                forma: $forma,
                referencia: $referencia,
                fecha: $fecha,
                observaciones: $nota,
            );

            return;
        }

        $servicio->cobrarVariosLotes(
            venta: $venta,
            cliente: $cliente,
            renglones: $renglones,
            forma: $forma,
            referencia: $referencia,
            fecha: $fecha,
            observaciones: $nota,
        );
    }

    // ─── Las piezas ───────────────────────────────────────────────────

    /**
     * El vendedor que anota el cuaderno, si anota alguno.
     *
     * ═══ EL NOMBRE SE NORMALIZA ANTES DE LLEGAR ACA ═══
     *
     * El cuaderno escribe «Jony Gerson García Melgar», «Jony Gerson García»,
     * «Jony García» y «Yoni García» para la misma persona. Los cuatro entran
     * al archivo de datos con la grafía completa —lo confirmó Mauricio el
     * 11-ago-2026— así que acá el `firstOrCreate` encuentra siempre la misma
     * fila y no hay cuatro vendedores donde hay uno.
     *
     * Sin vendedor la venta la cerró la lotificadora, que es el caso normal:
     * seis expedientes de setenta y seis traen nombre.
     *
     * @param array<string, mixed> $datos
     */
    private function vendedor(array $datos): ?Vendedor
    {
        $nombre = trim((string) ($datos['vendedor'] ?? ''));

        if ($nombre === '') {
            return null;
        }

        return Vendedor::query()->firstOrCreate(
            ['nombre' => $nombre],
            ['observaciones' => 'Registrado con la carga de la cartera anterior al sistema.'],
        );
    }

    /**
     * Los lotes del expediente, indexados por «bloque-numero».
     *
     * @param array<string, mixed> $datos
     *
     * @return array<string, Lote>
     */
    private function lotes(Proyecto $proyecto, array $datos): array
    {
        /** @var list<array<string, string>> $declarados */
        $declarados = is_array($datos['lotes'] ?? null) ? $datos['lotes'] : [];

        /*
         * 🔴 EL GUARD VA ANTES DEL BUCLE, Y NO ES ESTILO.
         *
         * Estaba después —`if ($encontrados === []) throw`— y **Rector lo
         * rompió**: `RemoveAlwaysTrueIfConditionRector` decidió que la
         * condición siempre era verdadera, se llevó el `if`, y
         * `RemoveUnreachableStatementRector` se llevó el `return` detrás. El
         * método quedó lanzando la excepción SIEMPRE, y el seeder entero dejó
         * de funcionar sin que ningún test lo notara.
         *
         * Preguntar por `$declarados` es además lo correcto: lo que se está
         * validando es que el DATO declare lotes, no que la consulta los
         * encuentre — eso ya lo cubre el `throw` de adentro del bucle.
         */
        if ($declarados === []) {
            throw new RuntimeException("El expediente {$datos['expediente']} no declara ningún lote.");
        }

        $encontrados = [];

        foreach ($declarados as $fila) {
            $clave = $fila['bloque'].'-'.$fila['numero'];

            $lote = Lote::query()
                ->where('proyecto_id', $proyecto->getKey())
                ->where('numero', $fila['numero'])
                ->whereIn('bloque_id', DB::table('bloques')
                    ->where('proyecto_id', $proyecto->getKey())
                    ->where('nombre', $fila['bloque'])
                    ->pluck('id'))
                ->first();

            if (! $lote instanceof Lote) {
                throw new RuntimeException("El expediente {$datos['expediente']} pide el lote {$clave}, que no existe en el plano.");
            }

            $encontrados[$clave] = $lote;
        }

        return $encontrados;
    }

    /**
     * 🔴 El precio por vara² SE DERIVA del valor del lote, con seis decimales.
     *
     * Praderas cobra por lote —el A-1 mide 252 vr² y cuesta lo mismo que uno de
     * 250—, así que el precio por vara² es el resultado de una división, no un
     * dato. Con dos decimales la cuenta no cierra: ver la migración
     * `precio_vara_con_seis_decimales`.
     *
     * @param array<string, Lote> $lotes
     * @param array<string, mixed> $datos
     *
     * @return list<PrecioPactado>
     */
    private function precios(array $lotes, array $datos): array
    {
        /** @var list<array<string, string>> $declarados */
        $declarados = is_array($datos['lotes'] ?? null) ? $datos['lotes'] : [];

        $precios = [];

        foreach ($declarados as $fila) {
            $lote = $lotes[$fila['bloque'].'-'.$fila['numero']];
            $area = (string) $lote->getAttribute('area_varas');

            if (! is_numeric($area) || (float) $area <= 0) {
                throw new RuntimeException("El lote {$fila['bloque']}-{$fila['numero']} no tiene área cargada.");
            }

            $valor = new Monto($fila['valor']);

            $precioVara = new Monto($valor->dividirPor($area)->redondeado(6));

            /*
             * 🔴 CUANDO HUBO DESCUENTO DE VERDAD, SON DOS PRECIOS.
             *
             * `valor_lista` es lo que el lote costaba; `valor` es lo que se
             * cobró. El exp. 0024 es el caso: L 250,000.00 de lista, L 40,000.00
             * de descuento autorizado por pago al contado, L 210,000.00 final.
             *
             * El de lista se escribe en la ficha del lote y el pactado va en la
             * venta. Así el sistema VE el descuento, lo mide contra algo real y
             * exige el motivo que R4 pide — que es exactamente lo que pasó.
             *
             * Sin `valor_lista`, los dos son el mismo y no hay descuento: es el
             * caso normal, donde cada lote se vende a su precio.
             */
            $deLista = is_string($fila['valor_lista'] ?? null)
                ? new Monto(new Monto($fila['valor_lista'])->dividirPor($area)->redondeado(6))
                : $precioVara;

            /*
             * 🔴 EL PRECIO DEL LOTE SE FIJA ACA, ANTES DE VENDER.
             *
             * Cada lote se vende a SU precio: eso lo dijo Mauricio el
             * 11-ago-2026 y lo confirmó el cruce contra el plano. El precio por
             * vara² no es un dato que alguien elija — es el resultado de
             * dividir lo que se cobró entre lo que mide.
             *
             * `ListaDePrecios::deListaPara()` toma el precio del PLAN cuando el
             * proyecto ofrece uno para ese plazo, y el del LOTE cuando no. Al
             * escribir acá el precio real del lote, vender a ese mismo precio
             * deja de ser un descuento y R4 no pide motivo — porque no hubo
             * descuento: hubo un precio negociado, que es otra cosa.
             *
             * ⚠️ Si el proyecto tiene un plan ACTIVO para ese plazo, ese plan
             * gana y el sistema volverá a ver descuento. Por eso
             * `olympo:limpiar-cartera --planes` los barre: un precio de lista
             * único no representa cómo esta lotificadora vende.
             */
            /*
             * `save()` y NO `saveQuietly()`: `Lote::booted()` recalcula el
             * `valor` en cada guardado, y saltarse el evento dejaría la ficha
             * del lote con el precio nuevo y el valor viejo.
             */
            $lote->forceFill(['precio_vara' => $deLista->redondeado(Lote::DECIMALES_DEL_PRECIO)])->save();

            /*
             * El motivo va SOLO si el dato lo trae. Un descuento de verdad —el
             * exp. 0024, L 40,000 por pago al contado— se declara en el
             * expediente y se explica. Inventar un motivo para todos los demás
             * llenaría la bitácora de descuentos que nunca existieron.
             */
            /*
             * 🔴 LA PRIMA PUEDE SER DE ESTE LOTE Y NO DEL CONTRATO.
             *
             * Null es «repartime la del contrato en proporción al valor», que
             * es el caso normal y el que sirvió para los primeros 24
             * expedientes. Pero el cuaderno también lleva expedientes donde
             * cada lote pactó SU prima: el exp. 0050 tiene el K-7 con
             * L 16,000.00 y el K-8 con L 10,000.00, escritos en renglones
             * distintos y con cuotas distintas —L 8,000.00 y L 5,000.00—.
             *
             * Sin esto, el reparto proporcional le daría al K-8 su parte de
             * los 16,000 y su cuota saldría de L 5,208.33 en lugar de los
             * L 5,000.00 que dice el papel. Y esa diferencia no es de una vez:
             * se repite en las 47 cuotas que faltan.
             */
            /*
             * 🔴 Y EL PLAZO TAMBIEN PUEDE SER DE ESTE LOTE.
             *
             * Va de la mano con la prima: cuando la prima de un lote cubre su
             * valor entero, ese lote se vendió AL CONTADO y su plazo es 0. El
             * exp. 0049 es el caso —los lotes I-1 e I-2 se pagaron enteros el
             * día de firmar, mientras el I-3 quedó a 48 meses—, y sin esto
             * `RegistroDeVentas` lo rechaza con razón: «la prima cubre el
             * valor completo, así que no queda saldo que financiar, pero se
             * pidieron 48 cuotas».
             */
            $precios[] = new PrecioPactado(
                loteId: (int) $lote->getKey(),
                precioVara: $precioVara,
                motivo: is_string($fila['motivo'] ?? null) ? $fila['motivo'] : null,
                plazoMeses: is_int($fila['plazo'] ?? null) ? $fila['plazo'] : null,
                prima: is_string($fila['prima'] ?? null) ? new Monto($fila['prima']) : null,
            );
        }

        return $precios;
    }

    /**
     * El cliente. Se busca por DNI y, si no hay DNI, por nombre exacto: los
     * nombres van en MAYUSCULAS por mutador, así que la comparación es estable.
     *
     * @param array<string, mixed> $datos
     */
    private function cliente(array $datos): Cliente
    {
        /** @var array<string, string|null> $ficha */
        $ficha = is_array($datos['cliente'] ?? null) ? $datos['cliente'] : [];

        $dni = $ficha['dni'] ?? null;
        $nombre = (string) ($ficha['nombre'] ?? '');

        $existente = is_string($dni) && trim($dni) !== ''
            ? Cliente::query()->where('dni', $dni)->first()
            : Cliente::query()->where('nombre', mb_strtoupper($nombre, 'UTF-8'))->first();

        if ($existente instanceof Cliente) {
            return $existente;
        }

        /** @var Cliente $creado */
        $creado = Cliente::query()->create([
            'nombre'        => $nombre,
            'dni'           => $dni,
            'telefono'      => $ficha['telefono'] ?? null,
            'activo'        => true,
            'observaciones' => 'Cliente de la cartera anterior al sistema.',
        ]);

        return $creado;
    }

    /**
     * Entre qué lotes se reparte este pago, y cuánto le toca a cada uno.
     *
     * ═══ POR QUE EL REPARTO NO SE DECLARA EN EL DATO ═══
     *
     * Porque el cuaderno tampoco lo declara. Cuando el exp. 0025 anota «cuota
     * de julio L 10,000.00» sobre dos lotes de L 250,000.00 cada uno, nadie
     * escribió que fueron cinco mil por lote: se da por entendido. Pedirle a
     * quien transcribe que lo desglose es pedirle que invente una precisión que
     * el papel no tiene, y multiplicar por dos las chances de equivocarse.
     *
     * Así que el reparto es **proporcional al valor congelado de cada lote**,
     * que es como el propio sistema reparte la prima al firmar. Y el residuo del
     * redondeo va al último, para que la suma cierre al centavo: si se repartiera
     * a ciegas, tres lotes y L 10,000.00 dejarían un centavo suelto que después
     * hace que el saldo no cuadre contra el cuaderno.
     *
     * Declarar `'lote' => 'K-2'` sigue valiendo, y manda: es para el pago que
     * fue a un lote y no a la cuenta entera.
     *
     * @param array<string, Lote> $lotes
     * @param array<string, mixed> $pago
     *
     * @return list<array{lote: Compromiso, monto: Monto}>
     */
    private function renglones(Venta $venta, array $lotes, array $pago): array
    {
        $monto = new Monto((string) $pago['monto']);
        $clave = is_string($pago['lote'] ?? null) ? $pago['lote'] : null;

        /** @var list<Compromiso> $compromisos */
        $compromisos = Compromiso::query()
            ->where('venta_id', $venta->getKey())
            ->orderBy('id')
            ->get()
            ->all();

        if ($compromisos === []) {
            throw new RuntimeException($this->elPago($pago).' apunta a una venta sin lotes.');
        }

        /*
         * 🔴 VARIOS LOTES —PERO NO TODOS— EN UN SOLO RECIBO.
         *
         * El exp. 0051 lo trajo: el recibo 00000328 son L 15,000.00 que
         * cubren la cuota de julio de los lotes 1, 2 y 11 del bloque J —cinco
         * mil cada uno—, y los otros tres lotes del contrato no se tocan.
         *
         * Sin esto habría que elegir entre repartir los 15,000 entre los SEIS
         * lotes (y dejar seis saldos equivocados) o partir el recibo en tres
         * (y inventar dos números de talonario que no existen).
         *
         * ═══ LA CLAVE `lotes` ADMITE DOS FORMAS ═══
         *
         *   ['J-1', 'J-2', 'J-11']                → CUALES lotes. El monto se
         *                                           reparte entre ESOS, igual
         *                                           que se repartiría entre
         *                                           todos si no se dijera nada.
         *   ['J-1' => '5000.00', 'J-2' => '…']    → CUANTO a cada uno.
         *
         * La primera es la que sale del cuaderno y la que escribe
         * `convertir_cartera.py`: la columna «¿A qué lote?» dice «1 y 14 N» y
         * nada más. La segunda queda para el día que el papel desglose montos
         * distintos por lote — y entonces manda sobre el reparto.
         *
         * 🔴 Una lista NO es un mapa: `foreach` sobre `['J-1','J-2']` entrega
         * las claves 0 y 1. Cargarla por la puerta del mapa daba «El recibo
         * 385 apunta al lote 0, que no está en la venta».
         */
        $porLote = is_array($pago['lotes'] ?? null) && $pago['lotes'] !== [] ? $pago['lotes'] : null;

        // ── Forma 1: el cuaderno dice cuáles, y el reparto sale del valor.
        if ($porLote !== null && array_is_list($porLote)) {
            $elegidos = [];

            foreach ($porLote as $codigo) {
                $elegidos[] = $this->compromisoDe($pago, $lotes, $compromisos, (string) $codigo);
            }

            return count($elegidos) === 1
                ? [['lote' => $elegidos[0], 'monto' => $monto]]
                : $this->repartir($monto, $elegidos);
        }

        // ── Forma 2: el cuaderno dice cuánto a cada uno.
        if ($porLote !== null) {
            $renglones = [];

            foreach ($porLote as $codigo => $cuanto) {
                $renglones[] = [
                    'lote'  => $this->compromisoDe($pago, $lotes, $compromisos, (string) $codigo),
                    'monto' => new Monto((string) $cuanto),
                ];
            }

            return $renglones;
        }

        // Un lote declarado: todo va ahí.
        if ($clave !== null) {
            return [['lote' => $this->compromisoDe($pago, $lotes, $compromisos, $clave), 'monto' => $monto]];
        }

        if (count($compromisos) === 1) {
            return [['lote' => $compromisos[0], 'monto' => $monto]];
        }

        return $this->repartir($monto, $compromisos);
    }

    /**
     * El renglón de la venta que corresponde a un código «bloque-numero».
     *
     * @param array<string, mixed> $pago
     * @param array<string, Lote> $lotes
     * @param list<Compromiso> $compromisos
     */
    private function compromisoDe(array $pago, array $lotes, array $compromisos, string $codigo): Compromiso
    {
        $buscado = isset($lotes[$codigo]) ? (int) $lotes[$codigo]->getKey() : 0;

        foreach ($compromisos as $compromiso) {
            if ((int) $compromiso->getAttribute('lote_id') === $buscado) {
                return $compromiso;
            }
        }

        throw new RuntimeException($this->elPago($pago)." apunta al lote {$codigo}, que no está en la venta.");
    }

    /**
     * El reparto proporcional, con el residuo al último renglón.
     *
     * @param list<Compromiso> $compromisos
     *
     * @return list<array{lote: Compromiso, monto: Monto}>
     */
    private function repartir(Monto $monto, array $compromisos): array
    {
        $total = Monto::cero();

        foreach ($compromisos as $compromiso) {
            $total = $total->sumar($this->valorDe($compromiso));
        }

        if ($total->esCero()) {
            throw new RuntimeException('Los lotes de la venta suman cero: no hay contra qué repartir.');
        }

        $renglones = [];
        $repartido = Monto::cero();
        $ultimo = count($compromisos) - 1;

        foreach ($compromisos as $i => $compromiso) {
            $suyo = $i === $ultimo
                ? $monto->restar($repartido)
                : new Monto($monto->multiplicarPor($this->valorDe($compromiso)->valor)->dividirPor($total->valor)->redondeado());

            $repartido = $repartido->sumar($suyo);
            $renglones[] = ['lote' => $compromiso, 'monto' => $suyo];
        }

        return $renglones;
    }

    private function valorDe(Compromiso $compromiso): Monto
    {
        $valor = $compromiso->getAttribute('valor');

        return new Monto(is_string($valor) || is_int($valor) ? $valor : '0');
    }

    // ─── Los correlativos ─────────────────────────────────────────────

    /**
     * Deja `ultimo_numero` donde haga falta para que el siguiente consumo
     * entregue el número que quiere el cuaderno.
     *
     * La fila se crea si no existe, igual que hace `ConsumoDeCorrelativos`: la
     * primera venta de un proyecto nuevo no encuentra serie.
     */
    private function ponerLaSerieEn(TipoCorrelativo $tipo, int $numero, ?Proyecto $proyecto): void
    {
        $proyectoId = $proyecto instanceof Proyecto ? (int) $proyecto->getKey() : null;

        $consulta = DB::table('correlativos')->where('tipo', $tipo->value);

        /*
         * `where('proyecto_id', null)` genera `= NULL`, que en SQL no es
         * verdadero nunca. Las series globales necesitan `IS NULL` — la misma
         * razón que está escrita en `ConsumoDeCorrelativos::bloquear()`.
         */
        $consulta = $proyectoId === null
            ? $consulta->whereNull('proyecto_id')
            : $consulta->where('proyecto_id', $proyectoId);

        $fila = $consulta->first();

        if ($fila === null) {
            DB::table('correlativos')->insert([
                'proyecto_id'   => $proyectoId,
                'tipo'          => $tipo->value,
                'ultimo_numero' => $numero,
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);

            return;
        }

        $datos = (array) $fila;

        DB::table('correlativos')
            ->where('id', (int) ($datos['id'] ?? 0))
            ->update(['ultimo_numero' => $numero, 'updated_at' => now()]);
    }

    /**
     * Saca del mercado los lotes que la lotificadora guardó para alguien.
     *
     * Va DESPUES de cargar los expedientes a propósito: si un lote reservado
     * apareciera además vendido en el cuaderno, la venta manda y el aviso lo
     * dice. Al revés —reservar primero— la venta se caería con un error que
     * parecería un bug del seeder.
     *
     * No usa un Service porque no hay ninguno: reservar no es un compromiso
     * con nadie, es la lotificadora marcando su propio inventario. El día que
     * eso se haga desde la pantalla, este método se cambia por esa llamada.
     */
    private function reservarLosQueNoSeVenden(Proyecto $proyecto): void
    {
        foreach (ExpedientesHistoricos::RESERVADOS as $grupo => $datos) {
            $reservados = 0;
            $ocupados = [];

            foreach ($datos['lotes'] as $codigo) {
                [$bloque, $numero] = explode('-', $codigo, 2);

                $lote = Lote::query()
                    ->where('proyecto_id', $proyecto->getKey())
                    ->where('numero', $numero)
                    ->whereIn('bloque_id', DB::table('bloques')
                        ->where('proyecto_id', $proyecto->getKey())
                        ->where('nombre', $bloque)
                        ->pluck('id'))
                    ->first();

                if (! $lote instanceof Lote) {
                    throw new RuntimeException("El lote reservado {$codigo} no existe en el plano.");
                }

                $estado = $lote->getAttribute('estado');

                if ($estado instanceof EstadoLote && $estado->estaComprometido()) {
                    $ocupados[] = $codigo;

                    continue;
                }

                $lote->forceFill([
                    'estado'        => EstadoLote::Reservado,
                    'observaciones' => $datos['motivo'],
                ])->save();

                $reservados++;
            }

            $this->command?->info("   ✓ {$reservados} lotes reservados — {$grupo}.");

            if ($ocupados !== []) {
                $this->command?->warn('   ⚠️  Estos ya estaban vendidos o apartados y NO se reservaron: '
                    .implode(', ', $ocupados).'.');
            }
        }
    }

    /**
     * Al terminar, las series quedan donde tienen que quedar.
     *
     * La de CONTRATOS, en el expediente más alto del cuaderno: el próximo que
     * se abra sigue la cuenta.
     *
     * La de RECIBOS, en el número que se le diga — no en el que dejó la carga.
     * Sin eso, el primer recibo que emita alguien en producción repetiría un
     * número que ya está impreso y entregado en papel.
     */
    private function dejarLasSeriesDondeVan(Proyecto $proyecto): void
    {
        $expedientes = array_map(
            static fn (array $datos): int => (int) $datos['expediente'],
            ExpedientesHistoricos::todos(),
        );

        if ($expedientes !== []) {
            $this->ponerLaSerieEn(TipoCorrelativo::Contrato, max($expedientes), $proyecto);
        }

        /*
         * 🔴 EL CORRELATIVO DE RECIBOS SE FIJA ACA, UNA SOLA VEZ, Y ES LO QUE
         * EVITA REPETIRLE UN NUMERO A LA CONTRATANTE.
         *
         * La carga histórica numeró de corrido —1, 2, 3…— porque los recibos
         * viejos no llevan el número del talonario. Si la serie quedara ahí, el
         * primer recibo que el sistema emita en producción llevaría un número
         * que ella YA entregó en papel, y habría dos documentos distintos con
         * el mismo número.
         *
         * `OLYMPO_PROXIMO_RECIBO` es el próximo número en blanco de su talonario. La
         * serie se deja en ese menos uno, así el primero que salga es
         * exactamente ese.
         *
         * En null no se toca nada: sirve para probar en local. Antes de
         * producción hay que ponerlo — `olympo:verificar-produccion` no lo
         * revisa todavía.
         */
        /*
         * ⚠️ VIENE DE `config()` Y NO DE UNA CONSTANTE, Y NO ES ESTILO.
         *
         * Estuvo como `ExpedientesHistoricos::PROXIMO_RECIBO = null` y PHPStan
         * tenía razón en rechazarlo: una constante de clase se resuelve en el
         * análisis, así que `!== null` era siempre falso y todo este bloque era
         * código muerto que nadie iba a ejecutar nunca. El aviso amarillo
         * habría salido en producción igual que en local.
         *
         * Y de paso queda donde va: el número del talonario es una
         * configuración del servidor, no un dato del cuaderno.
         */
        $proximo = config('lotificadora.cartera.proximo_recibo');

        if (is_int($proximo) && $proximo > 0) {
            $this->ponerLaSerieEn(TipoCorrelativo::ReciboInterno, $proximo - 1, null);

            $this->command?->line("   · El próximo recibo del sistema será el {$proximo}.");

            return;
        }

        $this->command?->warn('   ⚠️  El correlativo de recibos quedó donde lo dejó la carga. '
            .'Antes de producción hay que poner OLYMPO_PROXIMO_RECIBO en el .env.');
    }

    // ─── Interno ──────────────────────────────────────────────────────

    private function yaExiste(Proyecto $proyecto, int $expediente): bool
    {
        return Venta::query()
            ->where('proyecto_id', $proyecto->getKey())
            ->where('numero_expediente', $expediente)
            ->exists();
    }

    private function forma(string $valor): FormaDePago
    {
        $forma = FormaDePago::tryFrom($valor);

        if (! $forma instanceof FormaDePago) {
            throw new RuntimeException("«{$valor}» no es una forma de pago que el sistema conozca.");
        }

        return $forma;
    }

    private function folio(int $numero): string
    {
        return str_pad((string) $numero, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Cómo se nombra un pago del cuaderno cuando hay que reclamarle algo.
     *
     * `recibo` es el número TAL COMO SE ESCRIBE —«00000053», «0049»,
     * «0075-1»—, con sus ceros, porque los ceros distinguen los dos
     * talonarios. Y puede faltar: el cuaderno tiene renglones sin numerar.
     * Sin esto el mensaje quedaba «El recibo  apunta al lote J-2», que no dice
     * cuál de los ciento y pico.
     *
     * @param array<string, mixed> $pago
     */
    private function elPago(array $pago): string
    {
        $numero = $pago['recibo'] ?? null;

        if (is_string($numero) && trim($numero) !== '') {
            return "El recibo {$numero}";
        }

        return 'El pago del '.($pago['fecha'] ?? 'sin fecha')
            .' por L '.number_format((float) ($pago['monto'] ?? 0), 2);
    }

    /**
     * @param array<string, mixed> $datos
     */
    private function nombre(array $datos): string
    {
        /** @var array<string, string|null> $ficha */
        $ficha = is_array($datos['cliente'] ?? null) ? $datos['cliente'] : [];

        return (string) ($ficha['nombre'] ?? 'sin nombre');
    }
}
