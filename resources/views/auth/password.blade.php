@extends('layout')
@section('title','Contraseña')
@section('content')
<div class="page-heading"><div><p class="eyebrow">MI CUENTA</p><h1>Actualizar contraseña</h1><p class="muted">Usa al menos 10 caracteres, letras y números.</p></div></div>
<form class="card narrow password-update-form" method="POST" action="{{ route('password.update') }}" data-password-validation novalidate>
@csrf
@if(session('password_updated'))<div hidden data-kardex-alert="success">{{ session('password_updated') }}</div>@endif
<label for="current-password">Contraseña actual</label><x-password-input id="current-password" name="current_password" autocomplete="current-password" aria-describedby="current-password-feedback" aria-invalid="{{ $errors->has('current_password')?'true':'false' }}" required />
<small id="current-password-feedback" class="password-feedback is-invalid" data-feedback="current_password" role="status" aria-live="polite" @if(!$errors->has('current_password')) hidden @endif>{{ $errors->first('current_password') }}</small>
<label for="new-password">Nueva contraseña</label><x-password-input id="new-password" name="password" minlength="10" autocomplete="new-password" aria-describedby="new-password-feedback" aria-invalid="{{ $errors->has('password')?'true':'false' }}" required />
<small id="new-password-feedback" class="password-feedback is-invalid" data-feedback="password" role="status" aria-live="polite" @if(!$errors->has('password')) hidden @endif>{{ $errors->first('password') }}</small>
<label for="confirm-password">Confirmar contraseña</label><x-password-input id="confirm-password" name="password_confirmation" minlength="10" autocomplete="new-password" aria-describedby="confirm-password-feedback" aria-invalid="{{ $errors->has('password_confirmation')?'true':'false' }}" required />
<small id="confirm-password-feedback" class="password-feedback is-invalid" data-feedback="password_confirmation" role="status" aria-live="polite" @if(!$errors->has('password_confirmation')) hidden @endif>{{ $errors->first('password_confirmation') }}</small>
<div class="password-form-actions"><button class="button" type="submit"><x-icon name="save"/>Actualizar Contraseña</button></div>
</form>
<script src="{{ asset('js/password-validation.js') }}?v={{ filemtime(public_path('js/password-validation.js')) }}" defer></script>
@endsection
