(() => {
    const dialog=document.getElementById('maintenance-photo-gallery');
    if (!dialog) return;
    const image=dialog.querySelector('[data-gallery-image]');
    const thumbs=dialog.querySelector('.photo-gallery-thumbs');
    const prev=dialog.querySelector('[data-gallery-prev]');
    const next=dialog.querySelector('[data-gallery-next]');
    const imageStatus=document.createElement('p');imageStatus.className='photo-gallery-status';imageStatus.setAttribute('role','status');imageStatus.hidden=true;image.after(imageStatus);
    image.addEventListener('load',()=>{imageStatus.hidden=true;});
    image.addEventListener('error',()=>{image.hidden=true;imageStatus.hidden=false;imageStatus.textContent='No se pudo cargar esta foto. Cierra y vuelve a intentarlo.';});
    let photos=[],index=0,start=null,bodyStyle='',scrollX=0,scrollY=0;
    function render() {
        const photo=photos[index];
        image.hidden=false;imageStatus.hidden=false;imageStatus.textContent='Cargando foto…';
        image.src=photo.url; image.alt=photo.caption || 'Foto del mantenimiento';
        dialog.querySelector('[data-gallery-counter]').textContent=`${index+1} de ${photos.length}`;
        dialog.querySelector('[data-gallery-caption]').textContent=photo.caption || '';
        prev.disabled=index===0;next.disabled=index===photos.length-1;
        [...thumbs.children].forEach((button,i)=>{button.classList.toggle('selected',i===index);button.setAttribute('aria-current',String(i===index));});
        const thumb=thumbs.children[index];
        if(thumb) thumbs.scrollLeft=Math.max(0,thumb.offsetLeft-thumbs.offsetLeft-(thumbs.clientWidth-thumb.clientWidth)/2);
    }
    function move(step) {index=Math.max(0,Math.min(photos.length-1,index+step));render();}
    let opener=null;
    function show(items, selected, title, source) {
        if (!items.length) return;
        photos=items;index=selected;opener=source;thumbs.replaceChildren();
        dialog.querySelector('[data-gallery-heading]').textContent=title || 'Fotos del mantenimiento';
        photos.forEach((photo,i)=>{
            const thumb=document.createElement('button');thumb.type='button';thumb.setAttribute('aria-label',`Ver foto ${i+1}`);
            const img=document.createElement('img');img.src=photo.thumbnail || photo.url;img.alt='';img.loading='lazy';thumb.append(img);
            thumb.addEventListener('click',()=>{index=i;render();});thumbs.append(thumb);
        });
        scrollX=window.scrollX;scrollY=window.scrollY;bodyStyle=document.body.style.cssText;
        Object.assign(document.body.style,{position:'fixed',top:`-${scrollY}px`,left:`-${scrollX}px`,width:'100%',overflow:'hidden'});
        document.documentElement.classList.add('photo-gallery-open');
        window.dispatchEvent(new CustomEvent('kardex-gallery-state',{detail:{open:true}}));
        // Allow the form's focus trap to release before opening the top-layer viewer.
        requestAnimationFrame(()=>{dialog.showModal();render();});
    }
    document.addEventListener('click',async event=>{
        if(event.defaultPrevented || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey)return;
        const button=event.target.closest('[data-photo-gallery]');
        const link=event.target.closest('.detail-photos a,.activity-thumbnails a,.activity-thumbnails .pending-photo>img');
        if (!button && !link) return;
        event.preventDefault();event.stopPropagation();
        if(link) {
            const group=link.closest('.detail-photos,.activity-thumbnails');
            const sources=[...group.querySelectorAll('a,figure>img')].filter(el=>el.tagName==='IMG'||el.querySelector('img'));
            const items=sources.map(el=>{const img=el.tagName==='IMG'?el:el.querySelector('img');return {url:el.tagName==='A'?el.href:img.src,thumbnail:img.src,caption:img.alt || 'Foto del mantenimiento'};});
            show(items,Math.max(0,sources.indexOf(link)),'Fotos del Kardex',link);return;
        }
        button.disabled=true;
        let items;
        try {
            const response=await fetch(button.dataset.photoGallery,{headers:{Accept:'application/json'},credentials:'same-origin'});
            if(!response.ok)throw new Error();
            items=await response.json();
            if(!Array.isArray(items))throw new Error();
        } catch {
            window.kardexAlert?.({icon:'error',title:'No se pudieron cargar las fotos',text:'Intenta nuevamente.'});return;
        } finally {button.disabled=false;}
        show(items,0,button.dataset.galleryTitle,button);
    });
    prev.addEventListener('click',()=>move(-1));next.addEventListener('click',()=>move(1));
    dialog.querySelector('[data-gallery-close]').addEventListener('click',()=>dialog.close());
    dialog.addEventListener('click',event=>{if(event.target===dialog)dialog.close();});
    dialog.addEventListener('close',()=>{document.body.style.cssText=bodyStyle;document.documentElement.classList.remove('photo-gallery-open');window.scrollTo({left:scrollX,top:scrollY,behavior:'instant'});image.removeAttribute('src');thumbs.replaceChildren();photos=[];imageStatus.hidden=true;window.dispatchEvent(new CustomEvent('kardex-gallery-state',{detail:{open:false}}));requestAnimationFrame(()=>{if(opener?.isConnected)opener.focus();});});
    dialog.addEventListener('keydown',event=>{if(event.key==='ArrowLeft'||event.key==='ArrowRight'){event.preventDefault();move(event.key==='ArrowLeft'?-1:1);}});
    const stage=dialog.querySelector('.photo-gallery-stage');
    stage.addEventListener('touchstart',event=>{const t=event.touches[0];start={x:t.clientX,y:t.clientY};},{passive:true});
    stage.addEventListener('touchmove',event=>{if(event.touches.length===1)event.preventDefault();},{passive:false});
    stage.addEventListener('touchcancel',()=>{start=null;},{passive:true});
    stage.addEventListener('touchend',event=>{if(!start)return;const t=event.changedTouches[0],dx=t.clientX-start.x,dy=t.clientY-start.y;if(Math.abs(dx)>45&&Math.abs(dx)>Math.abs(dy))move(dx<0?1:-1);start=null;},{passive:true});
})();
