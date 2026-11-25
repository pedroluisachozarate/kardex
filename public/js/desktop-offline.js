(() => {
    const frame=document.querySelector('[data-desktop-frame]'),form=document.querySelector('[data-desktop-download]');
    if(!frame||!form)return;
    const origin=new URL(frame.src).origin,status=document.querySelector('[data-desktop-status]'),button=form.querySelector('button'),state=form.querySelector('[name=state]');
    let ready=false;
    const timer=setTimeout(()=>{if(!ready)status.textContent='No se pudo conectar con los módulos de esta PC. Recarga para reintentar.';},15000);
    window.addEventListener('message',event=>{
        if(event.origin!==origin||event.source!==frame.contentWindow||event.data?.type!=='kardex-desktop-ready'||!(/^[A-Za-z0-9]{64}$/).test(event.data.state||''))return;
        state.value=event.data.state;ready=true;clearTimeout(timer);button.disabled=false;status.textContent='Descarga una copia para trabajar desde esta PC.';
    });
    const connect=()=>frame.contentWindow.postMessage({type:'kardex-desktop-connect'},origin);
    frame.addEventListener('load',connect);connect();
    form.addEventListener('submit',event=>{if(!ready){event.preventDefault();status.textContent='Espera a que se conecten los módulos de esta PC.';}else{button.disabled=true;status.textContent='Descargando tus proyectos…';}});
})();
