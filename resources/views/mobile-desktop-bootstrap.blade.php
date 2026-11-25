<!doctype html><html><head><meta charset="utf-8"><title>Kardex PC</title></head><body><script>
(() => {const origin=@json($serverOrigin),state=@json($state);const reply=()=>parent.postMessage({type:'kardex-desktop-ready',state},origin);window.addEventListener('message',event=>{if(event.origin===origin&&event.source===parent&&event.data?.type==='kardex-desktop-connect')reply();});reply();})();
</script></body></html>
