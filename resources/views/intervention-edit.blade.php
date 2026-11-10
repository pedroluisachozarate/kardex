@extends('layout')
@section('title', 'Editar Kardex '.$i->asset->kardex_code)
@section('content')
<livewire:project-workspace :project="$i->asset->project" :intervention="$i" />
@endsection
