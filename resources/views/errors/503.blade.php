@extends('errors.layout')

@section('codigo', '503')
@section('titulo', 'Sistema en mantenimiento')
@section('mensaje', 'Estamos realizando tareas de mantenimiento programado. El sistema estará disponible en unos minutos. Gracias por tu paciencia.')

@section('acciones')
    <a href="javascript:location.reload()" class="btn btn-primary">Reintentar</a>
@endsection
