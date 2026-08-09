<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * El plano que se le manda al cliente por WhatsApp, y quien contesta.
 *
 * ═══ QUE ES ═══
 *
 * Una pagina publica —sin login, fuera del panel— con el plano del proyecto:
 * que lotes estan libres, cuanto miden, cuanto valen a cada plazo y cuanto
 * seria la cuota. El vendedor manda un link y el cliente lo abre en el
 * telefono. De ahi salen prospectos con nombre y telefono.
 *
 * ═══ 🔴 NACE APAGADO, Y ESO NO ES UN DEFAULT DE CONVENIENCIA ═══
 *
 * `plano_publico` arranca en `false`. Encender esta pagina publica **la lista
 * de precios completa de la lotificadora**, y eso lo tiene que decidir alguien
 * a proposito, no heredarlo de una migracion. La competencia tambien la puede
 * abrir.
 *
 * ═══ EL SLUG ES PARTE DEL LINK, ASI QUE NO SE REGENERA SOLO ═══
 *
 * Se calcula una vez a partir del nombre y despues **se edita a mano o no
 * cambia**. Un slug que se recalcula cada vez que alguien corrige una tilde
 * del nombre del proyecto rompe todos los links ya mandados por WhatsApp, y
 * nadie relaciona una cosa con la otra.
 *
 * ═══ POR QUE `prospectos` Y NO `clientes` ═══
 *
 * Un prospecto es alguien que dejo su telefono en una pagina publica. No
 * tiene DNI, no firmo nada y puede ser un numero equivocado o un bot. Un
 * `cliente` es alguien con expediente. Mezclarlos ensuciaria el padron con el
 * que se emiten contratos, y el dia que alguien busque «Maria» tendria que
 * adivinar cual de las dos es.
 *
 * Cuando el prospecto compra, se crea su cliente. La fila del prospecto queda
 * como la traza de por donde llego — que es justamente lo que la lotificadora
 * quiere saber para decidir si el plano publico sirve.
 *
 * ⚠️ Son DATOS PERSONALES de gente que no es cliente. La tabla va con su
 * policy y su permiso, y el receptor no la ve.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proyectos', function (Blueprint $table): void {
            // Nullable en este paso: se rellena abajo y recien despues se
            // exige. Con filas ya escritas no hay otra forma.
            $table->string('slug', 80)->nullable()->after('codigo');

            $table->boolean('plano_publico')->default(false)->after('activo');

            /*
             * El numero al que llegan las consultas de ESTE proyecto. Va en el
             * proyecto y no en la configuracion de la empresa porque dos
             * desarrollos pueden tener vendedores distintos — y porque el dia
             * que uno se venda entero, se apaga su plano sin tocar el otro.
             *
             * Sin numero cargado, la pagina se ve igual y no muestra el boton:
             * es preferible a mandar al cliente a un chat que nadie lee.
             */
            $table->string('whatsapp', 20)->nullable()->after('plano_publico');
        });

        $this->rellenarSlugs();

        Schema::table('proyectos', function (Blueprint $table): void {
            $table->string('slug', 80)->nullable(false)->change();
            $table->unique('slug');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE proyectos
                ADD CONSTRAINT proyectos_slug_con_forma_chk
                CHECK (slug ~ '^[a-z0-9]+(-[a-z0-9]+)*$')
        SQL);

        Schema::create('prospectos', function (Blueprint $table): void {
            $table->id();

            // Cascade: un prospecto no significa nada sin su proyecto.
            $table->foreignId('proyecto_id')->constrained('proyectos')->cascadeOnDelete();

            /*
             * nullOnDelete y no cascade: si el lote se borra, el prospecto
             * sigue siendo alguien a quien hay que llamar. Se pierde por cual
             * preguntaba, no el contacto.
             */
            $table->foreignId('lote_id')->nullable()->constrained('lotes')->nullOnDelete();

            $table->string('nombre', 120);
            $table->string('telefono', 20);
            $table->text('mensaje')->nullable();

            /*
             * El plazo que tenia elegido cuando escribio. Es la mitad de la
             * conversacion que la administradora va a tener: alguien que
             * miraba 48 meses no quiere lo mismo que alguien que miraba
             * contado. NULL si el proyecto no tenia planes cargados.
             */
            $table->unsignedSmallInteger('plazo_meses')->nullable();

            /*
             * Para el anti-spam y para saber si tres «prospectos» distintos
             * son la misma persona probando. No se muestra en pantalla.
             */
            $table->string('ip', 45)->nullable();

            // Que alguien ya lo llamo. Sin esto, la lista crece y nadie sabe
            // por donde iba.
            $table->timestamp('atendido_el')->nullable();
            $table->foreignId('atendido_por')->nullable()->constrained('users')->nullOnDelete();
            $table->text('nota')->nullable();

            $table->timestamps();

            $table->index(['proyecto_id', 'created_at']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE prospectos
                ADD CONSTRAINT prospectos_nombre_no_vacio_chk
                CHECK (btrim(nombre) <> ''),

                ADD CONSTRAINT prospectos_telefono_no_vacio_chk
                CHECK (btrim(telefono) <> ''),

                -- Marcar atendido sin decir quien deja una fila que no se
                -- puede auditar: van juntos o no van.
                ADD CONSTRAINT prospectos_atencion_completa_chk
                CHECK (
                    (atendido_el IS NULL AND atendido_por IS NULL)
                    OR (atendido_el IS NOT NULL AND atendido_por IS NOT NULL)
                )
        SQL);

        /*
         * El indice que hace rapida la unica pregunta que la administradora
         * hace todos los dias: «¿a quien me falta llamar?». Es PARCIAL, asi
         * que con los anios solo pesa lo pendiente y no el historico entero
         * — el mismo criterio que `cuotas_pendientes_idx`.
         */
        DB::statement(<<<'SQL'
            CREATE INDEX prospectos_sin_atender_idx
                ON prospectos (created_at)
             WHERE atendido_el IS NULL
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('prospectos');

        DB::statement('ALTER TABLE proyectos DROP CONSTRAINT IF EXISTS proyectos_slug_con_forma_chk');

        Schema::table('proyectos', function (Blueprint $table): void {
            $table->dropUnique(['slug']);
            $table->dropColumn(['slug', 'plano_publico', 'whatsapp']);
        });
    }

    /**
     * El slug de cada proyecto que ya existe, a partir de su nombre.
     *
     * Se hace en PHP y no en SQL porque `Str::slug()` sabe de tildes y de
     * la ñ, y un `lower(replace(...))` no: «RESIDENCIAL PRADERAS DEL SOL»
     * tiene que dar `residencial-praderas-del-sol` y no romperse el dia que
     * un proyecto se llame «LA CAÑADA».
     *
     * El codigo del proyecto desempata: dos desarrollos pueden llamarse
     * parecido y el slug es unico.
     */
    private function rellenarSlugs(): void
    {
        /** @var list<object{id: int, nombre: string, codigo: string}> $proyectos */
        $proyectos = DB::table('proyectos')->select(['id', 'nombre', 'codigo'])->get()->all();

        $usados = [];

        foreach ($proyectos as $proyecto) {
            $base = Str::slug($proyecto->nombre);

            if ($base === '') {
                $base = Str::slug($proyecto->codigo);
            }

            $slug = $base;

            if (in_array($slug, $usados, true)) {
                $slug = $base.'-'.Str::slug($proyecto->codigo);
            }

            $usados[] = $slug;

            DB::table('proyectos')->where('id', $proyecto->id)->update(['slug' => $slug]);
        }
    }
};
