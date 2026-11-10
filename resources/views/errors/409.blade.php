@extends('layout')
@section('title','Registro protegido')
@section('content')<div class="card"><h1>Este registro cambió de estado.</h1><p>{{ $exception->getMessage() }}</p><a class="button" href="{{ url()->previous() }}"><x-icon name="back"/>Volver y actualizar</a></div>@endsection
