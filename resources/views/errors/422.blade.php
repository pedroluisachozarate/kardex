@extends('layout')
@section('title','Revisa el registro')
@section('content')<div class="card"><h1>Faltan datos para continuar.</h1><p>{{ $exception->getMessage() }}</p><a class="button" href="{{ url()->previous() }}"><x-icon name="back"/>Volver al registro</a></div>@endsection
