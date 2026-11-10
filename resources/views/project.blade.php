@extends('layout')
@section('title',$project->name)
@section('content')
<livewire:project-workspace :project="$project" />
@endsection
