import{s}from"./pettycash-main.mjs";async function i(t,a=1,r=25){const n=new URLSearchParams;return n.set("page",String(a)),n.set("pageSize",String(r)),s(`/transactions/${t}/audit?${n.toString()}`)}async function o(t){return s(`/transactions/${t}/revisions`)}export{o as a,i as l};
//# sourceMappingURL=auditApi-CCLWZPG6.chunk.mjs.map
