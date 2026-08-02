@extends('errors.layout')

@section('codigo', '429')
@section('titulo', 'Demasiadas solicitudes')
@section('mensaje', 'Has hecho demasiadas solicitudes en poco tiempo. Espera unos segundos antes de volver a intentar.')

@section('acciones')
    <a href="{{ url('/') }}" class="btn btn-primary">Ir al inicio</a>
@endsection
