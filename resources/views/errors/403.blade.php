@extends('layout')
@section('title','Acceso restringido')
@section('content')<div class="card"><p class="eyebrow">ACCESO RESTRINGIDO</p><h1>No tienes permiso para esta acción.</h1><p class="muted">Consulta al administrador sobre tu rol y los proyectos asignados.</p><a class="button" href="{{ route('dashboard') }}"><x-icon name="back"/>Volver al resumen</a></div>@endsection
