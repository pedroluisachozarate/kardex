// Shared alerts for regular forms, Livewire and photo uploads.
(() => {
    const shown = new WeakMap();
    const approved = new WeakSet();
    const dialog = options => Swal.fire({
        confirmButtonText: 'Aceptar', cancelButtonText: 'Cancelar',
        confirmButtonColor: '#16745f', cancelButtonColor: '#657680',
        heightAuto: false, ...options,
    });
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
            const link = node.querySelector('a');
            node.hidden = true;
            dialog({icon: success ? 'success' : 'error', title: success ? 'Listo' : 'Revisa los datos', text,
                ...(success && !link ? {toast:true, position:'top-end', timer:3500, timerProgressBar:true, showConfirmButton:false} : {}),
                ...(link ? {showCancelButton:true, confirmButtonText:link.textContent.trim(), cancelButtonText:'Cerrar'} : {}),
            }).then(result => { if (link && result.isConfirmed) window.location.assign(link.href); });
        });
    };
    window.addEventListener('offline', () => {
        dialog({icon:'error', title:'Sin conexión', text:'Comprueba tu conexión y vuelve a intentarlo.'});
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
