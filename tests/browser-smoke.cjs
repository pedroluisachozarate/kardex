const { chromium } = require('C:/Users/achoz/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const { spawn, spawnSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const root=path.resolve(__dirname,'..');
const out=path.join(root,'tmp','browser'); fs.mkdirSync(out,{recursive:true});
const database=path.join(out,'qa-'+Date.now()+'.sqlite');fs.writeFileSync(database,'');
const env={...process.env,DB_CONNECTION:'sqlite',DB_DATABASE:database,SESSION_DRIVER:'file',CACHE_STORE:'array'};
function artisan(args){const result=spawnSync('C:/xampp/php/php.exe',['artisan',...args],{cwd:root,env,encoding:'utf8',timeout:120000});if(result.status!==0)throw new Error(result.stdout+result.stderr);return result.stdout;}
artisan(['migrate','--force']);
const output=artisan(['kardex:admin','qa@example.test']);
const password=output.match(/Contraseña temporal: (.+)/)[1].trim();
const port=18000+Math.floor(Math.random()*1000);
const base='http://127.0.0.1:'+port;
const server=spawn('C:/xampp/php/php.exe',['-S','127.0.0.1:'+port,path.join(root,'vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php')],{cwd:path.join(root,'public'),env,stdio:'ignore'});
(async()=>{
 let browser, page; const errors=[];
 try {
  for(let n=0;n<40;n++){try{const res=await fetch(base+'/login');if(res.ok)break;}catch{}await new Promise(r=>setTimeout(r,250));}
  browser=await chromium.launch({headless:true,channel:'msedge'});page=await browser.newPage({viewport:{width:1440,height:1000}});
  page.on('pageerror',e=>errors.push(e.message));page.on('console',m=>{if(m.type()==='error')errors.push(m.text());});page.on('response',r=>{if(r.status()>=400)errors.push(r.status()+' '+r.url());});
  await page.goto(base+'/login');
  await page.getByLabel('Correo electrónico').fill('qa@example.test');await page.getByLabel('Contraseña',{exact:true}).fill(password);
  await page.getByRole('button',{name:'Ingresar',exact:true}).click();await page.waitForURL('**/password').catch(async e=>{console.log(await page.locator('body').innerText());throw e;});
  await page.getByLabel('Contraseña actual').fill(password);await page.getByLabel('Nueva contraseña').fill('ExampleStrong123!');await page.getByLabel('Confirmar contraseña').fill('ExampleStrong123!');
  await page.getByRole('button',{name:'Guardar contraseña'}).click();await page.waitForURL(base+'/');
  await page.goto(base+'/administracion');
  let form;
  await page.evaluate(()=>window.livewireQaMarker='same-document');
  await page.getByRole('button',{name:'+ Nuevo cliente',exact:true}).click();
  await page.getByLabel('Entidad o empresa',{exact:true}).fill('Cliente de prueba');await page.getByRole('button',{name:/^Crear (cliente|ubicación|proyecto)$/,exact:true}).click();
  await page.getByRole('dialog').waitFor({state:'hidden'});
  await page.getByLabel('Buscar registros').fill('sin coincidencias');await page.getByText('Sin coincidencias',{exact:true}).waitFor();
  await page.getByLabel('Buscar registros').fill('Cliente de prueba');await page.getByRole('button',{name:'Editar Cliente de prueba',exact:true}).waitFor();
  await page.getByRole('button',{name:'Editar Cliente de prueba',exact:true}).click();await page.getByLabel('Contacto (opcional)',{exact:true}).fill('Responsable de sede');await page.getByRole('button',{name:'Guardar cambios',exact:true}).click();await page.getByRole('dialog').waitFor({state:'hidden'});
  await page.getByRole('tab',{name:/Proyectos/}).click();await page.getByRole('button',{name:'+ Nuevo proyecto',exact:true}).click();await page.getByLabel('Cliente',{exact:true}).selectOption({label:'Cliente de prueba'});await page.getByRole('button',{name:'Continuar →',exact:true}).click();await page.getByLabel('Nombre del proyecto').fill('Mantenimiento tecnológico');await page.getByLabel('¿Qué servicio se realizará?',{exact:true}).fill('Mantenimiento preventivo y correctivo de equipos');await page.getByRole('button',{name:'Continuar →',exact:true}).click();await page.getByRole('button',{name:/^Crear (cliente|ubicación|proyecto)$/,exact:true}).click();await page.getByRole('dialog').waitFor({state:'hidden'});
  if(await page.evaluate(()=>window.livewireQaMarker)!=='same-document')throw new Error('Unexpected reload');
  await page.screenshot({path:path.join(out,'administration-livewire.png'),fullPage:true});
  await page.setViewportSize({width:390,height:844});await page.getByRole('button',{name:'+ Nuevo proyecto',exact:true}).click();await page.getByRole('dialog').waitFor();const box=await page.getByRole('dialog').boundingBox();if(box.x<0||box.width>390)throw new Error('Modal overflow');await page.screenshot({path:path.join(out,'administration-mobile.png'),fullPage:true});await page.getByRole('button',{name:'Cancelar',exact:true}).click();await page.getByRole('dialog').waitFor({state:'hidden'});await page.setViewportSize({width:1440,height:1000});
  await page.getByRole('link',{name:'Mantenimiento tecnológico',exact:true}).click();await page.waitForURL('**/proyectos/1');
  await page.getByRole('button',{name:'+ Crear tipo de equipo',exact:true}).click();
  form=page.getByRole('dialog');await form.getByLabel('Tipo de equipo',{exact:true}).fill('Aire acondicionado');await form.getByRole('button',{name:'+ Campo',exact:true}).click();await form.getByLabel('Campo',{exact:true}).fill('Capacidad');await form.getByLabel('Formato').selectOption('numero');await form.getByLabel('Unidad',{exact:true}).fill('BTU');await form.getByLabel('Obligatorio').check();await form.getByLabel('Actividades preventivas').fill('Limpieza de filtros\nRevisión eléctrica');await form.getByLabel('Actividades correctivas').fill('Diagnóstico\nReparación');await form.getByRole('button',{name:'Guardar',exact:true}).click();await page.getByRole('dialog').waitFor({state:'hidden'});
  await page.getByRole('button',{name:'+ Agregar equipo',exact:true}).click();form=page.getByRole('dialog');await form.getByLabel('Nombre del equipo').fill('Aire cassette');await form.getByLabel('Marca',{exact:true}).fill('SURE');await form.getByLabel('Número de serie').fill('ABC123');await form.getByLabel('Capacidad (BTU) *').fill('60000');await form.getByRole('button',{name:'Guardar',exact:true}).click();await page.getByRole('dialog').waitFor({state:'hidden'});
  await page.getByRole('button',{name:'+ Crear Kardex',exact:true}).click();await page.getByRole('dialog').getByRole('button',{name:'Crear Kardex',exact:true}).click();await page.waitForURL('**/intervenciones/1');
  await page.getByLabel('Conclusiones',{exact:true}).fill('Equipo operativo');await page.getByLabel('Encargado o fiscal de servicio').fill('Responsable del cliente');await page.getByLabel('Agente de servicio').fill('Técnico de prueba');await page.getByRole('button',{name:'Guardar datos del borrador'}).click();
  for(let n=0;n<2;n++){form=page.locator('.activity').nth(n).locator('form').first();await form.getByLabel('Resultado').selectOption('realizado');await form.getByLabel('Fecha de ejecución').fill('2026-10-17');await form.getByRole('button',{name:'Guardar actividad'}).click();}
  await page.screenshot({path:path.join(out,'intervention-desktop.png'),fullPage:true});
  await page.getByRole('button',{name:'Enviar a revisión'}).click();await page.getByRole('button',{name:'Aprobar y finalizar'}).click();
  if(!await page.locator('.badge.finalizado').count())throw new Error('No finalizó el documento');
  await page.goto(base+'/intervenciones/1/imprimir');await page.pdf({path:path.join(out,'kardex-preventivo-qa.pdf'),format:'A4',printBackground:true});
  await page.goto(base+'/');await page.screenshot({path:path.join(out,'dashboard-desktop.png'),fullPage:true});
  await page.setViewportSize({width:390,height:844});await page.screenshot({path:path.join(out,'dashboard-mobile.png'),fullPage:true});
  if(await page.evaluate(()=>document.documentElement.scrollWidth>window.innerWidth))throw new Error('Desbordamiento horizontal en móvil');
  await page.goto(base+'/intervenciones/1');await page.screenshot({path:path.join(out,'intervention-mobile.png'),fullPage:true});
  if(await page.evaluate(()=>document.documentElement.scrollWidth>window.innerWidth))throw new Error('Desbordamiento horizontal en intervención');
  if(errors.length)throw new Error(errors.join('\n'));
  console.log('Browser QA passed: login, password change, client, location, project, dynamic template, equipment, intervention, activities, review, finalization, print PDF, desktop and mobile.');
 } catch(e) {if(page){console.log('ERRORS',errors);console.log('URL',page.url());console.log((await page.locator('body').innerText()).slice(0,5000));await page.screenshot({path:path.join(out,'failure.png'),fullPage:true});}throw e;} finally {if(browser)await browser.close();server.kill();}
})().catch(e=>{console.error(e);process.exitCode=1;});
