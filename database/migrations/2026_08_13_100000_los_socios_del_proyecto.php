<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Quiénes son los dueños del PROYECTO, y qué parte le toca a cada uno.
 *
 * ═══ QUE LO PIDIO ═══
 *
 * Mauricio, el 13-ago-2026: «pueden ser dos propietarios, no de compra de un
 * lote sino del proyecto en sí; que haya uno de propietarios y porcentaje, e
 * ingrese los datos de los propietarios o socios y el porcentaje que le toca a
 * cada uno». Para saber cómo se reparte el dinero del mes.
 *
 * ═══ NO SON CLIENTES, Y NO SON VENDEDORES ═══
 *
 * Un cliente COMPRA un lote. Un socio es dueño de una parte del proyecto
 * entero: no tiene expediente, no tiene saldo, no aparece en el plano. Meterlo
 * en `clientes` lo metería en el formulario de ventas, en los reportes de
 * cartera y en el contador de clientes de la lotificadora — la misma razón por
 * la que el 12-ago el titular de recibo tampoco fue a `clientes`.
 *
 * ═══ POR QUE CUELGAN DEL PROYECTO ═══
 *
 * Porque el reparto es del proyecto. La plata entra por las ventas de SUS lotes
 * y sale por SUS gastos, y de ese neto sale lo de cada socio. Una misma persona
 * puede ser socia de dos proyectos con porcentajes distintos, así que el
 * porcentaje no es un atributo de la persona sino de su parte en ESE proyecto.
 *
 * Si algún día hace falta preguntar «¿cuánto le tocó a fulano en total?», eso
 * se resuelve agrupando por DNI o promoviendo la persona a su propia tabla.
 * Hoy sería inventar una relación que nadie pidió.
 *
 * ═══ LO QUE ESTA TABLA NO HACE ═══
 *
 * ⚠️ **No reparte nada todavía.** Guarda quién y cuánto; el corte mensual que
 * dice «a fulano le tocan L 47,000 de este mes» es el paso siguiente, y antes
 * de escribirlo hay que saber sobre QUE se reparte: ¿sobre lo cobrado del mes?
 * ¿neto de gastos? ¿los gastos de qué fecha, la del comprobante o la del pago?
 * Son preguntas de plata y contestarlas mal cuesta.
 *
 * ═══ EL 100% ES OBLIGATORIO, PERO NO LO PUEDE EXIGIR UN CHECK ═══
 *
 * Mauricio fue tajante: «siempre debe estar distribuido el 100, si no, no deja
 * guardar». Y así quedó — pero el que lo impide es el FORMULARIO, no la base, y
 * conviene saber por qué:
 *
 *   · Un CHECK mira UNA fila. La suma es de todas: no hay forma de escribirlo.
 *   · Un trigger diferido sí podría, pero solo cierra si todo el guardado ocurre
 *     dentro de UNA transacción. El repetidor de Filament guarda fila por fila,
 *     así que al reemplazar un socio del 50% por otro habría un instante con
 *     50% — y el trigger tumbaría un guardado correcto.
 *
 * Lo que la base SÍ garantiza es cada parte por separado: mayor que cero, no
 * más de 100, y entera o media. Lo que no puede, lo exige la pantalla y lo
 * volverá a exigir el reparto —que es donde de verdad importa: repartir con 90%
 * deja 10% sin dueño—.
 *
 * ⚠️ Un proyecto SIN socios cargados es válido: no es un reparto mal hecho, es
 * que no hay reparto. Exigirlo obligaría a conocer a los socios antes de poder
 * crear el proyecto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('socios', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('proyecto_id')->constrained('proyectos')->cascadeOnDelete();

            $table->string('nombre', 150);
            $table->string('dni', 13)->nullable();
            $table->string('telefono', 8)->nullable();
            $table->string('correo', 150)->nullable();

            /*
             * UN decimal, y solo enteros o medios. Regla de Mauricio
             * (13-ago-2026): «solo se pueden enteros o medios, ejemplo 0.5,
             * 10, 20.5, y así, que al final sumen 100 sin excepciones».
             *
             * Es una decisión de negocio y simplifica el reparto: con partes
             * de medio punto, tres socios se acomodan en 33.5 + 33.5 + 33 y
             * no hace falta un tercio periódico que después deje centavos
             * sueltos.
             */
            $table->decimal('porcentaje', 5, 1);

            $table->boolean('activo')->default(true);
            $table->text('observaciones')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['proyecto_id', 'activo']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE socios
                ADD CONSTRAINT socios_nombre_no_vacio_chk
                CHECK (btrim(nombre) <> ''),

                ADD CONSTRAINT socios_dni_valido_chk
                CHECK (dni IS NULL OR dni ~ '^[0-9]{13}$'),

                ADD CONSTRAINT socios_telefono_valido_chk
                CHECK (telefono IS NULL OR telefono ~ '^[23789][0-9]{7}$'),

                ADD CONSTRAINT socios_porcentaje_razonable_chk
                CHECK (porcentaje > 0 AND porcentaje <= 100),

                /*
                 * Enteros o medios: 0.5, 10, 20.5. Multiplicado por dos tiene
                 * que dar un entero. Va en la base y no solo en la pantalla
                 * porque un seeder o un import tampoco pueden meter 33.3.
                 */
                ADD CONSTRAINT socios_porcentaje_entero_o_medio_chk
                CHECK (porcentaje * 2 = trunc(porcentaje * 2))
        SQL);

        /*
         * La misma persona no puede figurar dos veces en el mismo proyecto: dos
         * renglones de 30% y 20% para el mismo socio son un error de carga que
         * despues nadie distingue de dos socios distintos.
         *
         * Por NOMBRE normalizado y no por DNI, porque el DNI es opcional. Y
         * parcial, para que un socio archivado no bloquee al que lo reemplaza.
         */
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX socios_uno_por_proyecto_uq
                ON socios (proyecto_id, lower(btrim(nombre)))
                WHERE deleted_at IS NULL
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('socios');
    }
};
