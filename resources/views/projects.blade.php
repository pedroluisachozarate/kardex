@extends('layout')
@section('title',auth()->user()->role==='admin' ? 'Proyectos' : 'Mis proyectos')
@section('content')
<header class="page-heading dashboard-heading"><h1>{{ auth()->user()->role==='admin' ? 'Proyectos' : 'Mis proyectos' }}</h1>@if(auth()->user()->role==='admin')<a class="button" href="{{ route('administration',['tab'=>'proyectos']) }}"><x-icon name="plus"/>Crear proyecto</a>@endif</header>
<div class="project-grid">@forelse($projects as $project)<a class="card project-card" href="{{ route('projects.show',$project) }}"><span class="eyebrow">{{ $project->client->name }}</span><h3>{{ $project->name }}</h3><span class="link">Abrir proyecto →</span></a>@empty<div class="card empty"><p>{{ auth()->user()->role==='admin' ? 'Aún no hay proyectos registrados.' : 'Todavía no tienes proyectos asignados.' }}</p></div>@endforelse</div>
@endsection
