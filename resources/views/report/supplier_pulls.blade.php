@extends('layouts.app')
@section('title', 'Supplier Price Pulls')

@section('content')
<div style="max-width:960px;margin:0 auto;padding:24px 16px 60px;color:#1F1B16;">
    <h1 style="font-size:26px;font-weight:700;margin:0 0 8px;">Supplier price pulls</h1>
    <p style="color:#6B6155;font-size:15px;">AMS refreshes on its own every Sunday at 11pm. These four suppliers block our server from logging in, so their prices are pulled in your browser while you're logged into their site. A Sunday 11pm job runs all four automatically if Chrome is open and you're logged in. To run one by hand: open the supplier's site, log in, then click its button in your bookmarks bar.</p>
    <table style="width:100%;border-collapse:collapse;font-size:15px;margin-top:14px;">
        <tr><th style="text-align:left;padding:8px;border-bottom:1px solid #E6DCCF;">Supplier</th><th style="text-align:left;padding:8px;border-bottom:1px solid #E6DCCF;">Site to be on</th><th style="text-align:left;padding:8px;border-bottom:1px solid #E6DCCF;">Button (drag to bookmarks bar)</th></tr>
        @foreach ([['alliance','Alliance','https://webami.aent.com/music'],['secretly','Secretly','https://b2b.secretlydistribution.com/'],['redeye','Redeye','https://b2b.redeyeworldwide.com/best-sellers/catalog'],['monostereo','Monostereo','https://newb2b.monostereo1stop.com/search?q=093624967330']] as $s)
        <tr>
            <td style="padding:8px;border-bottom:1px solid #F3ECE1;font-weight:600;">{{ $s[1] }}</td>
            <td style="padding:8px;border-bottom:1px solid #F3ECE1;"><a href="{{ $s[2] }}" target="_blank" class="sp-site" data-key="{{ $s[0] }}">{{ parse_url($s[2], PHP_URL_HOST) }}</a></td>
            <td style="padding:8px;border-bottom:1px solid #F3ECE1;"><a class="sp-pull" data-key="{{ $s[0] }}" href="#" style="display:inline-block;background:#1F1B16;color:#FFE8A3;padding:6px 14px;border-radius:8px;font-weight:700;text-decoration:none;">Pull {{ $s[1] }} prices</a></td>
        </tr>
        @endforeach
    </table>
    <p style="color:#6B6155;font-size:13px;margin-top:14px;">Keep these buttons private: they can write supplier prices into the ERP.</p>
</div>
@endsection

@section('javascript')
<script>
(function () {
    var B = @json(url('/supplier-harvest'));
    var T = @json($token);
    // Shared pieces: an on-page status box + an uploader that sends 500 rows at a time.
    var head = "const B=" + JSON.stringify(B) + ",T=" + JSON.stringify(T) + ";"
      + "const box=document.createElement('div');box.id='nivessaPullBox';box.style.cssText='position:fixed;top:12px;right:12px;z-index:999999;background:#1F1B16;color:#FFE8A3;padding:14px 18px;border-radius:10px;font:600 15px system-ui;box-shadow:0 4px 18px rgba(0,0,0,.3)';document.body.appendChild(box);"
      + "const say=t=>{box.textContent=t;window.__nivessaPull=t;};"
      + "const up=async(sup,rows)=>{let saved=0;for(let i=0;i<rows.length;i+=1000){const s=await fetch(B+'/upload/'+sup+'?token='+T,{method:'POST',headers:{'Content-Type':'text/plain'},body:JSON.stringify(rows.slice(i,i+1000))}).then(r=>r.json());if(!s.success)throw new Error(s.msg||'save failed');saved+=s.saved;}return saved;};";
    var bodies = {
        alliance: "if(location.host!=='webami.aent.com')throw new Error('Open webami.aent.com first');"
          + "const U=(await fetch(B+'/upcs?token='+T).then(r=>r.json())).upcs;let rows=[];"
          + "for(let i=0;i<U.length;i+=50){const r=await fetch('/ajax/priceavail',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8','X-Requested-With':'XMLHttpRequest'},body:'ids='+encodeURIComponent(U.slice(i,i+50).join('|'))});"
          + "const tx=await r.text();if(!tx.trim())throw new Error('WebAMI logged you out');JSON.parse(tx).forEach(x=>{if(x&&x.Id&&x.Price&&!x.Error)rows.push({upc:x.Id,cost:String(x.Price).replace(/[^0-9.]/g,''),qty:parseInt(x.Qty)||0});});"
          + "say('Alliance: checked '+Math.min(U.length,i+50)+' of '+U.length);}"
          + "say('Alliance: saving...');const n=await up('alliance',rows);say('Alliance done: '+n+' prices saved');",
        secretly: "if(location.host!=='b2b.secretlydistribution.com')throw new Error('Open b2b.secretlydistribution.com first');"
          + "const a=[...document.querySelectorAll('a')].find(a=>/Download Catalog/i.test(a.innerText));if(!a)throw new Error('Not logged into Secretly');"
          + "say('Secretly: downloading catalog...');const t=await fetch(a.href,{credentials:'same-origin'}).then(r=>r.text());const L=t.split('\\n');const h=L[0].split('\\t');const ix=n=>h.indexOf(n);let rows=[];"
          + "for(let i=1;i<L.length;i++){const c=L[i].split('\\t');const upc=(c[ix('UPC')]||'').replace(/\\D/g,'');const p=parseFloat(c[ix('Price')]);if(upc.length>=8&&p>0)rows.push({upc,cost:p,artist:c[ix('Artist')],title:c[ix('Title')],format:c[ix('Format')]});}"
          + "say('Secretly: saving '+rows.length+'...');const n=await up('secretly',rows);say('Secretly done: '+n+' prices saved');",
        redeye: "if(location.host!=='b2b.redeyeworldwide.com')throw new Error('Open b2b.redeyeworldwide.com first');"
          + "const ids=(await fetch(B+'/redeye-ids?token='+T).then(r=>r.json())).ids;let rows=[];"
          + "const one=async id=>{const h=await fetch('/products/details/'+id,{credentials:'same-origin'}).then(r=>r.text());if(/name=\"password\"/.test(h)&&!/cart-buy-box/.test(h))throw new Error('Redeye logged you out');"
          + "for(const b of h.split(/<div\\s+class=\"cart-buy-box/).slice(1)){const c=b.match(/Your\\s*cost\\s*:?\\s*\\$?\\s*([0-9]+(?:\\.[0-9]{1,2})?)/i);const u=b.match(/UPC\\s*:?\\s*([0-9]{8,14})/i)||b.match(/EAN\\s*:?\\s*([0-9]{8,14})/i);if(!c||!u)continue;const f=((b.match(/<strong>\\s*([\\s\\S]+?)\\s*<\\/strong>/i)||[])[1]||'').replace(/<[^>]+>/g,'').trim();rows.push({upc:u[1],cost:c[1],format:f,url:'https://b2b.redeyeworldwide.com/products/details/'+id});}};"
          + "for(let i=0;i<ids.length;i+=4){await Promise.all(ids.slice(i,i+4).map(one));say('Redeye: checked '+Math.min(ids.length,i+4)+' of '+ids.length);}"
          + "say('Redeye: saving...');const n=await up('redeye',rows);say('Redeye done: '+n+' prices saved');",
        monostereo: "if(location.host!=='newb2b.monostereo1stop.com')throw new Error('Open newb2b.monostereo1stop.com first');"
          + "const U=(await fetch(B+'/upcs?token='+T).then(r=>r.json())).upcs;let rows=[];"
          + "const one=async ids=>{const h=await fetch('/search?type=product&q='+encodeURIComponent(ids.join(' OR ')),{credentials:'same-origin'}).then(r=>r.text());if(/authentication\\/\\d+\\/login/.test(h))throw new Error('Monostereo logged you out');"
          + "const t=new DOMParser().parseFromString(h,'text/html').body.innerText.replace(/\\s+/g,' ');for(const m of t.matchAll(/Sale price\\s*\\$([0-9.,]+)\\s+(\\d{8,14})\\s+Format\\s*:\\s*([A-Za-z0-9]+)/g))rows.push({upc:m[2],cost:m[1].replace(/,/g,''),format:m[3]});};"
          + "for(let i=0;i<U.length;i+=12){await Promise.all([0,3,6,9].map(o=>U.slice(i+o,i+o+3)).filter(x=>x.length).map(one));say('Monostereo: checked '+Math.min(U.length,i+12)+' of '+U.length);}"
          + "say('Monostereo: saving...');const n=await up('monostereo',rows);say('Monostereo done: '+n+' prices saved');"
    };
    document.querySelectorAll('.sp-pull').forEach(function (a) {
        var code = "(async()=>{" + head + "try{" + bodies[a.getAttribute('data-key')] + "}catch(e){box.style.background='#B71C1C';box.style.color='#fff';say('Stopped: '+e.message);}})();";
        a.setAttribute('href', 'javascript:' + encodeURIComponent(code));
        a.setAttribute('data-code', code);
    });
})();
</script>
@endsection
