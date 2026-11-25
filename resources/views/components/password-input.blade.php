<span class="password-field" x-data="{ visible: false }" x-on:keydown.escape.window="visible=false">
<input type="password" x-bind:type="visible ? 'text' : 'password'" {{ $attributes }}>
<button type="button" class="password-toggle" x-on:click="visible=!visible" x-bind:aria-pressed="visible" x-bind:aria-label="visible ? 'Ocultar contraseña' : 'Mostrar contraseña'" x-bind:title="visible ? 'Ocultar contraseña' : 'Mostrar contraseña'" aria-label="Mostrar contraseña" aria-pressed="false"><x-icon name="eye" x-show="!visible"/><x-icon name="eye-off" x-show="visible" x-cloak/></button>
</span>
