@extends('layout.app')

@section('titulo', 'Configuración')

@section('contenido')
    <x-landing titulo="Configuración" icono="sliders"
               desc="Cómo está armado el salón: cómo se ve el sistema, los locales y por dónde te contactan."
               :subs="$subs" />
@endsection
