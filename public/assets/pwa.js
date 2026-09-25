(() => {
  if(!('serviceWorker' in navigator))return;
  const current=document.querySelector('meta[name="app-version"]')?.content;
  let controlled=!!navigator.serviceWorker.controller,reloading=false,registration;
  const reload=()=>{if(reloading)return;reloading=true;location.reload();};
  navigator.serviceWorker.addEventListener('controllerchange',()=>{if(!controlled){controlled=true;return;}reload();});
  const activateWaiting=()=>{if(registration?.waiting)registration.waiting.postMessage({type:'SKIP_WAITING'});};
  const check=async()=>{
    try{
      const response=await fetch('/version.php?current='+encodeURIComponent(current||''),{cache:'no-store',headers:{Accept:'application/json'}});
      const data=await response.json();if(!response.ok||!data.version||data.version===current)return;
      await registration?.update();activateWaiting();setTimeout(reload,1500);
    }catch{}
  };
  addEventListener('load',async()=>{
    try{registration=await navigator.serviceWorker.register('/sw.js',{scope:'/',updateViaCache:'none'});activateWaiting();await registration.update();await check();}catch{}
  });
  document.addEventListener('visibilitychange',()=>{if(!document.hidden)check();});
  setInterval(check,300000);
})();
