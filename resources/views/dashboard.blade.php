@extends('layout')
@section('title','Resumen')
@section('content')
<div class="dashboard-workspace {{ $section==='maintenance'?'maintenance-workspace':'' }}">
<header class="page-heading dashboard-heading">@if($section!=='overview')<a class="dashboard-back" href="{{ $backUrl }}" aria-label="{{ $backLabel }}" title="{{ $backLabel }}">←</a>@endif<h1>{{ ['overview'=>'Resumen','projects'=>'Proyectos del período','maintenance'=>'Mantenimientos del período'][$section] }}</h1><span class="date-label">{{ now()->timezone('America/La_Paz')->format('d/m/Y') }}</span></header>


<form method="GET" action="{{ route('dashboard') }}" class="card dashboard-filters">
@if(isset($filters['parent']))<input type="hidden" name="parent" value="{{ $filters['parent'] }}">@endif
@if($section!=='overview')<input type="hidden" name="view" value="{{ $section }}">@foreach($overviewFilters as $key=>$value)<input type="hidden" name="overview[{{ $key }}]" value="{{ $value }}">@endforeach @endif
@if(request('state'))<input type="hidden" name="state" value="{{ request('state') }}">@endif
@foreach(['from'=>'Desde','to'=>'Hasta'] as $key=>$label)
<div class="dashboard-date" x-data="kardexCalendar(@js($$key), @js($availableDates))" @click.outside="open=false" @keydown.escape.window="open=false">
<label>{{ $label }}</label><input type="hidden" name="{{ $key }}" :value="value"><button type="button" class="calendar-trigger button secondary" @click="open=!open" :aria-expanded="open" aria-label="Seleccionar fecha {{ strtolower($label) }}"><x-icon name="calendar"/><span x-text="formatted"></span></button>
<div class="calendar-panel" x-show="open" x-cloak><div class="calendar-heading"><button type="button" class="button secondary compact" @click="move(-1)" aria-label="Mes anterior">‹</button><strong x-text="monthLabel"></strong><button type="button" class="button secondary compact" @click="move(1)" aria-label="Mes siguiente">›</button></div><div class="calendar-days"><template x-for="name in ['L','M','X','J','V','S','D']"><span x-text="name"></span></template><template x-for="(day,index) in days" :key="index"><button type="button" :disabled="!day || !available.includes(day.key)" :class="{'selected':day && value===day.key}" @click="value=day.key;open=false" x-text="day ? day.number : ''" :aria-label="day ? day.key : 'Sin día'"></button></template></div><small>Solo se pueden elegir días con Kardex.</small></div>
</div>
@endforeach
@if($role==='admin')<label>Entidad<select onchange="this.form.querySelectorAll('input[name=from],input[name=to]').forEach(input => input.disabled=true);this.form.requestSubmit()" name="client_id"><option value="">Todas las entidades</option>@foreach($clients as $client)<option value="{{ $client->id }}" @selected(request('client_id')==$client->id)>{{ $client->name }}</option>@endforeach</select></label>@endif
<div class="dashboard-project-controls"><label>Proyecto<select onchange="this.form.querySelectorAll('input[name=from],input[name=to]').forEach(input => input.disabled=true);this.form.requestSubmit()" name="project_id"><option value="">Todos los proyectos</option>@foreach($projects as $project)<option value="{{ $project->id }}" @selected(request('project_id')==$project->id)>{{ $project->name }}</option>@endforeach</select></label><div class="dashboard-filter-actions"><button class="button" type="submit">Aplicar</button><a class="button secondary" href="{{ route('dashboard') }}">Hoy</a></div></div>
@if($role!=='tecnico')<label>Técnico<select onchange="this.form.querySelectorAll('input[name=from],input[name=to]').forEach(input => input.disabled=true);this.form.requestSubmit()" name="technician_id"><option value="">Todos los técnicos</option>@foreach($technicians as $technician)<option value="{{ $technician->id }}" @selected(request('technician_id')==$technician->id)>{{ $technician->name }}</option>@endforeach</select></label>@endif

<p class="muted small dashboard-filter-note">{{ $role==='tecnico' ? 'Tus mantenimientos' : ($role==='admin' ? 'Todos los proyectos' : 'Tus proyectos asignados') }} · Según la fecha del mantenimiento. Al cambiar los filtros se actualizan los días disponibles.</p>
</form>
@php($stateLabels=['borrador'=>'Borradores','revision'=>'En revisión','observado'=>'Observados','finalizado'=>'Finalizados'])
@php($projectStates=in_array($role,['admin','supervisor']) ? ['revision','observado','borrador','finalizado'] : ['observado','borrador','revision','finalizado'])
@if($section==='overview')
<div class="stats dashboard-stats">
<div class="stat"><span>Equipos atendidos</span><strong>{{ $equipmentCount }}</strong><small>Equipos distintos del período</small></div>
<div class="stat"><span>Mantenimientos</span><strong><a href="{{ route('dashboard',[...$filters,'view'=>'maintenance']) }}" aria-label="Ver todos los mantenimientos">{{ $totalCount }}</a></strong><small>Registros del período</small></div>
@foreach($stateLabels as $state=>$label)<a class="stat {{ $state==='finalizado'?'accent':'' }}" href="{{ route('dashboard',[...$filters,'view'=>'maintenance','state'=>$state]) }}"><span>{{ $label }}</span><strong>{{ $counts[$state]??0 }}</strong></a>@endforeach
</div>
@endif
@if($section!=='maintenance')
<section class="dashboard-list-section" aria-labelledby="dashboard-projects-heading">
<div class="section-heading"><h2 id="dashboard-projects-heading">{{ $section==='overview'?'Proyectos del período':'Todos los proyectos del período' }}</h2>@if($role==='admin')<a class="button secondary compact" href="{{ route('administration',['tab'=>'proyectos']) }}"><x-icon name="plus"/>Crear proyecto</a>@endif@if($section==='overview' && $projectCount>0)<a class="link" href="{{ route('dashboard',[...$filters,'view'=>'projects']) }}">Ver todos ({{ $projectCount }}) →</a>@endif</div>
<div class="card dashboard-list">
@forelse($summary as $row)
<a class="dashboard-project-row {{ $section==='projects' ? 'dashboard-project-breakdown' : '' }}" href="{{ route('dashboard',[...$filters,'view'=>'maintenance','project_id'=>$row['project']->id,'parent'=>route('dashboard',request()->query(),false)]) }}">
<div class="dashboard-row-title"><strong>{{ $row['project']->name }}</strong><small>{{ $row['project']->client->name }} · {{ $row['total'] }} {{ $row['total']===1?'mantenimiento':'mantenimientos' }}</small></div>
@if($section==='projects')<div class="dashboard-row-states">@foreach($projectStates as $state)@if($row[$state])<span class="badge {{ $state }}"><strong class="project-state-value">{{ $row[$state] }}</strong><span class="project-state-name">{{ $stateLabels[$state] }}</span></span>@endif @endforeach</div>@endif<span class="dashboard-row-arrow" aria-hidden="true">›</span>
</a>
@empty<div class="dashboard-list-empty">{{ $from===$today && $to===$today ? 'Hoy no hay mantenimientos registrados.' : 'No hay mantenimientos en el período seleccionado.' }}</div>@endforelse
</div>
@if($projectPagination)@include('partials.dashboard-pagination',['pagination'=>$projectPagination])@endif
</section>
@endif
@if($section!=='projects')
<section class="dashboard-list-section maintenance-list-section" aria-labelledby="dashboard-maintenance-heading">
<div class="maintenance-list-toolbar"><div class="section-heading"><h2 id="dashboard-maintenance-heading">{{ $section==='overview'?'Kardex que requieren atención':'Todos los mantenimientos' }}</h2>@if($section==='overview' && $totalCount>0)<a class="link" href="{{ route('dashboard',[...$filters,'view'=>'maintenance']) }}">Ver todos ({{ $totalCount }}) →</a>@endif</div>
@if($section==='maintenance')
<form method="GET" action="{{ route('dashboard') }}" class="dashboard-state-form">@foreach($filters as $key=>$value)@if(is_array($value))@foreach($value as $nestedKey=>$nestedValue)<input type="hidden" name="{{ $key }}[{{ $nestedKey }}]" value="{{ $nestedValue }}">@endforeach @else<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endif @endforeach<input type="hidden" name="view" value="maintenance"><label>Estado<select name="state" onchange="this.form.requestSubmit()"><option value="">Todos los estados</option>@foreach($stateLabels as $state=>$label)<option value="{{ $state }}" @selected(request('state')===$state)>{{ $label }}</option>@endforeach</select></label><noscript><button class="button">Filtrar</button></noscript></form>
@elseif($attentionCount>5)<p class="dashboard-list-note">Los 5 prioritarios de {{ $attentionCount }} por atender.</p>@endif
</div>
<div class="card dashboard-list">
@forelse($interventions as $i)
<a class="dashboard-maintenance-row" href="{{ route('interventions.show',['intervention'=>$i,'return_to'=>route('dashboard',[...$filters,'view'=>$section,'state'=>request('state'),'page'=>request('page')],false)]) }}"><div class="dashboard-row-title"><strong>{{ $i->asset->kardex_code }} / {{ sprintf('%03d',$i->number) }} <span class="dashboard-equipment-name">· {{ $i->asset->equipment->name }}</span></strong><small>{{ $i->asset->project->name }} · {{ $i->date->format('d/m/Y') }}</small></div><span class="badge {{ $i->status }}">{{ $i->status_label }}</span><span class="dashboard-row-arrow" aria-hidden="true">›</span></a>
@empty<div class="dashboard-list-empty">{{ $section==='overview' ? 'No hay Kardex que requieran atención en este período.' : 'No hay mantenimientos para estos filtros.' }}</div>@endforelse
</div>
@if($maintenancePagination)@include('partials.dashboard-pagination',['pagination'=>$maintenancePagination])@endif
</section>
@endif
</div>
@endsection
