@extends('errors.layout')

@section('codigo', '404')
@section('titulo', 'Página no encontrada')
@section('mensaje', 'La página que buscas no existe o fue movida. Verifica la dirección o regresa al inicio.')

@section('acciones')
    <a href="{{ url('/') }}" class="btn btn-primary">Ir al inicio</a>
    <a href="javascript:history.back()" class="btn btn-secondary">Volver atrás</a>
@endsection
