@extends('errors.layout')

@section('codigo', '403')
@section('titulo', 'Acceso denegado')
@section('mensaje', 'No tienes permisos suficientes para acceder a esta sección. Si crees que es un error, contacta al administrador.')

@section('acciones')
    <a href="{{ url('/') }}" class="btn btn-primary">Ir al inicio</a>
    <a href="javascript:history.back()" class="btn btn-secondary">Volver atrás</a>
@endsection
