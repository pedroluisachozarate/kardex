@extends('layout')
@section('title','Iniciar sesión')
@section('content')
<div class="login-card"><span class="brand-icon">K</span><p class="eyebrow">TU EQUIPO. CADA INTERVENCIÓN.</p><h1>Bienvenido a Kardex</h1><p class="muted">Gestiona el mantenimiento y sus evidencias en un solo lugar.</p><form method="POST" action="{{ route('login') }}">@csrf<label>Correo electrónico<input name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="username"></label><label>Contraseña<input name="password" type="password" required autocomplete="current-password"></label><button class="button full"><x-icon name="login"/>Ingresar</button></form><p class="muted small">Solicita tu cuenta al administrador del sistema.</p></div>
@endsection
