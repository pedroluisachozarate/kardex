import fs from 'node:fs';
import {createHash} from 'node:crypto';
import {transform} from 'esbuild';
import postcss from 'postcss';
const files=['public/css/kardex.css','public/css/kardex-theme.css'];
const source=files.map(file=>fs.readFileSync(file,'utf8')).join('\n');
const root=postcss.parse(source);
root.walkRules(rule=>{
 let previous=null;
 rule.walkDecls(decl=>{
   if(previous && previous.prop===decl.prop && previous.value===decl.value && previous.important===decl.important)decl.remove();
   else previous=decl;
 });
});
const result=await transform(root.toString(),{loader:'css',minify:true,target:['chrome100','safari15']});
fs.writeFileSync('public/css/kardex.bundle.min.css',result.code);
fs.writeFileSync('public/css/kardex.bundle.manifest.json',JSON.stringify(files.map(file=>createHash('sha256').update(fs.readFileSync(file)).digest('hex'))));
console.log(`${Buffer.byteLength(source)} -> ${Buffer.byteLength(result.code)} bytes CSS`);
