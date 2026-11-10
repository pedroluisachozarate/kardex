<div class="admin-workspace" wire:poll.15s.visible x-data @keydown.escape.window="$wire.closeForm()">
    @php($labels=['clientes'=>'Clientes','proyectos'=>'Proyectos','usuarios'=>'Usuarios'])
    @php($singular=['clientes'=>'cliente','proyectos'=>'proyecto','usuarios'=>'usuario'])
    <header class="page-heading"><h1>Administración</h1><span class="live-indicator" title="Actualización automática"><i></i> En línea</span></header>
    <div class="admin-tabs" role="tablist" aria-label="Secciones administrativas">
        @foreach($labels as $key=>$label)
            <button type="button" role="tab" aria-selected="{{ $tab===$key?'true':'false' }}" class="admin-tab {{ $tab===$key?'selected':'' }}" wire:click="selectTab('{{ $key }}')"><x-icon name="file"/><span>{{ $label }}</span><b>{{ $counts[$key] }}</b></button>
        @endforeach
    </div>
    @if($message)<div hidden data-kardex-alert="success"><span>✓ {{ $message }}</span>@if($savedType==='proyectos' && $savedId)<a class="link" href="{{ route('projects.show',$savedId) }}">Abrir proyecto →</a>@endif<button type="button" class="text-button" wire:click="$set('message','')" aria-label="Cerrar aviso"><x-icon name="close"/></button></div>@endif
    
    <section class="card admin-list">
        <div class="admin-toolbar compact-toolbar">
            <label class="search-label"><span class="sr-only">Buscar registros</span><input type="search" wire:model.live.debounce.300ms="search" placeholder="Buscar {{ strtolower($labels[$tab]) }}…" aria-label="Buscar registros"></label>
            @if($tab==='proyectos')<select wire:model.live="clientFilter" aria-label="Filtrar por cliente"><option value="">Todos los clientes</option>@foreach($clients as $client)<option value="{{ $client->id }}">{{ $client->name }}</option>@endforeach</select>@endif
            <button class="button" wire:click="openForm" wire:loading.attr="disabled"><x-icon name="plus"/>Nuevo {{ $singular[$tab] }}</button>
        </div>
        <div class="table-card"><table class="responsive-table"><thead><tr>
            @if($tab==='clientes')<th>Entidad / empresa</th><th>Contacto</th>
            @elseif($tab==='proyectos')<th>Proyecto</th><th>Cliente</th>
            @else<th>Persona</th><th>Rol</th><th>Estado</th>@endif
            <th class="align-right">Acciones</th></tr></thead><tbody>
            @forelse($records as $record)
                <tr wire:key="{{ $tab }}-{{ $record->id }}">
                    @if($tab==='clientes')<td data-label="Entidad / empresa"><strong>{{ $record->name }}</strong></td><td data-label="Contacto">{{ $record->contact?:'—' }}</td>
                    @elseif($tab==='proyectos')<td data-label="Proyecto"><a class="link" href="{{ route('projects.show',$record) }}">{{ $record->name }}</a></td><td data-label="Cliente">{{ $clients->firstWhere('id',$record->client_id)?->name }}</td>
                    @else<td data-label="Persona"><strong>{{ $record->name }}</strong><small>{{ $record->email }}</small></td><td data-label="Rol">{{ ['admin'=>'Administrador','supervisor'=>'Supervisor','tecnico'=>'Técnico','consulta'=>'Consulta'][$record->role] }}</td><td data-label="Estado"><span class="badge {{ $record->active?'finalizado':'no_realizado' }}">{{ $record->active?'Activo':'Inactivo' }}</span></td>@endif
                    <td data-label="Acciones" class="align-right"><div class="admin-row-actions">@if($tab==='usuarios' && $record->id===auth()->id())<span class="muted small">Tu cuenta</span>@else<button class="button secondary compact" wire:click="openForm({{ $record->id }})" aria-label="Editar {{ $record->name??$record->building }}"><x-icon name="edit"/>Editar</button>@endif @if($tab==='proyectos')<a class="button secondary compact open-project-button" href="{{ route('projects.show',$record) }}"><x-icon name="next"/>Abrir</a>@endif</div></td>
                </tr>
            @empty<tr><td colspan="4"><div class="empty"><p class="muted">{{ $search!==''?'Sin coincidencias':'Sin registros' }}</p></div></td></tr>@endforelse
        </tbody></table></div>
        <div class="admin-pagination"><span class="muted small">{{ $records->firstItem()??0 }}–{{ $records->lastItem()??0 }} de {{ $records->total() }}</span><div><button class="button secondary compact" wire:click="previousPage" @disabled($records->onFirstPage())><x-icon name="back"/>Anterior</button><span class="small">{{ $records->currentPage() }} / {{ $records->lastPage() }}</span><button class="button secondary compact" wire:click="nextPage" @disabled(!$records->hasMorePages())><x-icon name="next"/>Siguiente</button></div></div>
    </section>
    @if($showForm)
        <div class="admin-overlay" wire:key="editor-{{ $formType }}-{{ $editing??'new' }}" x-data x-init="$nextTick(() => $refs.editor.querySelector('input,select')?.focus())" x-trap.inert.noscroll="true">
            <div class="admin-backdrop" aria-hidden="true"></div>
            <section class="admin-modal" role="dialog" aria-modal="true" aria-labelledby="editor-title" x-ref="editor">
                <header><div><h2 id="editor-title">{{ $editing?'Editar':'Nuevo' }} {{ $singular[$formType] }}</h2></div><button type="button" class="text-button close-drawer" wire:click="closeForm" aria-label="Cerrar formulario"><x-icon name="close"/></button></header>
                <form wire:submit="save" novalidate>
                    <div class="drawer-body">
                    @if($formType==='proyectos')
                        <label for="admin-client">Entidad</label><select id="admin-client" wire:model.change="form.client_id"><option value="">Seleccionar entidad</option>@foreach($clients as $client)<option value="{{ $client->id }}">{{ $client->name }}</option>@endforeach</select>@error('form.client_id')<span class="field-error">{{ $message }}</span>@enderror
                        @if($clients->isEmpty())<p class="form-tip">Registra primero una entidad en Clientes.</p>@endif
                    @endif
                    <label for="admin-name">{{ ['clientes'=>'Entidad o empresa','proyectos'=>'Nombre del proyecto','usuarios'=>'Nombre completo'][$formType] }}</label><input id="admin-name" wire:model.blur="form.name" maxlength="200">@error('form.name')<span class="field-error">{{ $message }}</span>@enderror
                    @if($formType==='clientes')<label for="admin-contact">Contacto <span class="muted">(opcional)</span></label><input id="admin-contact" wire:model.blur="form.contact" maxlength="255">@error('form.contact')<span class="field-error">{{ $message }}</span>@enderror@endif
                    @if($formType==='usuarios')
                        <label for="admin-email">Correo electrónico</label><input id="admin-email" type="email" wire:model.blur="form.email" autocomplete="off">@error('form.email')<span class="field-error">{{ $message }}</span>@enderror
                        <label for="admin-phone">Teléfono <span class="muted">(opcional)</span></label><input id="admin-phone" wire:model.blur="form.phone">@error('form.phone')<span class="field-error">{{ $message }}</span>@enderror
                        <div class="grid two"><div><label for="admin-role">Rol</label><select id="admin-role" wire:model.change="form.role"><option value="tecnico">Técnico</option><option value="supervisor">Supervisor</option><option value="consulta">Consulta</option><option value="admin">Administrador</option></select>@error('form.role')<span class="field-error">{{ $message }}</span>@enderror</div><div><label for="admin-active">Estado</label><select id="admin-active" wire:model.change="form.active"><option value="1">Activo</option><option value="0">Inactivo</option></select>@error('form.active')<span class="field-error">{{ $message }}</span>@enderror</div></div>
                        <div class="role-help">{{ ['admin'=>'Acceso completo.','supervisor'=>'Revisa y aprueba Kardex.','tecnico'=>'Registra actividades y fotos.','consulta'=>'Solo lectura de Kardex y avances.'][$form['role']]??'Selecciona un rol.' }}</div>
                        <label for="admin-password">{{ $editing?'Nueva contraseña temporal (opcional)':'Contraseña temporal' }}</label><input id="admin-password" type="password" wire:model.blur="form.password" autocomplete="new-password">@error('form.password')<span class="field-error">{{ $message }}</span>@enderror
                        <div class="form-tip">Mínimo 10 caracteres. Cambio obligatorio al ingresar. {{ $editing?'Vacío: conservar contraseña.':'' }}</div>
                    @endif
                    </div>
                    <footer><button type="button" class="button secondary" wire:click="closeForm" wire:loading.attr="disabled"><x-icon name="close"/>Cancelar</button><button type="submit" class="button" wire:loading.attr="disabled"><x-icon name="save"/><span wire:loading.remove wire:target="save">{{ $editing?'Guardar cambios':'Crear '.$singular[$formType] }}</span><span wire:loading wire:target="save">Procesando…</span></button></footer>
                </form>
            </section>
        </div>
    @endif
</div>
