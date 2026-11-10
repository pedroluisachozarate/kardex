function labelSelects(root=document){root.querySelectorAll('label select').forEach(select=>{if(!select.hasAttribute('aria-label'))select.setAttribute('aria-label',[...select.parentElement.childNodes].filter(node=>node.nodeType===Node.TEXT_NODE).map(node=>node.textContent).join('').trim());});}
labelSelects();
document.querySelectorAll('[data-add-row]').forEach(button => button.addEventListener('click', () => {
    const container = document.querySelector(`[data-repeat="${button.dataset.addRow}"]`);
    const row = container.firstElementChild.cloneNode(true);
    row.querySelectorAll('input,textarea').forEach(input => { input.value = ''; });
    row.querySelectorAll('select').forEach(input => { input.selectedIndex = 0; });
    container.append(row);
}));
document.querySelectorAll('[data-template-builder]').forEach(form => {
    const container=form.querySelector('[data-fields]');
    const add=(field=null)=>{
        const row=document.createElement('div');row.className='field-row';
        row.innerHTML='<label>Nombre<input data-name required placeholder="Ej.: Capacidad"></label><label>Tipo<select data-type><option value="texto">Texto</option><option value="numero">Número</option><option value="fecha">Fecha</option><option value="lista">Lista</option></select></label><label>Unidad<input data-unit placeholder="BTU"></label><label>Obligatorio<select data-required><option value="no">No</option><option value="si">Sí</option></select></label><label>Opciones de lista<input data-options placeholder="Opción 1, Opción 2"></label><button type="button" class="button secondary" aria-label="Quitar campo">×</button>';
        row.querySelector('button').addEventListener('click',()=>row.remove());container.append(row);labelSelects(row);
        if(field){row.querySelector('[data-name]').value=field.name;row.querySelector('[data-type]').value=field.type;row.querySelector('[data-unit]').value=field.unit;row.querySelector('[data-required]').value=field.required?'si':'no';row.querySelector('[data-options]').value=field.options.join(', ');}
    };
    form.querySelector('[data-add-field]').addEventListener('click',()=>add());
    const initial=form.querySelector('[data-initial-fields]');if(initial)JSON.parse(initial.textContent).forEach(add);
    form.addEventListener('submit',()=>{form.querySelector('[data-fields-output]').value=[...container.children].map(row=>['name','type','unit','required','options'].map(key=>row.querySelector(`[data-${key}]`).value.replace(/[|\r\n]/g,' ')).join('|')).join('\n');});
});
const templateSelect=document.getElementById('asset-template');
if(templateSelect){
    const templates=JSON.parse(document.getElementById('template-data').textContent);
    const render=()=>{
        const target=document.getElementById('technical-fields');target.replaceChildren();
        (templates[templateSelect.value]||[]).forEach((field,index)=>{
            const label=document.createElement('label');label.textContent=field.name+(field.unit?` (${field.unit})`:'')+(field.required?' *':'');
            const input=document.createElement(field.type==='lista'?'select':'input');input.name=`technical[${index}]`;input.required=field.required;
            if(field.type==='lista'){const blank=document.createElement('option');blank.value='';blank.textContent='Seleccionar';input.append(blank);field.options.forEach(value=>{const option=document.createElement('option');option.value=value;option.textContent=value;input.append(option);});}
            else {input.type=field.type==='numero'?'number':field.type==='fecha'?'date':'text';if(input.type==='number')input.step='any';}
            label.append(input);target.append(label);
        });
    };templateSelect.addEventListener('change',render);render();
}
const existing=document.getElementById('existing-equipment');
if(existing){const toggle=()=>{const block=document.getElementById('new-equipment');block.hidden=Boolean(existing.value);block.querySelectorAll('input').forEach(input=>input.disabled=Boolean(existing.value));};existing.addEventListener('change',toggle);toggle();}

// Upload each activity batch in one request and explicitly confirm its registration.
const activityPhotoRequests = new WeakMap();
window.kardexCancelPhotoUpload = input => activityPhotoRequests.get(input)?.abort();
window.kardexUploadActivityPhotos = async function(input, wire, endpoint, bucket, index) {
    const files = Array.from(input.files || []);
    if (!files.length) return;
    const emit = (name, detail = {}) => input.dispatchEvent(new CustomEvent(name, {bubbles:true, detail}));
    const fail = message => emit('livewire-upload-error', {message});
    emit('livewire-upload-start');
    if (files.length > 10 || files.some(file => file.size > 8 * 1024 * 1024)) {
        fail('Selecciona hasta 10 fotos, de máximo 8 MB cada una.'); input.value = ''; return;
    }
    const split = bucket.indexOf('_');
    const data = new FormData();
    data.append('template_id', bucket.slice(0, split));
    data.append('type', bucket.slice(split + 1));
    data.append('activity_index', index);
    if (wire.interventionId) data.append('intervention_id', wire.interventionId);
    files.forEach(file => data.append('files[]', file, file.name));
    let cancelled = false;
    try {
        const result = await new Promise((resolve, reject) => {
            const xhr = new XMLHttpRequest();
            activityPhotoRequests.set(input, xhr);
            xhr.open('POST', endpoint);
            xhr.responseType = 'json';
            xhr.timeout = 60000;
            xhr.setRequestHeader('Accept', 'application/json');
            xhr.setRequestHeader('X-CSRF-TOKEN', document.querySelector('meta[name="csrf-token"]').content);
            xhr.upload.onprogress = event => { if(event.lengthComputable) emit('livewire-upload-progress', {progress:Math.round(100 * event.loaded / event.total)}); };
            xhr.onload = () => {
                const response = xhr.response;
                if (xhr.status >= 200 && xhr.status < 300 && response?.token) return resolve(response);
                const message = response?.errors ? Object.values(response.errors).flat().join(' ') :
                    xhr.status === 419 || xhr.status === 401 ? 'La sesión venció. Vuelve a ingresar y selecciona las fotos.' :
                    xhr.status === 403 ? 'Tu cuenta no tiene permiso para subir fotos en este proyecto.' :
                    xhr.status === 404 || xhr.status === 405 ? 'El servidor no encuentra la ruta de carga. Actualiza la página e intenta nuevamente.' :
                    xhr.status === 413 ? 'Las fotos superan el tamaño permitido por el servidor.' :
                    xhr.status === 429 ? 'Se hicieron muchas cargas seguidas. Espera un minuto y vuelve a intentar.' :
                    'El servidor no pudo recibir las fotos. Intenta nuevamente.';
                console.warn('Kardex: carga rechazada', {status: xhr.status, path: new URL(endpoint, location.href).pathname});
                reject(new Error(message));
            };
            xhr.onerror = () => reject(new Error('Se perdió la conexión al subir las fotos. Intenta nuevamente.'));
            xhr.ontimeout = () => reject(new Error('La carga tardó demasiado. Intenta con menos fotos por selección.'));
            xhr.onabort = () => { cancelled = true; reject(new Error('Carga cancelada.')); };
            xhr.send(data);
        });
        activityPhotoRequests.delete(input);
        let confirmationTimer;
        const confirmation = await Promise.race([
            wire.acceptUploadedPhotos(result.token),
            new Promise((resolve, reject) => { confirmationTimer = setTimeout(() => reject(new Error('El servidor tardó demasiado en confirmar las fotos. Intenta nuevamente.')), 30000); })
        ]).finally(() => clearTimeout(confirmationTimer));
        if (!confirmation?.ok) throw new Error(confirmation?.message || 'No se pudieron confirmar las fotos. Intenta nuevamente.');
        emit('livewire-upload-finish');
    } catch (error) {
        emit(cancelled ? 'livewire-upload-cancel' : 'livewire-upload-error', {message:error.message || 'No se pudieron cargar las fotos.'});
    } finally {
        activityPhotoRequests.delete(input);
        input.value = '';
    }
};
