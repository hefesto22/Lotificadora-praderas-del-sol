@extends('errors.layout')

@section('codigo', '419')
@section('titulo', 'Sesión expirada')
@section('mensaje', 'Tu sesión expiró por inactividad. Por favor inicia sesión nuevamente.')

@section('acciones')
    <a href="{{ url('/login') }}" class="btn btn-primary">Iniciar sesión</a>
@endsection
