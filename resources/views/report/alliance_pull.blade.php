@extends('layouts.app')
@section('title', 'Alliance Price Pull')

@section('content')
<div style="max-width:900px;margin:0 auto;padding:24px 16px 60px;color:#1F1B16;">
    <h1 style="font-size:26px;font-weight:700;margin:0 0 8px;">Alliance price pull</h1>
    <p style="color:#6B6155;font-size:15px;">Alliance blocks our server from logging in, so this runs in your browser while you're logged into WebAMI. It asks WebAMI for your price on every barcode we carry and saves them to the Alliance price list in the ERP (Distributor prices column).</p>
    <ol style="font-size:15px;line-height:1.8;">
        <li>Drag this button to your bookmarks bar (one time): <a id="apLink" href="#" style="display:inline-block;background:#1F1B16;color:#FFE8A3;padding:6px 14px;border-radius:8px;font-weight:700;text-decoration:none;">Pull Alliance prices</a></li>
        <li>Log into <a href="https://webami.aent.com" target="_blank">webami.aent.com</a>.</li>
        <li>Click <b>Pull Alliance prices</b> in your bookmarks bar while on WebAMI. A box shows progress; keep the tab open until it says done (a few minutes).</li>
    </ol>
    <p style="color:#6B6155;font-size:13px;">Run it whenever you want fresh Alliance prices (weekly is plenty). Keep this button private: it can write Alliance prices into the ERP.</p>
</div>
@endsection

@section('javascript')
<script>
(function () {
    var base = @json(url('/supplier-harvest'));
    var token = @json($token);
    var code = "(async()=>{"
      + "if(location.host!=='webami.aent.com'){alert('Open webami.aent.com and log in first, then click this again.');return;}"
      + "const B=" + JSON.stringify(base) + ",T=" + JSON.stringify(token) + ";"
      + "const box=document.createElement('div');box.style.cssText='position:fixed;top:12px;right:12px;z-index:999999;background:#1F1B16;color:#FFE8A3;padding:14px 18px;border-radius:10px;font:600 15px system-ui;box-shadow:0 4px 18px rgba(0,0,0,.3)';box.textContent='Alliance pull: getting our barcodes...';document.body.appendChild(box);"
      + "try{const L=await fetch(B+'/upcs?token='+T).then(r=>r.json());if(!L.success)throw new Error(L.msg||'ERP said no');const U=L.upcs;let got=[],done=0,saved=0;"
      + "for(let i=0;i<U.length;i+=50){const ids=U.slice(i,i+50);"
      + "const r=await fetch('/ajax/priceavail',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8','X-Requested-With':'XMLHttpRequest'},body:'ids='+encodeURIComponent(ids.join('|'))});"
      + "if(r.status===401||r.status===403)throw new Error('WebAMI says you are logged out');"
      + "const j=await r.json().catch(()=>[]);(Array.isArray(j)?j:[]).forEach(x=>{if(x&&x.Id&&x.Price&&!x.Error){got.push({upc:x.Id,cost:String(x.Price).replace(/[^0-9.]/g,''),qty:parseInt(x.Qty)||0});}});"
      + "done+=ids.length;box.textContent='Alliance pull: checked '+done+' of '+U.length+', '+got.length+' priced';"
      + "if(got.length>=500||i+50>=U.length){const s=await fetch(B+'/upload/alliance?token='+T,{method:'POST',headers:{'Content-Type':'text/plain'},body:JSON.stringify(got)}).then(r=>r.json());if(!s.success)throw new Error(s.msg||'save failed');saved+=s.saved;got=[];}"
      + "await new Promise(z=>setTimeout(z,250));}"
      + "box.textContent='Done: '+saved+' Alliance prices saved to the ERP. You can close this.';}"
      + "catch(e){box.style.background='#B71C1C';box.style.color='#fff';box.textContent='Alliance pull stopped: '+e.message;}"
      + "})();";
    document.getElementById('apLink').setAttribute('href', 'javascript:' + encodeURIComponent(code));
})();
</script>
@endsection
