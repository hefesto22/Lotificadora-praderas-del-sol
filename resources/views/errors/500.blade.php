@extends('errors.layout')

@section('codigo', '500')
@section('titulo', 'Algo salió mal')
@section('mensaje', 'Ocurrió un error inesperado en el servidor. El equipo técnico ha sido notificado y está trabajando para resolverlo.')

@section('acciones')
    <a href="{{ url('/') }}" class="btn btn-primary">Ir al inicio</a>
    <a href="javascript:location.reload()" class="btn btn-secondary">Reintentar</a>
@endsection
