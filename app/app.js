let parts=[];
let movements=[];
let users=[];
const $=selector=>document.querySelector(selector);
const $$=selector=>[...document.querySelectorAll(selector)];
const csrf=$('meta[name="a12-csrf"]').content;
const currentRole=$('meta[name="a12-role"]').content;
const currentUserId=+$('meta[name="a12-user-id"]').content;
const canManageParts=['admin','storekeeper'].includes(currentRole);
const roleLabels={admin:'Administrator',storekeeper:'Lagerist',member:'Mitglied'};
const roleDescriptions={admin:'Voller Zugriff auf Bestand, Benutzer und Exporte.',storekeeper:'Kann Bauteile anlegen sowie Bestände ein- und auslagern.',member:'Kann den Bestand ansehen und Bauteile entnehmen.'};

const esc=value=>String(value??'').replace(/[&<>"']/g,character=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[character]));
const setTheme=dark=>{document.body.classList.toggle('dark',dark);localStorage.setItem('a12-theme',dark?'dark':'light');$('#themeIcon').textContent=dark?'☀':'☾';$('#themeLabel').textContent=dark?'Light-Mode':'Dark-Mode';$('#themeToggle').setAttribute('aria-label',dark?'Light-Mode einschalten':'Dark-Mode einschalten')};
const sheetUrl=part=>`https://www.google.com/search?q=${encodeURIComponent(`${part.manufacturer||''} ${part.name} datasheet filetype:pdf`)}`;

async function request(action,{method='GET',body,query={}}={}){
  const options={method,credentials:'same-origin',headers:{Accept:'application/json'}};
  if(body!==undefined){options.headers['Content-Type']='application/json';options.headers['X-CSRF-Token']=csrf;options.body=JSON.stringify(body)}
  const parameters=new URLSearchParams({action,...query});
  const response=await fetch(`api.php?${parameters}`,options);
  let payload;
  try{payload=await response.json()}catch{payload={error:'Der Server hat eine ungültige Antwort geliefert.'}}
  if(response.status===401){location.reload();throw new Error('Die Sitzung ist abgelaufen.')}
  if(!response.ok)throw new Error(payload.error||'Die Anfrage ist fehlgeschlagen.');
  return payload;
}

async function requestForm(action,formData){
  const response=await fetch(`api.php?${new URLSearchParams({action})}`,{method:'POST',credentials:'same-origin',headers:{Accept:'application/json','X-CSRF-Token':csrf},body:formData});
  let payload;
  try{payload=await response.json()}catch{payload={error:'Der Server hat eine ungültige Antwort geliefert.'}}
  if(response.status===401){location.reload();throw new Error('Die Sitzung ist abgelaufen.')}
  if(!response.ok)throw new Error(payload.error||'Die Anfrage ist fehlgeschlagen.');
  return payload;
}

function useSnapshot(snapshot){
  parts=Array.isArray(snapshot.parts)?snapshot.parts:[];
  movements=Array.isArray(snapshot.movements)?snapshot.movements:[];
  users=Array.isArray(snapshot.users)?snapshot.users:users;
  initCategories();
  render();
}

function render(){
  const query=$('#search').value.toLowerCase();
  const selectedCategory=$('#category').value;
  const shown=parts.filter(part=>(!selectedCategory||part.category===selectedCategory)&&[part.name,part.manufacturer,part.value,part.drawer].join(' ').toLowerCase().includes(query));
  $('#partsBody').innerHTML=shown.map(part=>`<tr><td><span class="partName">${esc(part.name)}</span><span class="sub">${esc(part.manufacturer||part.value)}</span></td><td><span class="tag">${esc(part.category)}</span></td><td><span class="drawer">${esc(part.drawer)}</span></td><td><span class="stock ${part.quantity<=part.minimum?'low':''}">${part.quantity} Stk.</span><span class="bar"><i class="${part.quantity<=part.minimum?'low':''}" style="width:${Math.min(100,part.quantity/Math.max(part.minimum,1)*50)}%"></i></span></td><td><a class="datasheet" href="${esc(part.datasheet||sheetUrl(part))}" target="_blank" rel="noopener">${part.datasheet?'↗ PDF öffnen':'⌕ suchen'}</a></td><td><button class="rowMenu" data-detail="${part.id}" aria-label="Details öffnen">•••</button></td></tr>`).join('');
  $('#empty').style.display=shown.length?'none':'block';
  $('#statParts').textContent=parts.length;
  $('#statUnits').textContent=parts.reduce((sum,part)=>sum+part.quantity,0).toLocaleString('de-DE');
  $('#statLow').textContent=parts.filter(part=>part.quantity<=part.minimum).length;
  $('#statDrawers').textContent=new Set(parts.map(part=>part.drawer)).size;
  renderDrawers();
  renderMovements();
  renderUsers();
}

function initCategories(){
  const current=$('#category').value;
  const categories=[...new Set(parts.map(part=>part.category))].sort();
  $('#category').innerHTML='<option value="">Alle Kategorien</option>'+categories.map(category=>`<option>${esc(category)}</option>`).join('');
  if(categories.includes(current))$('#category').value=current;
}

function renderDrawers(){
  const drawers={};
  parts.forEach(part=>(drawers[part.drawer]??=[]).push(part));
  $('#drawerGrid').innerHTML=Object.keys(drawers).sort().map(drawer=>`<article class="drawerCard"><strong>${esc(drawer)}</strong><span class="count">${drawers[drawer].length} Position${drawers[drawer].length===1?'':'en'}</span><ul>${drawers[drawer].map(part=>`<li><span>${esc(part.name)}</span><b>${part.quantity}</b></li>`).join('')}</ul></article>`).join('')||'<div class="emptyState">Noch keine Schubladen belegt.</div>';
}

function formatDate(value){
  if(!value)return '–';
  const normalized=value.includes('T')?value:`${value.replace(' ','T')}Z`;
  const date=new Date(normalized);
  return Number.isNaN(date.getTime())?value:new Intl.DateTimeFormat('de-DE',{dateStyle:'short',timeStyle:'short'}).format(date);
}

function renderMovements(){
  $('#movementBody').innerHTML=movements.length?movements.map(movement=>`<tr><td>${esc(formatDate(movement.date))}</td><td><b>${esc(movement.name)}</b></td><td><span class="tag">${esc(movement.type)}</span></td><td>${movement.delta>0?'+':''}${movement.delta}</td><td>${movement.stock}</td><td>${esc(movement.actor||'Altsystem')}</td></tr>`).join(''):'<tr><td colspan="6" class="tableEmpty">Noch keine Bestandsbewegungen.</td></tr>';
}

function renderUsers(){
  const body=$('#usersBody');
  if(!body)return;
  body.innerHTML=users.map(user=>`<tr><td><span class="partName">${esc(user.username)}</span>${user.id===currentUserId?'<span class="sub">Eigenes Konto</span>':''}</td><td><span class="roleBadge role-${esc(user.role)}">${esc(roleLabels[user.role]||user.role)}</span></td><td><span class="userStatus ${user.active?'active':'inactive'}">${user.active?'Aktiv':'Deaktiviert'}</span></td><td>${esc(formatDate(user.lastLoginAt))}</td><td><button class="rowMenu" data-edit-user="${user.id}" aria-label="Benutzer bearbeiten">•••</button></td></tr>`).join('');
}

let toastTimer;
function toast(message,isError=false){
  clearTimeout(toastTimer);
  const element=$('#toast');
  element.textContent=message;
  element.classList.toggle('errorToast',isError);
  element.classList.add('show');
  toastTimer=setTimeout(()=>element.classList.remove('show'),3000);
}

function openDetail(id){
  const part=parts.find(candidate=>candidate.id===id);
  if(!part)return;
  const deleteButton=currentRole==='admin'?`<button class="danger" data-delete="${part.id}">Löschen</button>`:'<span class="detailSpacer"></span>';
  const addButton=canManageParts?`<button class="primary" data-adjust="${part.id}">＋ Einlagern</button>`:'';
  $('#detailContent').innerHTML=`<div class="detailTop"><div><p class="eyebrow">${esc(part.category)}</p><h2>${esc(part.name)}</h2><span class="sub">${esc(part.manufacturer||'Hersteller nicht angegeben')}</span></div><button class="close" data-close-detail>×</button></div><div class="detailMeta"><div><small>Wert / Bauform</small><b>${esc(part.value||'–')}</b></div><div><small>Schublade</small><b class="drawer">${esc(part.drawer)}</b></div><div><small>Bestand</small><b>${part.quantity} Stück</b></div><div><small>Mindestbestand</small><b>${part.minimum} Stück</b></div></div><div class="dataCard"><small>DATENBLATT</small><br><a target="_blank" rel="noopener" href="${esc(part.datasheet||sheetUrl(part))}">${part.datasheet?'Hersteller-PDF öffnen ↗':'Automatische Websuche öffnen ↗'}</a></div><div class="detailAdjust">${deleteButton}<label>Menge<input id="adjustQty" type="number" min="1" max="100000000" value="1"></label><button data-adjust="-${part.id}">− Entnehmen</button>${addButton}</div>`;
  $('#detailDialog').showModal();
}

async function adjust(id,sign){
  const quantity=Math.max(1,+$('#adjustQty').value||1);
  try{
    setDetailBusy(true);
    useSnapshot(await request('adjust-stock',{method:'POST',body:{id,delta:quantity*sign}}));
    $('#detailDialog').close();
    toast(sign>0?`${quantity} Stück eingelagert`:`${quantity} Stück entnommen`);
  }catch(error){toast(error.message,true)}finally{setDetailBusy(false)}
}

async function removePart(id){
  if(!confirm('Diesen Lagerposten wirklich löschen? Die Bewegungen bleiben im Journal erhalten.'))return;
  try{
    setDetailBusy(true);
    useSnapshot(await request('delete-part',{method:'POST',body:{id}}));
    $('#detailDialog').close();
    toast('Bauteil gelöscht');
  }catch(error){toast(error.message,true)}finally{setDetailBusy(false)}
}

function setDetailBusy(busy){$$('#detailContent button,#detailContent input').forEach(element=>element.disabled=busy)}

function openUserDialog(user=null){
  const form=$('#userForm');
  form.reset();
  form.elements.id.value=user?.id||'';
  form.elements.username.value=user?.username||'';
  form.elements.username.disabled=Boolean(user);
  form.elements.role.value=user?.role||'member';
  form.elements.active.value=user?.active===false?'0':'1';
  form.querySelector('.activeField').hidden=!user;
  form.elements.password.required=!user;
  $('#passwordHint').textContent=user?'(nur zum Ändern)':'*';
  $('#userDialogTitle').textContent=user?'Benutzer bearbeiten':'Benutzer anlegen';
  updateRoleInfo();
  $('#userDialog').showModal();
}

function updateRoleInfo(){
  if($('#roleInfo'))$('#roleInfo').textContent=roleDescriptions[$('#userForm').elements.role.value]||'';
}

function safeGithubUrl(value){
  try{const url=new URL(value);return url.protocol==='https:'&&url.hostname==='github.com'?url.href:null}catch{return null}
}

function renderUpdateStatus(status){
  if(currentRole!=='admin'||!$('#updateHeadline'))return;
  const checked=status.checkedAt?formatDate(status.checkedAt):'noch nicht geprüft';
  $('#updateChecked').textContent=`Zuletzt geprüft: ${checked}${status.cached?' · Cache':''}`;
  $('#releaseNotes').hidden=true;
  $('#updateActions').hidden=true;
  $('#prepareUpdateBtn').hidden=true;
  $('#updateBanner').hidden=true;
  $('#updateDot').hidden=true;

  if(status.error&&!status.latestVersion){
    $('#updateHeadline').textContent='Prüfung nicht möglich';
    $('#updateMessage').textContent=status.error;
    return;
  }
  if(!status.latestVersion){
    $('#updateHeadline').textContent='Noch kein Release vorhanden';
    $('#updateMessage').textContent=status.releaseNotes||'Auf GitHub wurde noch kein Release veröffentlicht.';
    return;
  }

  $('#updateHeadline').textContent=status.updateAvailable?`Version ${status.latestVersion} verfügbar`:'A12-Teilchenbeschleuniger ist aktuell';
  $('#updateMessage').textContent=status.updateAvailable?`Installiert ist Version ${status.currentVersion}.`:`Installierte Version ${status.currentVersion} entspricht dem aktuellen Release.`;
  if(status.releaseNotes){$('#releaseNotes').textContent=status.releaseNotes;$('#releaseNotes').hidden=false}
  const releaseUrl=safeGithubUrl(status.releaseUrl);
  const updaterUrl=safeGithubUrl(status.updaterUrl);
  if(releaseUrl){$('#releaseLink').href=releaseUrl;$('#releaseLink').hidden=false}else{$('#releaseLink').hidden=true}
  if(updaterUrl){$('#updaterLink').href=updaterUrl;$('#updaterLink').hidden=false}else{$('#updaterLink').hidden=true}
  $('#prepareUpdateBtn').hidden=!(status.updateAvailable&&updaterUrl);
  $('#updateActions').hidden=!(releaseUrl||updaterUrl||status.updateAvailable);
  if(status.updateAvailable){
    $('#updateBannerText').textContent=`Version ${status.latestVersion} kann installiert werden.`;
    $('#updateBanner').hidden=false;
    $('#updateDot').hidden=false;
  }
}

async function checkUpdates(force=false){
  if(currentRole!=='admin')return;
  const button=$('#checkUpdateBtn');
  if(button)button.disabled=true;
  try{renderUpdateStatus(await request('update-status',{query:force?{force:'1'}:{}}))}
  catch(error){renderUpdateStatus({currentVersion:'',error:error.message,checkedAt:new Date().toISOString()})}
  finally{if(button)button.disabled=false}
}

async function prepareUpdate(){
  const button=$('#prepareUpdateBtn');
  if(!button)return;
  const label=button.textContent;
  button.disabled=true;
  button.textContent='Updater wird geprüft …';
  try{
    const prepared=await request('prepare-update',{method:'POST',body:{}});
    toast(`Updater ${prepared.version} ist bereit`);
    location.href=prepared.url;
  }catch(error){
    toast(error.message,true);
    button.disabled=false;
    button.textContent=label;
  }
}

$$('.nav').forEach(nav=>nav.addEventListener('click',()=>{
  $$('.nav,.view').forEach(element=>element.classList.remove('active'));
  nav.classList.add('active');
  $('#'+nav.dataset.view).classList.add('active');
  $('#viewTitle').textContent={inventory:'Bauteile',drawers:'Schubladen',movements:'Bestandsbewegungen',users:'Benutzerverwaltung',system:'System & Updates'}[nav.dataset.view];
  $('#addBtn').hidden=!(nav.dataset.view==='inventory'&&canManageParts);
  $('#addUserBtn').hidden=!(nav.dataset.view==='users'&&currentRole==='admin');
}));

$('#partsBody').addEventListener('click',event=>{
  const button=event.target.closest('[data-detail]');
  if(button)openDetail(+button.dataset.detail);
});

$('#detailContent').addEventListener('click',event=>{
  const close=event.target.closest('[data-close-detail]');
  if(close){$('#detailDialog').close();return}
  const deletion=event.target.closest('[data-delete]');
  if(deletion){removePart(+deletion.dataset.delete);return}
  const adjustment=event.target.closest('[data-adjust]');
  if(adjustment){const encoded=+adjustment.dataset.adjust;adjust(Math.abs(encoded),Math.sign(encoded))}
});

$('#addBtn').addEventListener('click',()=>{$('#partForm').reset();$('#partDialog').showModal()});
$('#addUserBtn').addEventListener('click',()=>openUserDialog());
$('#themeToggle').addEventListener('click',()=>setTheme(!document.body.classList.contains('dark')));
$('#search').addEventListener('input',render);
$('#category').addEventListener('change',render);
$$('[data-close-dialog]').forEach(button=>button.addEventListener('click',()=>{
  const dialog=document.getElementById(button.dataset.closeDialog);
  if(dialog?.open)dialog.close();
}));

if($('#usersBody'))$('#usersBody').addEventListener('click',event=>{
  const button=event.target.closest('[data-edit-user]');
  if(button)openUserDialog(users.find(user=>user.id===+button.dataset.editUser));
});
if($('#userForm'))$('#userForm').elements.role.addEventListener('change',updateRoleInfo);
if($('#checkUpdateBtn'))$('#checkUpdateBtn').addEventListener('click',()=>checkUpdates(true));
if($('#prepareUpdateBtn'))$('#prepareUpdateBtn').addEventListener('click',prepareUpdate);
if($('[data-open-system]'))$('[data-open-system]').addEventListener('click',()=>document.querySelector('.nav[data-view="system"]').click());
if($('#backupBtn'))$('#backupBtn').addEventListener('click',()=>{location.href='api.php?action=backup'});
if($('#restoreOpenBtn'))$('#restoreOpenBtn').addEventListener('click',()=>{$('#restoreForm').reset();$('#restoreDialog').showModal()});
if($('#resetOpenBtn'))$('#resetOpenBtn').addEventListener('click',()=>{$('#resetForm').reset();$('#resetDialog').showModal()});

if($('#restoreForm'))$('#restoreForm').addEventListener('submit',async event=>{
  event.preventDefault();
  const form=event.currentTarget;
  const data=new FormData(form);
  if(data.get('password')!==data.get('password_confirm')){toast('Die beiden Passworteingaben stimmen nicht überein.',true);return}
  const button=$('#restoreBtn');
  const label=button.textContent;
  button.disabled=true;
  button.textContent='Backup wird geprüft …';
  try{
    const result=await requestForm('restore-backup',data);
    $('#restoreDialog').close();
    if(result.loggedOut){location.href='index.php';return}
    location.reload();
  }catch(error){toast(error.message,true);button.disabled=false;button.textContent=label}
});

if($('#resetForm'))$('#resetForm').addEventListener('submit',async event=>{
  event.preventDefault();
  const form=event.currentTarget;
  const data=new FormData(form);
  if(data.get('password')!==data.get('password_confirm')){toast('Die beiden Passworteingaben stimmen nicht überein.',true);return}
  const button=$('#resetConfirmBtn');
  const label=button.textContent;
  button.disabled=true;
  button.textContent='System wird zurückgesetzt …';
  try{
    await request('reset-system',{method:'POST',body:{mode:data.get('mode'),password:data.get('password'),passwordConfirm:data.get('password_confirm')}});
    $('#resetDialog').close();
    location.reload();
  }catch(error){toast(error.message,true);button.disabled=false;button.textContent=label}
});

$('#partForm').addEventListener('submit',async event=>{
  event.preventDefault();
  const form=event.currentTarget;
  const data=new FormData(form);
  const button=$('#savePartBtn');
  button.disabled=true;
  try{
    useSnapshot(await request('create-part',{method:'POST',body:{name:data.get('name').trim(),manufacturer:data.get('manufacturer').trim(),category:data.get('category'),value:data.get('value').trim(),drawer:data.get('drawer').trim(),quantity:+data.get('quantity'),minimum:+data.get('minimum')}}));
    $('#partDialog').close();
    toast(data.get('autoDatasheet')?'Bauteil gespeichert · Datenblatt-Suche vorbereitet':'Bauteil gespeichert');
  }catch(error){toast(error.message,true)}finally{button.disabled=false}
});

if($('#userForm'))$('#userForm').addEventListener('submit',async event=>{
  event.preventDefault();
  const form=event.currentTarget;
  const data=new FormData(form);
  const id=+form.elements.id.value;
  const button=$('#saveUserBtn');
  button.disabled=true;
  try{
    const body=id?{id,role:data.get('role'),active:data.get('active')==='1',password:data.get('password')}:{username:data.get('username').trim(),role:data.get('role'),password:data.get('password')};
    useSnapshot(await request(id?'update-user':'create-user',{method:'POST',body}));
    $('#userDialog').close();
    toast(id?'Benutzer aktualisiert':'Benutzer angelegt');
  }catch(error){toast(error.message,true)}finally{button.disabled=false}
});

$('#exportBtn').addEventListener('click',()=>{
  location.href='api.php?action=export';
});

setTheme(localStorage.getItem('a12-theme')==='dark'||(!localStorage.getItem('a12-theme')&&matchMedia('(prefers-color-scheme: dark)').matches));
request('snapshot').then(useSnapshot).catch(error=>toast(error.message,true));
if(currentRole==='admin'){
  setTimeout(()=>checkUpdates(false),1500);
  setInterval(()=>checkUpdates(false),21600000);
}
