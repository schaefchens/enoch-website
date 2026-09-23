(() => {
  const root=document.documentElement;
  const apply=()=>{root.dataset.effectiveTheme=root.dataset.theme==='system'?(matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light'):root.dataset.theme;};
  apply();matchMedia('(prefers-color-scheme: dark)').addEventListener('change',apply);
  document.addEventListener('change',e=>{
    if(!e.target.matches('[data-preference]'))return;
    const key=e.target.dataset.preference,value=e.target.value;
    const allowed=key==='theme'?['system','dark','light']:['system','de','en'];if(!allowed.includes(value))return;
    document.cookie='enoch_'+key+'='+value+'; Path=/; Max-Age=31536000; SameSite=Strict'+(location.protocol==='https:'?'; Secure':'');
    if(key==='theme'){root.dataset.theme=value;apply();}else location.reload();
  });
})();
