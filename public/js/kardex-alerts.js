// Shared alerts for regular forms, Livewire and photo uploads.
(() => {
    const shown = new WeakMap();
    const approved = new WeakSet();
    const notify = detail => {
        if(window.kardexExperience?.notice)window.kardexExperience.notice(detail.message,detail);
        else (window.kardexPendingNotices ||= []).push(detail);
    };
    const dialog = options => {
        if(options.icon==='success') {
            notify({message:options.text || options.title || 'Cambios guardados.',duration:1500});
            return Promise.resolve({isConfirmed:false});
        }
        return Swal.fire({
        confirmButtonText: 'Aceptar', cancelButtonText: 'Cancelar',
        confirmButtonColor: '#16745f', cancelButtonColor: '#657680',
        heightAuto: false, ...options,
        });
    };
    window.kardexAlert = dialog;
    const scan = () => {
        document.querySelectorAll('[data-kardex-alert]').forEach(node => {
            const copy = node.cloneNode(true);
            copy.querySelectorAll('button, a').forEach(child => child.remove());
            const items = [...copy.querySelectorAll('li')];
            const text = items.length ? items.map(item => item.textContent.trim()).join('\n') : copy.textContent.trim().replace(/^✓\s*/, '');
            if (!text || shown.get(node) === text) return;
            shown.set(node, text);
            const success = node.dataset.kardexAlert === 'success';
            if (success) window.dispatchEvent(new CustomEvent('kardex-operation-saved'));
            const link = node.querySelector('a');
            node.hidden = true;
            if(success) {
                notify({message:text,duration:1500,...(link ? {link:{href:link.href,text:link.textContent.trim()}} : {})});
                return;
            }
            dialog({icon:'error',title:'Revisa los datos',text,
                ...(link ? {showCancelButton:true,confirmButtonText:link.textContent.trim(),cancelButtonText:'Cerrar'} : {}),
            }).then(result=>{if(link && result.isConfirmed)window.location.assign(link.href);});
        });
    };
    window.addEventListener('offline', () => {
        window.dispatchEvent(new CustomEvent('kardex-notice', {detail:{message:'Sin conexión. Conservamos los cambios en pantalla.'}}));
    });
    document.addEventListener('click', async event => {
        const button = event.target.closest('[data-confirm]');
        if (!button || button.disabled) return;
        if (button.name === 'status' && button.value === 'observado') {
            const note = button.form?.querySelector('[name="review_note"]');
            if (note && !note.value.trim()) {
                event.preventDefault(); event.stopImmediatePropagation();
                await dialog({icon:'warning', title:'Indica el motivo', text:'Escribe el motivo de devolución o reapertura antes de continuar.'});
                note.focus();
                return;
            }
        }
        if (approved.has(button)) { approved.delete(button); return; }
        event.preventDefault(); event.stopImmediatePropagation();
        const result = await dialog({icon:'question', title:button.dataset.confirm, text:button.dataset.confirmText || '',
            showCancelButton:true, confirmButtonText:'Sí, continuar', focusCancel:true});
        if (result.isConfirmed && button.isConnected) { approved.add(button); button.click(); }
    }, true);
    document.addEventListener('livewire-upload-error', event => {
        dialog({icon:'error', title:'No se pudieron subir las fotos', text:event.detail?.message || 'Intenta nuevamente.'});
    }, true);
    document.addEventListener('livewire:init', () => {
        Livewire.hook('request', ({fail}) => {
            fail(({status, preventDefault}) => {
                preventDefault();
                dialog({icon:'error', title:status === 419 ? 'La sesión venció' : 'No se pudo completar la acción',
                    text:status === 419 ? 'Recarga la página e inicia sesión nuevamente.' : 'Comprueba tu conexión e intenta nuevamente.'});
            });
        });
    });
    let pending = false;
    new MutationObserver(() => {
        if (pending) return;
        pending = true;
        queueMicrotask(() => { pending = false; scan(); });
    }).observe(document.body, {childList:true, subtree:true, characterData:true});
    scan();
})();
