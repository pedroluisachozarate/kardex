window.kardexCalendar = (initial, available) => ({
    value: initial, available, open: false,
    month: Number(initial.slice(5,7))-1, year: Number(initial.slice(0,4)),
    get formatted() { return this.value.split('-').reverse().join('/'); },
    get monthLabel() { return new Date(this.year,this.month,1).toLocaleDateString('es',{month:'long',year:'numeric'}); },
    get days() {
        const offset=(new Date(this.year,this.month,1).getDay()+6)%7;
        const days=Array(offset).fill(null);
        for(let number=1;number<=new Date(this.year,this.month+1,0).getDate();number++) {
            days.push({number,key:`${this.year}-${String(this.month+1).padStart(2,'0')}-${String(number).padStart(2,'0')}`});
        }
        return days;
    },
    move(step) { const date=new Date(this.year,this.month+step,1);this.year=date.getFullYear();this.month=date.getMonth(); }
});

// Share the dashboard calendar appearance with every native date field.
(() => {
    if (window.kardexDateFields) return;
    window.kardexDateFields = true;
    const fields = new Map();
    let current = null, pending = false;
    const icon = '<svg class="button-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 11h18"/></svg>';
    const format = value => /^\d{4}-\d{2}-\d{2}$/.test(value) ? value.split('-').reverse().join('/') : 'Seleccionar fecha';
    function close(focus = false) {
        if (!current) return;
        const {panel,button} = current;
        current = null; panel.remove(); button.setAttribute('aria-expanded','false');
        if (focus && button.isConnected) button.focus();
    }
    function position() {
        if (!current) return;
        const {button,panel} = current, rect = button.getBoundingClientRect();
        if (!button.isConnected || rect.bottom < 0 || rect.top > innerHeight) { close(); return; }
        const width = Math.min(310,innerWidth-16);
        panel.style.width = width+'px';
        panel.style.left = Math.max(8,Math.min(rect.left,innerWidth-width-8))+'px';
        panel.style.top = Math.max(8,Math.min(rect.bottom+6,innerHeight-panel.offsetHeight-8))+'px';
    }
    function open(input,button) {
        if (current?.input === input) { close(); return; }
        close();
        const value = /^\d{4}-\d{2}-\d{2}$/.test(input.value) ? input.value : '';
        const date = value ? new Date(value+'T12:00:00') : new Date();
        const panel = document.createElement('div');
        panel.className = 'calendar-panel kardex-date-panel';
        panel.setAttribute('role','dialog');panel.setAttribute('aria-label',button.getAttribute('aria-label'));
        current = {input,button,panel,year:date.getFullYear(),month:date.getMonth()};
        button.setAttribute('aria-expanded','true');(input.closest('[role=dialog],.admin-modal') || document.body).append(panel);
        function choose(value) {
            input.value = value;
            input.dispatchEvent(new Event('input',{bubbles:true}));
            input.dispatchEvent(new Event('change',{bubbles:true}));
            sync(input,button);close(true);
        }
        function render() {
            if (!current || current.panel !== panel) return;
            const {year,month} = current;
            panel.replaceChildren();
            const heading = document.createElement('div');heading.className='calendar-heading';
            const prev=document.createElement('button'),next=document.createElement('button'),title=document.createElement('strong');
            prev.type=next.type='button';prev.className=next.className='button secondary compact';prev.textContent='‹';next.textContent='›';prev.setAttribute('aria-label','Mes anterior');next.setAttribute('aria-label','Mes siguiente');
            title.textContent=new Date(year,month,1).toLocaleDateString('es',{month:'long',year:'numeric'});title.setAttribute('aria-live','polite');
            function move(step) { const date=new Date(year,month+step,1);current.year=date.getFullYear();current.month=date.getMonth();render();panel.querySelector(step<0?'button':'button:last-of-type')?.focus(); }
            prev.onclick=()=>move(-1);next.onclick=()=>move(1);heading.append(prev,title,next);panel.append(heading);
            const grid=document.createElement('div');grid.className='calendar-days';
            for(const name of ['L','M','X','J','V','S','D']) {const span=document.createElement('span');span.textContent=name;grid.append(span);}
            const offset=(new Date(year,month,1).getDay()+6)%7;
            for(let i=0;i<offset;i++)grid.append(document.createElement('span'));
            for(let number=1;number<=new Date(year,month+1,0).getDate();number++) {
                const key=`${year}-${String(month+1).padStart(2,'0')}-${String(number).padStart(2,'0')}`;
                const day=document.createElement('button');day.type='button';day.textContent=number;day.setAttribute('aria-label',format(key));
                day.disabled=Boolean((input.min && key<input.min)||(input.max && key>input.max));
                if(key===input.value){day.className='selected';day.setAttribute('aria-current','date');}
                day.onclick=()=>choose(key);grid.append(day);
            }
            panel.append(grid);
            if(!input.required) {const clear=document.createElement('button');clear.type='button';clear.className='text-button calendar-clear';clear.textContent='Borrar fecha';clear.onclick=()=>choose('');panel.append(clear);}
            position();
        }
        render();(panel.querySelector('.selected')||panel.querySelector('.calendar-days button:not(:disabled)'))?.focus();
    }
    function sync(input,button) {
        const text=format(input.value), span=button.querySelector('span');
        if(span.textContent!==text)span.textContent=text;
        const disabled=input.disabled||input.readOnly;
        if(button.disabled!==disabled)button.disabled=disabled;
    }
    function scan() {
        pending=false;
        for(const [input,button] of fields) {
            if(!input.isConnected){if(current?.input===input)close();button.remove();fields.delete(input);continue;}
            if(!input.classList.contains('kardex-date-source'))input.classList.add('kardex-date-source');
            input.tabIndex=-1;
            if(!button.isConnected)input.after(button);
            if(current?.input===input && (!current.panel.isConnected || input.disabled || input.readOnly))close();
            sync(input,button);
        }
        for(const input of document.querySelectorAll('input[type="date"]')) {
            if(fields.has(input))continue;
            const button=document.createElement('button');button.type='button';button.className='calendar-trigger button secondary kardex-date-trigger';button.innerHTML=icon+'<span></span>';
            const label=input.getAttribute('aria-label')||input.closest('label')?.textContent.trim()||'Seleccionar fecha';
            button.setAttribute('aria-label',label);button.setAttribute('aria-haspopup','dialog');button.setAttribute('aria-expanded','false');
            input.classList.add('kardex-date-source');input.tabIndex=-1;input.after(button);fields.set(input,button);sync(input,button);
            button.onclick=()=>open(input,button);input.addEventListener('input',()=>sync(input,button));input.addEventListener('change',()=>sync(input,button));
        }
    }
    function schedule() {if(!pending){pending=true;requestAnimationFrame(scan);}}
    function start() {
        scan();new MutationObserver(schedule).observe(document.body,{childList:true,subtree:true,attributes:true,attributeFilter:['value','disabled','readonly']});
        document.addEventListener('pointerdown',event=>{if(current&&!current.panel.contains(event.target)&&!current.button.contains(event.target))close();},true);
        document.addEventListener('keydown',event=>{if(event.key==='Escape'&&current){event.preventDefault();event.stopImmediatePropagation();close(true);}},true);
        document.addEventListener('scroll',position,true);window.addEventListener('resize',position);
        document.addEventListener('livewire:updated',schedule);
    }
    if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',start,{once:true});else start();
})();
