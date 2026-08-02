<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\ValueObjects\RTN;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Form Request base para Grupo Olympo.
 *
 * Centraliza las reglas de validación que se repiten en todo el
 * proyecto (RTN, montos, fechas históricas, teléfonos hondureños)
 * para evitar duplicación de conocimiento del dominio (§8.4.2).
 *
 * Uso típico:
 *
 *   final class StoreClienteRequest extends BaseFormRequest
 *   {
 *       public function rules(): array
 *       {
 *           return [
 *               'nombre' => ['required', 'string', 'max:255'],
 *               'rtn'    => $this->rtnRule(required: false),
 *               'limite_credito' => $this->montoRule(),
 *           ];
 *       }
 *   }
 */
abstract class BaseFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Regla de RTN hondureño — 14 dígitos numéricos.
     *
     * @return list<string>
     */
    protected function rtnRule(bool $required = true): array
    {
        return [
            $required ? 'required' : 'nullable',
            'string',
            'size:'.RTN::LONGITUD,
            'regex:'.RTN::REGEX,
        ];
    }

    /**
     * Regla para montos monetarios — no negativos, hasta 2 decimales.
     *
     * @return list<string>
     */
    protected function montoRule(bool $required = true, float $min = 0.0, ?float $max = null): array
    {
        $reglas = [
            $required ? 'required' : 'nullable',
            'numeric',
            'min:'.$min,
            'decimal:0,2',
        ];

        if ($max !== null) {
            $reglas[] = 'max:'.$max;
        }

        return $reglas;
    }

    /**
     * Regla para fechas históricas (no futuras, después del año 2000).
     *
     * @return list<string>
     */
    protected function fechaHistoricaRule(bool $required = true): array
    {
        return [
            $required ? 'required' : 'nullable',
            'date',
            'before_or_equal:today',
            'after:2000-01-01',
        ];
    }

    /**
     * Regla para teléfonos hondureños — 8 dígitos, con o sin código país.
     *
     * @return list<string>
     */
    protected function telefonoHondurasRule(bool $required = true): array
    {
        return [
            $required ? 'required' : 'nullable',
            'string',
            'regex:/^(\+?504[\s-]?)?[239][0-9]{7}$/',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            '*.required'        => 'El campo :attribute es obligatorio.',
            '*.regex'           => 'El campo :attribute tiene formato inválido.',
            '*.numeric'         => 'El campo :attribute debe ser numérico.',
            '*.min'             => 'El campo :attribute no puede ser menor a :min.',
            '*.max'             => 'El campo :attribute no puede ser mayor a :max.',
            '*.size'            => 'El campo :attribute debe tener exactamente :size caracteres.',
            '*.decimal'         => 'El campo :attribute admite máximo 2 decimales.',
            '*.before_or_equal' => 'El campo :attribute no puede ser una fecha futura.',
            '*.after'           => 'El campo :attribute debe ser posterior a :date.',
        ];
    }
}
