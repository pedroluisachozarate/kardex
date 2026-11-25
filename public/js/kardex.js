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

// Prepare evidence on the device before sending or saving it to a local draft.
const maxPhotoBytes = 8 * 1024 * 1024;
async function prepareActivityPhoto(file) {
    const url = URL.createObjectURL(file);
    const image = new Image();
    const canvas = document.createElement('canvas');
    try {
        image.src = url;
        try { await image.decode(); }
        catch { throw new Error('No se pudo leer la imagen. Selecciona una foto JPG, PNG o WebP.'); }
        const longestSide = Math.max(image.naturalWidth, image.naturalHeight);
        const supported = /^image\/(jpeg|png|webp)$/.test(file.type);
        if (supported && longestSide <= 1920 && file.size <= 300 * 1024) return file;

        const scale = Math.min(1, 1920 / longestSide);
        canvas.width = Math.max(1, Math.round(image.naturalWidth * scale));
        canvas.height = Math.max(1, Math.round(image.naturalHeight * scale));
        const context = canvas.getContext('2d', {alpha: false});
        if (!context) throw new Error('No se pudo preparar la foto.');
        context.fillStyle = '#fff';
        context.fillRect(0, 0, canvas.width, canvas.height);
        context.drawImage(image, 0, 0, canvas.width, canvas.height);
        const jpeg = await new Promise(resolve => canvas.toBlob(resolve, 'image/jpeg', 0.82));
        if (supported && scale === 1 && file.size <= maxPhotoBytes && (!jpeg || jpeg.size >= file.size)) return file;
        if (!jpeg || jpeg.size > maxPhotoBytes) throw new Error('No se pudo reducir la foto a 8 MB. Selecciona otra imagen.');
        return new File([jpeg], file.name.replace(/\.[^.]+$/, '') + '.jpg', {type: jpeg.type});
    } finally {
        URL.revokeObjectURL(url);
        canvas.width = canvas.height = 0;
    }
}

window.kardexPrepareLocalPhotos = async function(input, wire, property, remaining) {
    const files = Array.from(input.files || []);
    if (!files.length) return;
    const emit = (name, detail = {}) => input.dispatchEvent(new CustomEvent(`livewire-upload-${name}`, {bubbles: true, detail}));
    emit('start');
    try {
        if (files.length > remaining) throw new Error('Puedes agregar hasta 10 fotos por actividad.');
        const prepared = [];
        for (const file of files) prepared.push(await prepareActivityPhoto(file));
        await new Promise((resolve, reject) => wire.uploadMultiple(property, prepared, resolve,
            () => reject(new Error('No se pudieron adjuntar las fotos al borrador.')), undefined,
            () => reject(new Error('Carga cancelada.'))));
        emit('finish');
    } catch (error) {
        emit('error', {message: error.message});
    } finally {
        input.value = '';
    }
};

// JSON photo transport uses the same request format as Livewire in Android.
const activityPhotoRequests = new WeakMap();
window.kardexCancelPhotoUpload = input => activityPhotoRequests.get(input)?.abort();
window.kardexUploadActivityPhotos = async function(input, wire, endpoint, bucket, index) {
    const files = Array.from(input.files || []);
    if (!files.length || activityPhotoRequests.has(input)) return;
    const emit = (name, detail = {}) => input.dispatchEvent(new CustomEvent(name, {bubbles:true, detail}));
    emit('livewire-upload-start');
    if (files.length > 10) {
        emit('livewire-upload-error', {message:'Selecciona hasta 10 fotos.'});input.value='';return;
    }
    let cancelled=false, completed=0, activeRequest=null, reader=null;
    activityPhotoRequests.set(input,{abort(){cancelled=true;reader?.abort();activeRequest?.abort();}});
    const split=bucket.indexOf('_');
    try {
        for (let number=0; number<files.length; number++) {
            if(cancelled)throw new Error('Carga cancelada.');
            const file = await prepareActivityPhoto(files[number]);
            if (cancelled) throw new Error('Carga cancelada.');
            const encoded=await new Promise((resolve,reject)=>{
                reader=new FileReader();
                reader.onload=()=>resolve(String(reader.result).split(',',2)[1]);
                reader.onerror=()=>reject(new Error('No se pudo leer la foto. Vuelve a seleccionarla.'));
                reader.onabort=()=>reject(new Error('Carga cancelada.'));
                reader.readAsDataURL(file);
            });
            reader=null;
            if(cancelled)throw new Error('Carga cancelada.');
            const data={template_id:bucket.slice(0,split),type:bucket.slice(split+1),activity_index:index,
                encoded_photo:{name:file.name,data:encoded}};
            if(wire.interventionId)data.intervention_id=wire.interventionId;
            const result=await new Promise((resolve,reject)=>{
                const xhr=activeRequest=new XMLHttpRequest();
                xhr.open('POST',endpoint);xhr.responseType='json';xhr.timeout=60000;
                xhr.setRequestHeader('Accept','application/json');
                xhr.setRequestHeader('Content-Type','application/json');
                xhr.setRequestHeader('X-CSRF-TOKEN',document.querySelector('meta[name="csrf-token"]').content);
                xhr.upload.onprogress=event=>{if(event.lengthComputable)emit('livewire-upload-progress',{progress:Math.round(100*(number+event.loaded/event.total)/files.length)});};
                xhr.onload=()=>{
                    const response=xhr.response;
                    if(xhr.status>=200&&xhr.status<300&&response?.token)return resolve(response);
                    const message=response?.errors?Object.values(response.errors).flat().join(' '):
                        xhr.status===419||xhr.status===401?'La sesión venció. Vuelve a ingresar y selecciona las fotos.':
                        xhr.status===403?'Tu cuenta no tiene permiso para subir fotos en este proyecto.':
                        xhr.status===404||xhr.status===405?'El servidor no encuentra la ruta de carga. Actualiza la página e intenta nuevamente.':
                        xhr.status===413?'La foto supera el tamaño permitido por el servidor.':
                        xhr.status===429?'Se hicieron muchas cargas seguidas. Espera un minuto y vuelve a intentar.':
                        'El servidor no pudo recibir la foto. Intenta nuevamente.';
                    reject(new Error(message));
                };
                xhr.onerror=()=>reject(new Error('Se perdió la conexión al subir la foto. Intenta nuevamente.'));
                xhr.ontimeout=()=>reject(new Error('La carga tardó demasiado. Intenta nuevamente.'));
                xhr.onabort=()=>{cancelled=true;reject(new Error('Carga cancelada.'));};
                xhr.send(JSON.stringify(data));
            });
            activeRequest=null;
            let confirmationTimer;
            const confirmation=await Promise.race([
                wire.acceptUploadedPhotos(result.token),
                new Promise((resolve,reject)=>{confirmationTimer=setTimeout(()=>reject(new Error('El servidor tardó demasiado en confirmar la foto.')),30000);})
            ]).finally(()=>clearTimeout(confirmationTimer));
            if(!confirmation?.ok)throw new Error(confirmation?.message||'No se pudo confirmar la foto. Intenta nuevamente.');
            completed++;
        }
        emit('livewire-upload-finish');
    } catch(error) {
        let message=error.message||'No se pudieron cargar las fotos.';
        if(completed>0)message=`Se cargaron ${completed} de ${files.length} fotos. Pulsa Guardar para conservarlas y selecciona solo las restantes. ${message}`;
        emit(cancelled?'livewire-upload-cancel':'livewire-upload-error',{message});
        if(cancelled&&completed>0)window.dispatchEvent(new CustomEvent('kardex-notice',{detail:{message}}));
    } finally {
        activityPhotoRequests.delete(input);input.value='';
    }
};
