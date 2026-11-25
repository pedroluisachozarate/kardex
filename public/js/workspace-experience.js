(() => {
    'use strict';
    if (window.kardexExperience || !document.body.dataset.workspaceUser) return;
    const api = window.kardexExperience = {};
    const user = document.body.dataset.workspaceUser;
    const prefix = `kardex:recovery:v1:${user}:`;
    const positionKey = `kardex:position:${user}:${location.pathname}${location.search}`;
    let nativeSubmit = null;
    let active = null, version = 0, savedVersion = null, savingKey = null, busy = false, uploading = 0, timer, toastTimer, scheduled = false;
    const dirtyForms = new WeakSet();
    const safeRead = (storage, key) => { try { return JSON.parse(storage.getItem(key)); } catch { return null; } };
    const put = (storage, key, value) => { try { storage.setItem(key, JSON.stringify(value)); return true; } catch { return false; } };
    const remove = key => { try { localStorage.removeItem(key); } catch {} };
    const wireOf = element => {
        const root = element?.closest('[wire\\:id]');
        return root && window.Livewire?.find(root.getAttribute('wire:id'));
    };
    const notice = (message, options = {}) => {
        let el = document.getElementById('workspace-notice');
        if (!el) { el = document.createElement('div'); el.id = 'workspace-notice'; el.setAttribute('role', 'status'); document.body.append(el); }
        el.textContent = message; el.hidden = false;
        el.classList.remove('has-action');
        if(options.link) {
            try { const url=new URL(options.link.href,location.href);
                if(url.origin===location.origin) {const link=document.createElement('a');link.href=url.href;link.textContent=options.link.text;el.append(' ',link);el.classList.add('has-action');}
            } catch { /* Ignore invalid action links. */ }
        }
        clearTimeout(toastTimer); toastTimer = setTimeout(() => { el.hidden = true; }, options.duration ?? (message === 'Datos actualizados.' ? 1000 : 4500));
    };
    api.notice=notice;
    window.addEventListener('kardex-notice', e => notice(e.detail?.message || 'Listo.',e.detail || {}));
    for(const detail of window.kardexPendingNotices || [])notice(detail.message,detail);
    window.kardexPendingNotices=[];
    const isGuarded = form => !!form && (form.hasAttribute('data-recovery-form') || /^(save|create|copy|password)/i.test(form.getAttribute('wire:submit') || '') || (form.method === 'post' && !/logout|offline/.test(form.action)));
    const isDirty = () => !!active?.dirty || [...document.forms].some(form => dirtyForms.has(form));
    let editorView = null;
    const photoViews = new WeakMap();
    const editorIdentity = form => form ? [form.closest('[data-recovery-scope]')?.dataset.recoveryScope, form.dataset.recoveryMode, form.dataset.recoveryId].join('|') : null;

    function readEditorView(form, activity = null) {
        if (!form?.hasAttribute('data-recovery-form')) return;
        const scroller = form.querySelector('.drawer-body');
        const viewport = scroller?.getBoundingClientRect() ?? {top: 0, bottom: innerHeight};
        const rows = [...form.querySelectorAll('[data-activity-key]')];
        const isVisible = row => {
            const box = row.getBoundingClientRect();
            return box.bottom > viewport.top && box.top < viewport.bottom;
        };
        const anchor = activity && isVisible(activity) ? activity : rows.find(isVisible);
        return {
            identity: editorIdentity(form), scrollTop: scroller?.scrollTop ?? scrollY,
            expanded: rows.filter(row => row === activity || row.dataset.activityExpanded === 'true').map(row => row.dataset.activityKey),
            anchor: anchor?.dataset.activityKey,
            offset: anchor ? anchor.getBoundingClientRect().top - viewport.top : null,
        };
    }

    function restoreEditorView(saved) {
        if (!saved) return;
        requestAnimationFrame(() => {
            const form = document.querySelector('[data-recovery-form]');
            if (editorIdentity(form) !== saved.identity) return;
            const rows = [...form.querySelectorAll('[data-activity-key]')];
            for (const row of rows) {
                row.dispatchEvent(new CustomEvent('kardex-activity-restore', {
                    detail: {expanded: saved.expanded.includes(row.dataset.activityKey)},
                }));
            }
            requestAnimationFrame(() => {
                if (!form.isConnected) return;
                const scroller = form.querySelector('.drawer-body');
                const anchor = rows.find(row => row.dataset.activityKey === saved.anchor && row.isConnected);
                const viewportTop = scroller?.getBoundingClientRect().top ?? 0;
                const position = anchor ? (scroller?.scrollTop ?? scrollY) + anchor.getBoundingClientRect().top - viewportTop - saved.offset : saved.scrollTop;
                if (scroller) scroller.scrollTop = position;
                else scrollTo({top: position, behavior: 'instant'});
            });
        });
    }

    function rememberPhotoView(input) {
        if (!input.matches?.('input[type="file"]')) return;
        const activity = input.closest('[data-activity-key]');
        if (!activity) return;
        const saved = readEditorView(activity.closest('form'), activity);
        if (saved) photoViews.set(input, saved);
    }
    // Capture before Android opens its file picker, since the scroll may reset on return.
    document.addEventListener('click', event => rememberPhotoView(event.target), true);
    document.addEventListener('cancel', event => {
        const saved = photoViews.get(event.target);
        photoViews.delete(event.target);
        restoreEditorView(saved);
    }, true);
    function assign(object, path, value) {
        const parts = path.split('.');
        if (parts.some(p => ['__proto__', 'prototype', 'constructor'].includes(p))) return;
        let target = object;
        parts.slice(0, -1).forEach((key, i) => { if (!target[key] || typeof target[key] !== 'object') target[key] = /^\d+$/.test(parts[i + 1]) ? [] : {}; target = target[key]; });
        target[parts.at(-1)] = value;
    }
    const hasFiles = value => value && typeof value === 'object' ? Object.values(value).some(hasFiles) : typeof value === 'string' && value.length > 0;
    function snapshot(form) {
        const wire = wireOf(form), values = {};
        if (!wire) return null;
        for (const key of ['form', 'technical', 'activityData']) {
            const value = wire.$get(key);
            if (value && typeof value === 'object') values[key] = JSON.parse(JSON.stringify(value));
        }
        form.querySelectorAll('input,select,textarea').forEach(input => {
            if (['file', 'password', 'hidden'].includes(input.type) || input.disabled) return;
            const binding = [...input.attributes].find(a => a.name === 'wire:model' || a.name.startsWith('wire:model.'))?.value;
            if (!binding || !/^(form|technical|activityData)\./.test(binding)) return;
            if (input.type === 'radio' && !input.checked) return;
            assign(values, binding, input.type === 'checkbox' ? input.checked : input.value);
        });
        return {mode:form.dataset.recoveryMode, id:form.dataset.recoveryId, values, time:Date.now(), photos:uploading > 0 || [...form.querySelectorAll('input[type=file]')].some(i => i.files?.length) || [wire.$get('photoFiles'),wire.$get('uploads')].some(hasFiles)};
    }
    function capture() {
        if (!active?.dirty || !active.form.isConnected) return;
        const data = snapshot(active.form);
        if (!data) return;
        const ok = JSON.stringify(data).length <= 262144 && put(localStorage, active.key, data);
        const hint = active.form.querySelector('[data-recovery-status]');
        if (!hint) return;
        const message = ok ? `Copia de texto guardada · ${new Date(data.time).toLocaleTimeString('es', {hour:'2-digit', minute:'2-digit'})}. ${data.photos ? 'Hay fotos pendientes: pulsa Guardar antes de salir.' : 'Pulsa Guardar para confirmar el borrador.'}` : 'No se pudo guardar la copia automática. Guarda el borrador antes de salir.';
        if (hint.textContent !== message) hint.textContent = message;
    }
    function initForm() {
        const form = document.querySelector('[data-recovery-form]');
        if (!form) { active = null; return; }
        const scope = form.closest('[data-recovery-scope]')?.dataset.recoveryScope;
        const key = `${prefix}${scope}:${form.dataset.recoveryMode}:${form.dataset.recoveryId || 'new'}`;
        if (active?.key === key) { active.form = form; return; }
        active = {form, key, dirty:false}; savedVersion = null;
    }
    function recoveries(scope) {
        try { return Object.keys(localStorage).filter(k => k.startsWith(`${prefix}${scope}:`)).map(key => ({key, data:safeRead(localStorage,key)})).filter(r => r.data?.values && Date.now() - r.data.time < 30 * 86400000).sort((a,b)=>b.data.time-a.data.time); } catch { return []; }
    }
    function scan() {
        initForm();
        restoreMobilePosition();
        document.querySelectorAll('[data-recovery-scope]').forEach(root => {
            const found = recoveries(root.dataset.recoveryScope).find(r => !(active?.dirty && r.key === active.key));
            let banner = root.querySelector('[data-recovery-banner]');
            if (!found) { banner?.remove(); return; }
            if (banner?.dataset.key === found.key) return;
            banner?.remove();
            banner = document.createElement('div'); banner.dataset.recoveryBanner = ''; banner.dataset.key = found.key; banner.className = 'workspace-recovery-banner';
            const label = document.createElement('span'); label.textContent = 'Tienes un formulario sin terminar.';
            const restore = document.createElement('button'); restore.type = 'button'; restore.className = 'text-button'; restore.textContent = 'Recuperar';
            const discard = document.createElement('button'); discard.type = 'button'; discard.className = 'text-button'; discard.textContent = 'Descartar';
            restore.onclick = async () => {
                if (isDirty() && !confirm('Hay cambios abiertos. ¿Reemplazarlos por la copia guardada?')) return;
                restore.disabled = true;
                try {
                    const component = wireOf(root);
                    if (!component) throw new Error('missing component');
                    await component.$call('restoreRecovery', found.data);
                    await new Promise(resolve => setTimeout(resolve, 100));
                    initForm();
                    if (!active) throw new Error('missing editor');
                    active.dirty = true; version++; capture();
                    if (found.key !== active.key) remove(found.key);
                    banner.remove();
                    notice(found.data.photos ? 'Texto recuperado. Vuelve a adjuntar las fotos que no habías guardado.' : 'Texto recuperado. Revisa y guarda el borrador.');
                } catch { notice('No se pudo recuperar. La copia sigue guardada; comprueba la conexión y el estado del Kardex.'); restore.disabled = false; }
            };
            discard.onclick = () => { if (confirm('¿Descartar esta copia de recuperación?')) { remove(found.key); banner.remove(); scan(); } };
            banner.append(label, restore, discard);
            const container = root.querySelector('main') || root;
            container.prepend(banner);
        });
    }
    const scheduleScan = () => { if (scheduled) return; scheduled = true; requestAnimationFrame(() => { scheduled = false; scan(); }); };
    document.addEventListener('input', event => {
        const form = event.target.closest('form');
        if (!isGuarded(form)) return;
        dirtyForms.add(form); version++; initForm();
        if (active?.form === form) { active.dirty = true; clearTimeout(timer); timer = setTimeout(capture, 500); }
    }, true);
    document.addEventListener('change', event => {
        const form = event.target.closest('form');
        if (!isGuarded(form)) return;
        dirtyForms.add(form); version++; initForm();
        if (active?.form === form) { active.dirty = true; clearTimeout(timer); timer = setTimeout(capture, 500); }
    }, true);
    document.addEventListener('submit', event => { if (uploading && event.target.matches('[data-recovery-form]')) { event.preventDefault(); event.stopImmediatePropagation(); return; } if (isGuarded(event.target)) { editorView = readEditorView(event.target); if(!event.target.hasAttribute('wire:submit')) nativeSubmit = event; savedVersion = version; savingKey = active?.key; capture(); } }, true);
    document.addEventListener('click', event => {
        const action = event.target.closest('button,a');
        if (!action) return;
        const call = action.getAttribute('wire:click') || action.getAttribute('wire:click.prevent') || '';
        if (/save|sendCurrent|createIntervention/.test(call)) { editorView = readEditorView(action.closest('form')); savedVersion = version; savingKey = active?.key; capture(); }
        const leaves = /^(closeModal|cancelEdit|navigate|openSystem|showSummary|backSummary)/.test(call) || (action.tagName === 'A' && action.target !== '_blank' && action.getAttribute('href') && !action.getAttribute('href').startsWith('#') && !action.hasAttribute('download'));
        if (leaves && uploading) { event.preventDefault(); event.stopImmediatePropagation(); return; }
        if (!leaves || !isDirty()) return;
        capture();
        if (!confirm(active ? 'Hay cambios sin confirmar o fotos pendientes. ¿Salir del formulario? La copia automática conserva el texto, pero las fotos pendientes requieren Guardar.' : 'Hay cambios sin guardar. ¿Salir y descartarlos?')) { event.preventDefault(); event.stopImmediatePropagation(); return; }
        active && (active.dirty = false); document.querySelectorAll('form').forEach(f => dirtyForms.delete(f));
    }, true);
    document.addEventListener('keydown', event => {
        if (event.key === 'Escape' && uploading) { event.preventDefault(); event.stopImmediatePropagation(); return; }
        if (event.key !== 'Escape' || document.querySelector('.photo-gallery[open]') || !isDirty() || !document.querySelector('.admin-modal,[data-recovery-form]')) return;
        capture();
        if (!confirm('Hay cambios sin confirmar. ¿Cerrar el formulario? Guarda primero si tienes fotos pendientes.')) { event.preventDefault(); event.stopImmediatePropagation(); }
        else { if (active) active.dirty = false; document.querySelectorAll('form').forEach(f=>dirtyForms.delete(f)); }
    }, true);
    window.addEventListener('beforeunload', event => { if(nativeSubmit && !nativeSubmit.defaultPrevented) return; if (isDirty() || uploading) { capture(); event.preventDefault(); event.returnValue = ''; } });
    document.addEventListener('visibilitychange', () => { if (document.hidden) capture(); });
    window.addEventListener('kardex-operation-saved', () => { if(!active && savedVersion===version) {document.querySelectorAll('form').forEach(f=>dirtyForms.delete(f));savedVersion=null;} });
    window.addEventListener('kardex-saved', () => {
        restoreEditorView(editorView);
        editorView = null;
        // A successful server/local save is the only event that removes the recovery copy.
        if (savedVersion !== null && version !== savedVersion) { capture(); return; }
        if (savingKey) remove(savingKey);
        if (active) { remove(active.key); active.dirty = false; const hint = active.form.querySelector('[data-recovery-status]'); if (hint) hint.textContent = '';  dirtyForms.delete(active.form); }
        document.querySelectorAll('form').forEach(f=>dirtyForms.delete(f));
        savedVersion = null; savingKey = null; notice('Borrador guardado.',{duration:1500}); scheduleScan();
    });
    document.addEventListener('livewire-upload-start', event => {
        uploading++;
        if (!photoViews.has(event.target)) rememberPhotoView(event.target);
        restoreEditorView(photoViews.get(event.target));
    }, true);
    for (const eventName of ['livewire-upload-finish', 'livewire-upload-error', 'livewire-upload-cancel']) {
        document.addEventListener(eventName, event => {
            uploading = Math.max(0, uploading - 1);
            const saved = photoViews.get(event.target);
            photoViews.delete(event.target);
            restoreEditorView(saved);
            capture();
        }, true);
    }
    let mobileScreen = null;
    function mobilePositionKey() {
        const root=document.querySelector('[data-mobile-workspace]'),wire=wireOf(root);
        if(!wire) return null;
        return 'kardex:mobile-position:'+user+':'+JSON.stringify(['page','summarySection','summaryState','projectId','editing','from','to','appliedSummaryProject'].map(key=>wire.$get(key)));
    }
    function restoreMobilePosition() {
        const key=mobilePositionKey();
        if(!key || key===mobileScreen) return;
        mobileScreen=key;
        const position=safeRead(sessionStorage,key);
        if(position) requestAnimationFrame(()=>restoreScroll(position));
    }
    document.addEventListener('click',event=>{
        const action=event.target.closest('[wire\\:click],[wire\\:click\\.prevent]');
        if(!action)return;
        const key=mobilePositionKey();if(key)put(sessionStorage,key,scrollSnapshot());
    },true);
    function scrollSnapshot() {
        return {window:scrollY, lists:[...document.querySelectorAll('.table-card,.offline-scroll-list,.admin-records')].map(el=>el.scrollTop)};
    }
    function restoreScroll(position) {
        if (!position) return;
        scrollTo(0,position.window || 0);
        document.querySelectorAll('.table-card,.offline-scroll-list,.admin-records').forEach((el,i)=>{el.scrollTop=position.lists?.[i] || 0;});
    }
    const listKey = `kardex:list:${user}:${location.pathname}`;
    function saveList() {
        const root = document.querySelector('.project-workspace[data-recovery-scope]');
        if (!root) return;
        const wire = wireOf(root);
        if (!wire) return;
        put(sessionStorage,listKey,{search:wire.$get('search'),statusFilters:wire.$get('statusFilters'),paginators:wire.$get('paginators')});
    }
    window.addEventListener('pagehide', () => { capture(); saveList(); put(sessionStorage,positionKey,scrollSnapshot()); });
    setTimeout(async () => {
        const root=document.querySelector('.project-workspace[data-recovery-scope]'), state=safeRead(sessionStorage,listKey);
        if(!root || !state || isDirty() || document.querySelector('.admin-modal')) return;
        const wire=wireOf(root);if(!wire)return;
        try {
            if(typeof state.search==='string') wire.$set('search',state.search.slice(0,200),false);
            if(Array.isArray(state.statusFilters)) wire.$set('statusFilters',state.statusFilters.filter(s=>['borrador','revision','observado','finalizado'].includes(s)),false);
            if(state.paginators && Number.isInteger(state.paginators.equipmentPage)) wire.$set('paginators.equipmentPage',Math.max(1,state.paginators.equipmentPage),false);
            await wire.$refresh(); restoreScroll(safeRead(sessionStorage,positionKey));
        } catch { /* The existing page remains usable when the server is unavailable. */ }
    },200);

    if (performance.getEntriesByType('navigation')[0]?.type === 'back_forward' || performance.getEntriesByType('navigation')[0]?.type === 'reload') setTimeout(()=>restoreScroll(safeRead(sessionStorage,positionKey)),150);
    let refreshedAt = new Date().toLocaleTimeString('es',{hour:'2-digit',minute:'2-digit'});
    const network = document.createElement('button'); network.type = 'button'; network.className = 'workspace-network text-button'; network.setAttribute('aria-label','Actualizar datos y comprobar conexión'); network.textContent = 'Comprobando conexión…';
    const sidebar = document.querySelector('.sidebar'); if (sidebar) sidebar.insertBefore(network,sidebar.querySelector('.account'));
    let healthy = null;
    async function checkNetwork() {
        if (document.hidden) return false;
        if (document.querySelector('[data-mobile-workspace]')) {
            const root = document.querySelector('[data-mobile-workspace]'), wire = wireOf(root);
            try { await wire.$call('checkConnection'); healthy = wire.$get('serverAvailable') === true; } catch { healthy = false; }
        } else {
            const controller = new AbortController(), timeout = setTimeout(()=>controller.abort(),4000);
            try { const response = await fetch('/api/mobile/health', {cache:'no-store',signal:controller.signal,headers:{Accept:'application/json'}}); const data = await response.json(); healthy = response.ok && data.service === 'kardex'; } catch { healthy = false; } finally { clearTimeout(timeout); }
        }
        network.textContent = healthy ? `Conectado · Actualizado ${refreshedAt} · Actualizar` : 'Sin conexión · Reintentar';
        return healthy;
    }
    async function refresh() {
        if (busy) return;
        if (isDirty() || uploading || document.querySelector('.admin-modal,[data-recovery-form],.admin-modal-backdrop')) { hidePull(); notice('Guarda o cierra el formulario antes de actualizar.'); return; }
        busy = true; const position = scrollSnapshot();
        const pulled = !pull.hidden;
        if (pulled) { showPull(96); pull.classList.add('is-loading'); pull.setAttribute('aria-label','Actualizando'); }
        else notice('Actualizando…');
        try {
            const local = document.querySelector('[data-mobile-workspace]');
            if (local) { await wireOf(local).$call('refreshWorkspace'); }
            else {
                if (!await checkNetwork()) { notice('Sin conexión. Conservamos los datos en pantalla.'); return; }
                const components = [...document.querySelectorAll('[wire\\:id]')].filter(el=>!el.parentElement.closest('[wire\\:id]'));
                if (components.length) { for (const el of components) await wireOf(el).$refresh(); refreshedAt=new Date().toLocaleTimeString('es',{hour:'2-digit',minute:'2-digit'});network.textContent=`Conectado · Actualizado ${refreshedAt} · Actualizar`;notice('Datos actualizados.'); }
                else { put(sessionStorage,positionKey,position); sessionStorage.setItem('kardex:refreshed','1'); location.reload(); return; }
            }
            setTimeout(()=>restoreScroll(position),100);
        } catch { notice('No se pudo actualizar. Conservamos la pantalla y tus datos.'); }
        finally { busy = false; hidePull(); }
    }
    api.refresh = refresh; api.capture = capture;
    network.onclick = refresh;
    window.addEventListener('offline', () => { healthy=false;network.textContent='Sin conexión · Reintentar';notice('Sin conexión. Tus cambios permanecen en pantalla.'); });
    window.addEventListener('online', () => { checkNetwork(); });
    setTimeout(checkNetwork,2500); setInterval(checkNetwork,60000);
    try { if(sessionStorage.getItem('kardex:refreshed')) {sessionStorage.removeItem('kardex:refreshed');notice('Datos actualizados.');} } catch {}
    const pull = document.createElement('div'); pull.className='workspace-pull';pull.hidden=true;pull.setAttribute('role','status');pull.setAttribute('aria-label','Desliza para actualizar');
    pull.innerHTML='<span class="workspace-pull-ring" aria-hidden="true"></span><span class="workspace-pull-letter" aria-hidden="true">K</span>';document.body.append(pull);
    function hidePull() {
        pull.hidden=true; pull.classList.remove('is-loading','is-ready');
        document.querySelectorAll('[data-pull-content]').forEach(el=>{el.style.removeProperty('translate');el.removeAttribute('data-pull-content');});
    }
    function showPull(dy) {
        const content=document.querySelector('main');
        const progress=Math.min(dy/80,1), offset=Math.min(dy*.5,40);
        if(content) {
            const rect=content.getBoundingClientRect();
            // Remove the previous gesture offset when measuring the original content edge.
            const previous=Number(content.dataset.pullContent || 0);
            pull.style.top=`${Math.max(0,rect.top-previous)+Math.max(0,offset-20)}px`;
            pull.style.left=`${rect.left+rect.width/2}px`;
            content.dataset.pullContent=String(offset);content.style.translate=`0 ${offset}px`;
        }
        pull.hidden=false;pull.style.clipPath=`inset(0 0 ${Math.max(0,36-offset)}px 0)`;pull.style.setProperty('--pull-progress',progress);
    }
    let start=null, distance=0;
    const atTop = target => { if(scrollY>0) return false; for(let el=target;el && el!==document.body;el=el.parentElement) if(el.scrollHeight>el.clientHeight+1 && /auto|scroll/.test(getComputedStyle(el).overflowY) && el.scrollTop>0) return false; return true; };
    document.addEventListener('touchstart', e => {
        start=null;distance=0;
        if(e.touches.length!==1 || busy || isDirty() || uploading || e.target.closest('input,textarea,select,button,.sidebar,.admin-modal,dialog,[data-recovery-form]') || document.querySelector('.sidebar.is-open') || !atTop(e.target)) return;
        start={x:e.touches[0].clientX,y:e.touches[0].clientY};
    },{passive:true});
    document.addEventListener('touchmove', e => {
        if(!start || e.touches.length!==1) return;
        const dx=e.touches[0].clientX-start.x,dy=e.touches[0].clientY-start.y;
        if(dy<0 || Math.abs(dx)>Math.abs(dy)) { start=null;hidePull();return; }
        distance=dy;
        if(dy>12) { if(e.cancelable)e.preventDefault();showPull(dy);pull.classList.toggle('is-ready',dy>=80);pull.setAttribute('aria-label',dy>=80?'Suelta para actualizar':'Desliza para actualizar');pull.style.setProperty('--pull-progress',Math.min(dy/80,1)); }
    },{passive:false});
    document.addEventListener('touchend',()=>{const ready=start && distance>=80;start=null;distance=0;if(ready)refresh();else if(!busy)hidePull();},{passive:true});
    document.addEventListener('touchcancel',()=>{start=null;distance=0;if(!busy)hidePull();},{passive:true});
    new MutationObserver(scheduleScan).observe(document.body,{childList:true,subtree:true});
    scan();
})();
