import {cp,readFile,writeFile,mkdir,rm} from 'node:fs/promises';
import {fileURLToPath} from 'node:url';import path from 'node:path';
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
await mkdir(path.join(root,'public'),{recursive:true});
await rm(path.join(root,'public/assets'),{recursive:true,force:true});
await cp(path.join(root,'frontend/dist/assets'),path.join(root,'public/assets'),{recursive:true});
const html=await readFile(path.join(root,'frontend/dist/index.html'),'utf8');
await writeFile(path.join(root,'public/app.html'),html);
console.log('Built React app copied into LavaLust public/.');
