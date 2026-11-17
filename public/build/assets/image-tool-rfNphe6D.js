import{t as u}from"./app-DC5dYEJT.js";import{formatBytes as m,readFileAsImage as M,downloadBlob as T}from"./helpers-xiUezYzb.js";import{baseName as j,canvasToBlob as R}from"./helpers-xiUezYzb.js";function q(s,i){const d=s.querySelector("[data-dropzone]"),e=s.querySelector("[data-file-input]"),v=s.querySelector("[data-browse]"),f=s.querySelector("[data-results]"),n=s.querySelector("[data-status]"),g=15*1024*1024;function r(t,a=!1){n&&(n.textContent=t||"",n.classList.toggle("text-red-600",a),n.classList.toggle("text-slate-500",!a))}function p(t){const a=t&&t[0];if(a){if(!a.type.startsWith("image/")){r("Please choose an image file.",!0);return}if(a.size>g){r(`File is too large. Maximum size is ${m(g)}.`,!0);return}M(a).then(o=>{r(""),i.onImage(o,a)}).catch(()=>r("This image could not be read. Try a different file.",!0))}}d?.addEventListener("dragover",t=>{t.preventDefault(),d.classList.add("is-dragover")}),d?.addEventListener("dragleave",()=>d.classList.remove("is-dragover")),d?.addEventListener("drop",t=>{t.preventDefault(),d.classList.remove("is-dragover"),p(t.dataTransfer.files)}),v?.addEventListener("click",()=>e?.click()),d?.addEventListener("click",()=>e?.click()),e?.addEventListener("change",()=>p(e.files));function x({blob:t,filename:a,originalSize:o,extraLines:w=[],previewUrl:y}){const $=y??URL.createObjectURL(t),c=o?Math.max(0,Math.round((1-t.size/o)*100)):null,l=document.createElement("div");l.className="card mt-6 overflow-hidden",l.innerHTML=`
            <div class="grid gap-4 p-5 sm:grid-cols-[180px_1fr]">
                <img src="${$}" alt="Result preview" class="h-36 w-full rounded-xl border border-slate-200 bg-slate-100 object-contain sm:h-40" loading="lazy">
                <div class="flex flex-col justify-center gap-2">
                    <p class="font-semibold text-slate-900">${a}</p>
                    <dl class="grid grid-cols-2 gap-x-6 gap-y-1 text-sm text-slate-600">
                        <dt>Original</dt><dd class="font-medium text-slate-900">${m(o)}</dd>
                        <dt>New</dt><dd class="font-medium text-slate-900">${m(t.size)}</dd>
                        ${w.map(([b,E])=>`<dt>${b}</dt><dd class="font-medium text-slate-900">${E}</dd>`).join("")}
                        ${c!==null?`<dt>Saved</dt><dd class="font-medium ${c>0?"text-emerald-600":"text-slate-900"}">${c}%</dd>`:""}
                    </dl>
                    <div class="mt-2 flex flex-wrap gap-2">
                        <button type="button" class="btn-primary" data-download>Download</button>
                    </div>
                </div>
            </div>`,l.querySelector("[data-download]").addEventListener("click",()=>{T(t,a),u(s.dataset.tool,!0,"download")}),f.innerHTML="",f.appendChild(l)}function h(t){r(t,!0),u(s.dataset.tool,!1)}function L(){u(s.dataset.tool,!0)}return{setStatus:r,renderResult:x,reportError:h,reportSuccess:L}}function I(s,i,d){const e=document.createElement("canvas");return e.width=Math.max(1,Math.round(i)),e.height=Math.max(1,Math.round(d)),e.getContext("2d").drawImage(s,0,0,e.width,e.height),e}export{j as baseName,R as canvasToBlob,q as createImageToolUI,I as scaleTo};
