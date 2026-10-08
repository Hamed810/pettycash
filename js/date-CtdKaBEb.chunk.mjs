function t(e){return e==null||e===""?"—":new Date(e).toLocaleDateString("fa-IR")}function a(e){const u=t(e.firstExpenseDate),n=t(e.lastExpenseDate);return u==="—"&&n==="—"?"—":u==="—"?`… ${n}`:n==="—"?`${u} …`:`${u} – ${n}`}export{a,t as f};
//# sourceMappingURL=date-CtdKaBEb.chunk.mjs.map
