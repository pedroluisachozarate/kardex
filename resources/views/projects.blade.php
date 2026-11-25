@extends('layout')
@section('title',auth()->user()->role==='admin' ? 'Proyectos' : 'Mis proyectos')
@section('content')
<div class="projects-overview">
<header class="page-heading dashboard-heading"><h1>{{ auth()->user()->role==='admin' ? 'Proyectos' : 'Mis proyectos' }}</h1>@if(auth()->user()->role==='admin')<a class="button" href="{{ route('administration',['tab'=>'proyectos']) }}"><x-icon name="plus"/>Crear proyecto</a>@endif</header>
<form method="GET" action="{{ route('projects.index') }}" class="projects-search" role="search"><input type="search" name="q" value="{{ $search }}" oninput="if (!this.value && this.defaultValue) this.form.requestSubmit()" placeholder="Buscar entidad o proyecto…" aria-label="Buscar por entidad o proyecto" maxlength="120"><button type="submit" class="button">Buscar</button></form>
<p class="projects-overview-note">{{ $projects->total() }} {{ $projects->total()===1?'proyecto':'proyectos' }} · Historial completo</p>
@php($stateLabels=['borrador'=>'Borradores','revision'=>'En revisión','observado'=>'Observados','finalizado'=>'Finalizados'])
<div class="card dashboard-list">
@forelse($projects as $project)
@php($counts=$pendingCounts->get($project->id))
<a class="dashboard-project-row dashboard-project-breakdown" href="{{ route('projects.show',$project) }}">
<div class="dashboard-row-title"><strong>{{ $project->name }}</strong><small>{{ $project->client->name }} · {{ $counts->total??0 }} {{ ($counts->total??0)==1?'mantenimiento':'mantenimientos' }}</small></div>
@if(($counts->total??0)>0)<div class="dashboard-row-states">@foreach($stateLabels as $state=>$label)@if(($counts->$state??0)>0)<span class="badge {{ $state }}"><strong class="project-state-value">{{ $counts->$state }}</strong><span class="project-state-name">{{ $label }}</span></span>@endif @endforeach</div>@endif<span class="dashboard-row-arrow" aria-hidden="true">›</span>
</a>
@empty<div class="card empty">@if($search!=='')No hay proyectos que coincidan con «{{ $search }}».@else{{ auth()->user()->role==='admin' ? 'Aún no hay proyectos registrados.' : 'Todavía no tienes proyectos asignados.' }}@endif</div>@endforelse
</div>
@include('partials.dashboard-pagination',['pagination'=>$projects])
</div>
@endsection
