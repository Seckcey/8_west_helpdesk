/* One private workspace. Incremental provider text, durable tools, no page submit. */
(() => {
  'use strict';
  const root = document.getElementById('portal-chat-root');
  if (!root) return;
  const panel = document.getElementById('portal-chat-panel');
  const home = document.getElementById('portal-chat-home-slot');
  const workspace = root.dataset.workspace === '1';
  const bubble = document.getElementById('portal-chat-bubble');
  const form = document.getElementById('portal-chat-form');
  const input = document.getElementById('portal-chat-input');
  const send = document.getElementById('portal-chat-send');
  const stop = document.getElementById('portal-chat-stop');
  const status = document.getElementById('portal-chat-status');
  const log = document.getElementById('portal-chat-messages');
  const draftBox = document.getElementById('portal-chat-draft');
  const history = document.getElementById('portal-chat-history');
  const devices = document.getElementById('portal-chat-device');
  const jump = document.getElementById('portal-chat-jump');
  const empty = document.getElementById('portal-chat-empty');
  const nodes = new Map();
  let state = null, busy = false, editing = false, pending = null, focusBefore = null, poll = null;
  let streamController = null, currentOperation = null, stickToBottom = true, accessEpoch = 0;
  const labels = { requests:'Open a support request', updates:'Follow a request', response:'Response goals', summaries:'Service summaries', privacy:'Westy and privacy' };
  const errors = {
    ai_unavailable:'Westy is unavailable. You can still contact support.', provider_unavailable:'The reply was interrupted. Saved device work is shown below; it has not been retried.',
    provider_rate_limit:'Westy is busy. Please try a new message later.', provider_refused:'Westy could not answer that. Contact support for help.',
    provider_invalid:'Westy could not finish a usable reply.', interrupted:'This reply was interrupted. Check the device activity before asking again.',
    stopped:'Reply stopped. Device work already dispatched keeps its own recorded status.',
    stop_unconfirmed:'The conversation has stopped. The computer has not confirmed cancellation yet; it will not repeat the command.',
    companion_offline:'Open Westy on that computer and connect it to continue. Background health checks may still be available.',
    companion_ambiguous:'More than one Westy companion is connected for this computer. Disconnect the extra session.',
    run_unavailable:'This saved investigation cannot continue from this session. Check the original chat and its receipts.',
    shell_unavailable:'General computer tools are not available yet. Background checks remain available.',
    hourly_limit:'You have reached the hourly chat limit. Contact support is still available.', daily_limit:'Your business has reached today’s chat limit.', cost_limit:'Your business has reached its chat budget.',
    busy:'A reply is still running. You can stop it or wait.', sign_in:'Your sign-in ended or access changed. Sign in again to continue.',
    identity_unavailable:'Access cannot be verified. Your private chat is hidden until it can be checked.', read_only:'Your role cannot authorize device work or send this request.',
    draft_changed:'This draft changed or was already sent. Check its saved state.', conversation_changed:'The conversation changed in another tab. Check the current chat.',
    sensitive_text:'Remove passwords, secret keys and verification codes before sending.', invalid_message:'Enter a message of up to 2,000 characters.', invalid_request:'Check the required fields.',
    operation_expired:'Refresh this page before sending a new message. If this continues, check your computer clock.',
    unavailable:'The result could not be confirmed. Checking saved work; nothing will be retried automatically.', approval_changed:'The approval changed or expired. Refresh the operation before continuing.',
    tools_unavailable:'Device tools are not available. Your computers and support requests remain accessible.', support_busy:'A technician is handling this computer. Health checks and recorded facts remain available. Once the technician finishes and resolves the case, review and approve the proposed repair again.',
    support_status_unavailable:'Current support ownership could not be verified. The repair was not sent. Health checks and recorded facts remain available; try the approval again shortly.',
    policy_restricted:'Your workspace administrator has disabled this action in Westy settings.', execution_unresolved:'A previous command on this computer needs a confirmed result. Recorded hardware facts remain available with their capture time.',
    device_offline:'The computer is not currently available.', repair_cooldown:'A recent repair is still within its cooldown.', operation_unavailable:'This operation is not available for your current access.',
    service_unavailable:'The service response was not confirmed. This operation will not be retried automatically.'
  };
  const key = () => Array.from(crypto.getRandomValues(new Uint8Array(16)), b => b.toString(16).padStart(2, '0')).join('');
  const messageKey = () => 'f1'+Math.floor(Date.now()/1000).toString(16).padStart(8,'0')+Array.from(crypto.getRandomValues(new Uint8Array(11)),b=>b.toString(16).padStart(2,'0')).join('');
  const element = (tag, text, className) => { const e = document.createElement(tag); if (text !== undefined) e.textContent = text; if (className) e.className = className; return e; };
  const button = (text, action, cls = 'btn-ghost') => { const b = element('button', text, cls); b.type = 'button'; b.addEventListener('click', action); return b; };
  const say = text => { status.textContent = text; };
  function controls() {
    const waiting=state?.turns.some(t=>t.run?.state==='waiting');
    send.hidden = busy||waiting; stop.hidden = !busy&&!waiting; send.disabled = !state?.ai_available;
    input.disabled = !state?.ai_available||busy||waiting;
    document.querySelectorAll('[data-chat-new],#portal-chat-new').forEach(b => b.disabled = busy);
  }
  async function api(payload, receiptKey = null, query = '') {
    const epoch = accessEpoch;
    const controller = new AbortController(); const timeout = setTimeout(() => controller.abort(), 25000);
    try {
      const response = await fetch('/portal/westy.php' + (receiptKey ? '?receipt=' + encodeURIComponent(receiptKey) : query), {
        method:payload?'POST':'GET',credentials:'same-origin',cache:'no-store',
        headers:payload?{'Content-Type':'application/json','X-Portal-CSRF':root.dataset.csrf}:{},
        body:payload?JSON.stringify(payload):undefined,signal:controller.signal
      });
      const result = await response.json();
      if (epoch !== accessEpoch) throw Object.assign(new Error('sign_in'), {reason:'sign_in'});
      if (!response.ok || !result.ok) throw Object.assign(new Error(result.reason || 'unavailable'), {reason:result.reason});
      return result.state;
    } finally { clearTimeout(timeout); }
  }
  function clearPrivate() {
    accessEpoch++;
    state=null; nodes.clear(); log.replaceChildren(); draftBox.replaceChildren(); draftBox.hidden=true;
    preferenceControl?.closest('label')?.remove();preferenceControl=null;
    history?.replaceChildren(); input.value=''; editing=false; pending=null;
    devices.replaceChildren(element('option','Choose a computer'));devices.options[0].value='';devices.disabled=true;
    streamController?.abort(); if(poll)clearTimeout(poll); controls();
  }
  function accessError(error) {
    if(['sign_in','identity_unavailable'].includes(error.reason))clearPrivate();
    say(errors[error.reason] || errors.unavailable);
  }
  async function refresh(quiet = false) {
    if(busy)return;
    try {render(await api()); if(!quiet && state.ai_available)say('');}
    catch(error){clearPrivate();accessError(error);}
  }
  const scrollLatest = () => { log.scrollTop=log.scrollHeight; stickToBottom=true; jump.hidden=true; };
  log.addEventListener('scroll',()=>{stickToBottom=log.scrollHeight-log.scrollTop-log.clientHeight<80;jump.hidden=stickToBottom;});
  jump.addEventListener('click',scrollLatest);
  function turnNode(turn) {
    let node=nodes.get(turn.operation_key);
    if(!node){
      const question=element('article',undefined,'portal-chat-message portal-chat-message-user'); question.setAttribute('aria-label','You'); question.append(element('p',turn.input_text));
      const answer=element('article',undefined,'portal-chat-message portal-chat-message-assistant'); answer.setAttribute('aria-label','Westy');
      const avatar=element('img',undefined,'portal-chat-avatar');avatar.src='/assets/img/westy-avatar.png';avatar.alt='';
      const body=element('div',undefined,'portal-chat-message-body');const reply=element('p','', 'portal-chat-reply');const tools=element('div');const meta=element('div');
      body.append(reply,tools,meta);answer.append(avatar,body);log.append(question,answer);
      node={question,answer,reply,tools,meta,toolSignature:null,metaSignature:null};nodes.set(turn.operation_key,node);
    }
    return node;
  }
  const operationLabels={awaiting_approval:'Review required',authorized:'Authorized',queued:'Queued',verifying:'Checking recovery',completed:'Check complete',service_verified:'Service verified',needs_help:'Support review needed',expired:'Expired',cancelled:'Canceled',cancel_requested:'Cancel requested · waiting for result',cleanup_verified:'Cleanup verified'};
  const bytesLabel=bytes=>bytes<1024?bytes+' bytes':bytes<1048576?(bytes/1024).toFixed(1)+' KB':(bytes/1048576).toFixed(1)+' MB';
  function renderOperation(parent, op, turn) {
    const review=element('section',undefined,'portal-tool-review');review.append(element('h3',op.title));
    const details=element('dl');
    const add=(label,value)=>{details.append(element('dt',label),element('dd',value));};
    const namedDevice=[...devices.options].find(o=>o.value===op.device_reference);
    const computer=namedDevice?.textContent || 'Computer details unavailable';
    add('Computer',computer);add('Status',operationLabels[op.state]||op.state);
    const health=op.result?.health||op.result;
    if(health?.memory_used_percent!==undefined){add('Memory',health.memory_used_percent+'% used');if(health.memory_total_bytes!==undefined)add('RAM capacity',(health.memory_total_bytes/1073741824).toFixed(1)+' GB');add('Disk',health.system_disk_free_percent+'% free');add('Print service',health.spooler);}
    const temp=op.preview || (op.result?.kind==='temp_preview'?op.result:null);
    if(temp){add('Location','Windows temporary folder');add('Preview',temp.eligible_files+' eligible files · '+bytesLabel(temp.eligible_bytes));}
    if(op.result?.kind==='temp_cleanup'){add('Removed',op.result.deleted_files+' files · '+bytesLabel(op.result.deleted_bytes));add('Skipped',String(op.result.skipped_files));}
    review.append(details,element('p',op.impact));
    if(op.state==='queued')review.append(element('p','Waiting for your computer to report. You can leave this chat and return.'));
    if(['needs_help','cancel_requested'].includes(op.state)){const action={health:'check',temp_preview:'preview',temp_cleanup:'cleanup',spooler_restart:'repair'}[op.recipe]||'operation';review.append(element('p','The outcome is not confirmed. Do not repeat this '+action+'; contact support.', 'portal-tool-error'));}
    const actions=element('div',undefined,'portal-chat-draft-actions');
    const act=async(action)=>{
      if(busy)return;actions.querySelectorAll('button').forEach(b=>b.disabled=true);
      try{render(await api({action,conversation:state.conversation,reference:op.reference,approval_fingerprint:op.approval_fingerprint,reviewed:true}));say(action==='approve_operation'?'Approval recorded. Waiting for the computer’s result.':'Cancellation requested. Check the recorded status.');}
      catch(error){accessError(error);await refresh(true);}
    };
    if(op.can_approve&&namedDevice)actions.append(button(op.recipe==='temp_cleanup'?'Approve cleanup':'Approve repair',()=>act('approve_operation'),'btn-primary'));
    else if(op.can_approve)review.append(element('p','Computer details must load before you can approve this operation.'));
    if(op.can_cancel)actions.append(button('Cancel operation',()=>act('cancel_operation')));
    if(op.state==='needs_help'){const link=element('a','Contact support');link.href='/portal/new.php';actions.append(link);}
    review.append(actions);parent.append(review);
  }
  function renderTools(node,turn) {
    const signature=JSON.stringify(turn.reply?.tools||[]); if(signature===node.toolSignature)return;node.toolSignature=signature;node.tools.replaceChildren();
    const names={list_computers:'Computer list',read_computer_status:'Recorded device activity',start_health_check:'Computer health check',prepare_temp_cleanup:'Temporary-file preview',propose_print_repair:'Print-service repair review',inspect_computer:'Computer investigation',run_powershell:'Script review'};
    for(const tool of turn.reply?.tools||[]){
      const shell=['inspect_computer','run_powershell'].includes(tool.name)?tool.result:null;
      const shellLabels={queued:'Waiting',claimed:'Received',running:'Running',completed:'Result received',cancelled:'Cancelled',refused:'Not executed',rejected:'Not executed',unknown:'Outcome unknown',expired:'Expired'};
      const item=element('div',undefined,'portal-tool');item.dataset.active=String(tool.state==='dispatching'||['queued','verifying'].includes(tool.operation?.state)||['queued','claimed','running'].includes(shell?.state));
      const toolLabel=shell?(shellLabels[shell.state]||'Checking result'):tool.operation?operationLabels[tool.operation.state]:({complete:'Complete',dispatching:'Working',unknown:'Outcome unknown',unavailable:'Unavailable'}[tool.state]||tool.state);
      const label=element('div',undefined,'portal-tool-summary');label.append(element('span',undefined,'portal-tool-dot'),element('span',(names[tool.name]||'Device tool')+' · '+toolLabel));item.append(label);
      if(tool.reason)item.append(element('p',errors[tool.reason]||'This operation is unavailable. Contact support.'));
      if(tool.effect)item.append(element('p',tool.effect));
      if(tool.result&&['inspect_computer','run_powershell'].includes(tool.name)){
        const result=tool.result;item.append(element('p',({queued:'Waiting for the computer.',claimed:'The computer received the command. Check Westy on the computer if approval is needed.',running:'Running on the computer.',completed:'Result received.',cancelled:'Cancelled before execution.',refused:'The command was not executed.',rejected:'The plan was rejected before execution. Westy can use the validation feedback to correct it.',unknown:'The outcome is unknown. This command will not repeat.'}[result.state]||errors[result.reason]||'Checking the saved result.')));
      }
      if(tool.state==='unknown'||tool.state==='dispatching'&&turn.state!=='pending')item.append(element('p','The request may have reached your computer. It has not been retried. Check Your devices or contact support.','portal-tool-error'));
      if(tool.proposal)renderOperation(item,tool.proposal,turn);else if(tool.operation)renderOperation(item,tool.operation,turn);
      node.tools.append(item);
    }
  }
  function render(next) {
    const changed=state && state.conversation!==next.conversation;
    if(changed){nodes.clear();log.replaceChildren();editing=false;stickToBottom=true;}
    state=next;controls();
    if(!next.turns.length){if(empty)log.append(empty);}else empty?.remove();
    const present=new Set();
    for(const turn of next.turns){
      present.add(turn.operation_key);const node=turnNode(turn);const text=turn.reply?.reply||'';
      if(node.reply.textContent!==text)node.reply.textContent=text;
      renderTools(node,turn);
      const signature=JSON.stringify([turn.state,turn.reason_code,turn.reply?.sources,turn.reply?.draft_subject,turn.reply?.draft_body]);
      if(signature!==node.metaSignature){
        node.metaSignature=signature;node.meta.replaceChildren();
        if(turn.state==='pending'&&!text)node.meta.append(element('p','Westy is working…','portal-hint'));
        if(turn.state==='unavailable')node.meta.append(element('p',turn.reason_code==='stopped'&&!turn.reply?.tools?.length?'Reply stopped.':(errors[turn.reason_code]||errors.interrupted),'portal-hint'));
        if(turn.reply?.sources){const nav=element('nav');for(const id of turn.reply.sources){if(!labels[id])continue;const link=element('a',labels[id]);link.href='/portal/guide.php#'+id;nav.append(link);}node.meta.append(nav);}
        if(state.can_write&&turn.reply?.draft_subject&&turn.reply?.draft_body)node.meta.append(button('Edit suggested request',()=>editDraft({subject:turn.reply.draft_subject,body:turn.reply.draft_body,priority:'normal'})));
      }
    }
    for(const [id,node] of nodes)if(!present.has(id)){node.question.remove();node.answer.remove();nodes.delete(id);}
    if(history){
      const signature=JSON.stringify([next.conversation,next.conversations]);
      if(history.dataset.signature!==signature){history.dataset.signature=signature;history.replaceChildren();for(const chat of next.conversations||[]){const b=button(chat.title,()=>selectChat(chat.key),'');if(chat.key===next.conversation)b.setAttribute('aria-current','true');history.append(b);}}
    }
    if(!editing)renderDraft(state.receipt||state.draft);
    if(!state.ai_available)say(errors.ai_unavailable);
    if(stickToBottom)scrollLatest();
    scheduleRefresh();
    const latest=state.turns.at(-1);
    window.dispatchEvent(new CustomEvent('westy-conversation',{detail:{conversation:state.conversation,operation:latest?.state==='complete'?latest.operation_key:null}}));
  }
  function scheduleRefresh(){
    if(poll)clearTimeout(poll);
    const ready=state?.turns.find(t=>t.state==='complete'&&t.run?.ready);
    if(ready&&!busy&&state.ai_available){poll=setTimeout(()=>resumeRun(ready),100);return;}
    const active=state?.turns.some(t=>t.run?.state==='waiting'||t.state==='pending'||(t.reply?.tools||[]).some(x=>['queued','authorized','verifying','cancel_requested'].includes(x.operation?.state)||['queued','verifying','cancel_requested'].includes(x.proposal?.state)));
    if(active&&!busy)poll=setTimeout(()=>refresh(true),3000);
  }
  function renderDraft(draft) {
    draftBox.replaceChildren(); draftBox.hidden = !draft;
    if (!draft) return;
    if (draft.state === 'sent') {
      const receipt = element('div', undefined, 'portal-chat-message'); receipt.setAttribute('role', 'status');
      receipt.append(element('strong', 'Request #' + draft.ticket_id + ' received', 'receipt'), element('p', 'Your reviewed request is saved for your business and the support team. This confirms the ticket exists; it does not mean a technician has read it.'));
      if (draft.ticket_url) { const link = element('a', 'View support request #' + draft.ticket_id); link.href = draft.ticket_url; receipt.append(link); }
      else receipt.append(element('p', 'This ticket is no longer available in the portal. Contact support for help.'));
      draftBox.append(receipt);
    } else if (state.can_write) {
      draftBox.append(element('h3', 'Your saved draft'), element('p', draft.subject));
      draftBox.append(button('Edit & review request', () => editDraft(draft)));
    }
  }

  function editDraft(draft) {
    if (!state?.can_write) return;
    editing = true; draftBox.hidden = false; draftBox.replaceChildren();
    const draftKey = draft.draft_key || key();
    const revision = Number(draft.revision || 0);
    const editor = element('form', undefined, 'portal-chat-draft-fields');
    editor.append(element('h3', 'Edit your support request'));
    const fields = {};
    for (const [name, label, tag, max] of [['subject', 'Short summary', 'input', 190], ['body', 'What happened and who is affected?', 'textarea', 8000]]) {
      const wrap = element('label', label); const control = document.createElement(tag);
      control.name = name; control.maxLength = max; control.required = true; control.value = draft[name] || '';
      wrap.append(control); editor.append(wrap); fields[name] = control;
    }
    const label = element('label', 'Priority'); const priority = document.createElement('select');
    for (const item of ['low', 'normal', 'high', 'urgent']) { const option = element('option', item[0].toUpperCase() + item.slice(1)); option.value = item; priority.append(option); }
    priority.value = draft.priority || 'normal'; label.append(priority); editor.append(label);
    editor.append(element('p', 'Only the text above will be sent. Your private chat stays private.', 'portal-hint'));
    const actions = element('div', undefined, 'portal-chat-draft-actions');
    const review = element('button', 'Save & review request', 'btn-primary'); review.type = 'submit'; actions.append(review);
    actions.append(button('Cancel edits', () => { editing = false; renderDraft(state.draft); })); editor.append(actions);
    editor.addEventListener('submit', async event => {
      event.preventDefault(); if (busy) return;
      const request = { action: 'save_draft', draft_key: draftKey, revision, conversation: state.conversation, subject: fields.subject.value, body: fields.body.value, priority: priority.value };
      busy = true; review.disabled = true; say('Saving your draft for review…');
      try { const next = await api(request); editing = false; render(next); reviewDraft(state.draft); say('Review the exact text and audience before sending.'); }
      catch (error) { say(errors[error.reason] || 'The draft save was not confirmed. Check saved chat before retrying.'); }
      finally { busy = false; review.disabled = false; send.disabled = !state?.ai_available; }
    });
    draftBox.append(editor); fields.subject.focus();
  }

  function reviewDraft(draft) {
    if (!draft || draft.state !== 'draft' || !state.can_write) return;
    editing = false; draftBox.replaceChildren(); draftBox.hidden = false;
    draftBox.append(element('h3', 'Review before sending'), element('strong', draft.subject), element('p', draft.body, 'portal-chat-draft-review'), element('p', 'Priority: ' + draft.priority, 'portal-hint'));
    const label = element('label', undefined, 'portal-chat-review-audience'); const check = document.createElement('input'); check.type = 'checkbox';
    label.append(check, element('span', 'I understand this request will be visible to my business and the support team. Only this reviewed text is shared.'));
    draftBox.append(label);
    const actions = element('div', undefined, 'portal-chat-draft-actions');
    const submit = button('Send request', async () => {
      if (!check.checked || busy) return;
      busy = true; submit.disabled = true; say('Sending your reviewed request…');
      try { render(await api({ action: 'handoff', draft_key: draft.draft_key, revision: Number(draft.revision), reviewed: true })); say('Request saved. The receipt below is your confirmation.'); }
      catch (error) {
        say('Checking whether your request was saved…');
        try {
          render(await api(null, draft.draft_key));
          if (state.receipt?.draft_key === draft.draft_key || (state.draft?.draft_key === draft.draft_key && state.draft.state === 'sent')) say('Request saved. No duplicate was sent.');
          else { say(errors[error.reason] || 'No receipt was found yet. Reopen this saved draft to review and safely send the same request.'); }
        } catch {
          draftBox.replaceChildren(element('p', 'We cannot confirm the result yet. Check the saved receipt before sending anything else.'));
          draftBox.append(button('Check saved receipt', async () => {
            try { render(await api(null, draft.draft_key)); say(state.receipt ? 'Request saved. No duplicate was sent.' : 'No receipt was found. Check the saved draft before continuing.'); }
            catch (retryError) { say(errors[retryError.reason] || 'We cannot check the receipt yet. Try again when the connection returns.'); }
          }));
          say('Connection lost. The request may already be saved.');
        }
      } finally { busy = false; send.disabled = !state?.ai_available; }
    }, 'btn-primary');
    submit.disabled = true; check.addEventListener('change', () => { submit.disabled = !check.checked || busy; });
    actions.append(submit, button('Back to editing', () => editDraft(draft))); draftBox.append(actions); check.focus();
  }

  async function readStream(response,onEvent){
    if(!response.ok || !response.headers.get('content-type')?.includes('text/event-stream')){
      let data;try{data=await response.json();}catch{}throw Object.assign(new Error('unavailable'),{reason:data?.reason||'unavailable'});
    }
    const reader=response.body.getReader(),decoder=new TextDecoder();let buffer='',doneSeen=false;
    try{
      while(true){const {value,done}=await reader.read();if(done)break;buffer+=decoder.decode(value,{stream:true});if(buffer.length>524288)throw new Error('stream_limit');
        let end;while((end=buffer.indexOf('\n\n'))>=0){const frame=buffer.slice(0,end);buffer=buffer.slice(end+2);let event='message';const data=[];
          for(const line of frame.split('\n')){if(line.startsWith('event:'))event=line.slice(6).trim();if(line.startsWith('data:'))data.push(line.slice(5).trimStart());}
          if(!data.length)continue;const payload=JSON.parse(data.join('\n'));if(event==='error')throw Object.assign(new Error(payload.reason),{reason:payload.reason});
          if(event==='done')doneSeen=true;onEvent(event,payload);
        }
      }
      if(!doneSeen)throw new Error('stream_interrupted');
    }finally{reader.releaseLock();}
  }
  form.addEventListener('submit',async event=>{
    event.preventDefault();if(busy||!state?.ai_available||state.turns.some(t=>t.run?.state==='waiting')||!input.value.trim())return;
    const text=input.value.trim(),operation=messageKey(),requestEpoch=accessEpoch;currentOperation=operation;let operationExpired=false;
    const request={action:'message',operation,message:text,conversation:state.conversation,device_reference:devices.value||null};
    const turn={operation_key:operation,input_text:text,state:'pending',reply:{reply:'',sources:[],tools:[]},reason_code:''};
    state.turns.push(turn);busy=true;stickToBottom=true;render(state);input.value='';input.style.height='';say('Connecting to Westy…');controls();
    streamController=new AbortController();
    const streamTimeout=setTimeout(()=>streamController?.abort(),165000);
    try{
      const response=await fetch('/portal/westy.php',{method:'POST',credentials:'same-origin',cache:'no-store',headers:{'Content-Type':'application/json','Accept':'text/event-stream','X-Portal-CSRF':root.dataset.csrf},body:JSON.stringify(request),signal:streamController.signal});
      await readStream(response,(event,data)=>{
        if(event==='accepted'){state.conversation=data.conversation;say('Westy is working…');}
        if(event==='delta'){turn.reply.reply+=data.text;const node=turnNode(turn);node.reply.textContent=turn.reply.reply;node.meta.replaceChildren();if(stickToBottom)scrollLatest();}
        if(event==='tool'){const index=turn.reply.tools.findIndex(t=>t.key===data.tool.key);if(index<0)turn.reply.tools.push(data.tool);else turn.reply.tools[index]=data.tool;renderTools(turnNode(turn),turn);if(stickToBottom)scrollLatest();}
        if(event==='done'){render(data.state);say(state.turns.some(t=>t.run?.state==='waiting')?'Waiting for the computer check…':'Reply finished.');}
      });
    }catch(error){
      operationExpired=error.reason==='operation_expired';
      // An explicit pre-admission refusal leaves the request unsent. Restore it
      // before announcing refresh guidance; receipt reconciliation may be slow.
      if(operationExpired&&state&&accessEpoch===requestEpoch&&state.conversation===request.conversation)input.value=text;
      if(error.name!=='AbortError')accessError(error);
      // Read receipts only: never resubmit an ambiguous generation or tool call.
    }finally{
      clearTimeout(streamTimeout);
      busy=false;streamController=null;currentOperation=null;controls();
      if(state){await refresh(true);if(state&&!state.turns.some(t=>t.operation_key===operation)){input.value=text;say(operationExpired?errors.operation_expired:'Your message was not saved. Review it before sending again.');}input.focus();scheduleRefresh();}
    }
  });
  const desktopResumeAttempts=new Set();
  async function resumeRun(turn){
    if(!turn.run?.ready)return;
    await resumeComputer({conversation:state.conversation,operation:turn.operation_key,sequence:turn.run.sequence},turn);
  }
  async function resumeComputer(detail,turn){
    if(busy||!state?.ai_available||!detail||detail.conversation!==state.conversation
      ||turn?.state!=='complete'||detail.operation!==turn.operation_key)return;
    const attempt=detail.conversation+':'+detail.operation+':'+(detail.sequence??'desktop');
    if(desktopResumeAttempts.has(attempt)){poll=setTimeout(()=>refresh(true),5000);return;}
    desktopResumeAttempts.add(attempt);
    const request={action:detail.sequence===undefined?'desktop_resume':'run_resume',operation:detail.operation,conversation:detail.conversation};
    if(detail.sequence!==undefined)request.sequence=detail.sequence;
    currentOperation=detail.operation;busy=true;turn.state='pending';
    turn.reply||={reply:'',sources:[],tools:[]};turn.reply.reply+='\n\n';
    render(state);controls();say('Continuing your computer task…');
    streamController=new AbortController();
    const timeout=setTimeout(()=>streamController?.abort(),165000);
    try{
      const response=await fetch('/portal/westy.php',{method:'POST',credentials:'same-origin',cache:'no-store',headers:{'Content-Type':'application/json','Accept':'text/event-stream','X-Portal-CSRF':root.dataset.csrf},body:JSON.stringify(request),signal:streamController.signal});
      await readStream(response,(event,data)=>{
        if(event==='accepted')say('Westy is continuing your computer task…');
        if(event==='delta'){turn.reply.reply+=data.text;turnNode(turn).reply.textContent=turn.reply.reply;if(stickToBottom)scrollLatest();}
        if(event==='tool'){const index=turn.reply.tools.findIndex(t=>t.key===data.tool.key);if(index<0)turn.reply.tools.push(data.tool);else turn.reply.tools[index]=data.tool;renderTools(turnNode(turn),turn);}
        if(event==='done'){render(data.state);say('Computer task response finished.');}
      });
    }catch(error){if(error.name!=='AbortError')accessError(error);}
    finally{
      clearTimeout(timeout);busy=false;streamController=null;currentOperation=null;controls();
      if(state){await refresh(true);scheduleRefresh();}
    }
  }
  window.addEventListener('westy-desktop-resume',event=>resumeComputer(event.detail,state?.turns.at(-1)));
  stop.addEventListener('click',async()=>{
    const operation=currentOperation||state?.turns.find(t=>t.run?.state==='waiting')?.operation_key;
    if(!operation)return;stop.disabled=true;
    try{const next=await api({action:'stop',operation});streamController?.abort();render(next);say(next.turns.find(t=>t.operation_key===operation)?.reply?.tools?.length?errors.stopped:'Reply stopped.');}
    catch(error){accessError(error);}finally{stop.disabled=false;}
  });
  input.addEventListener('keydown',event=>{if(event.key==='Enter'&&!event.shiftKey&&!event.isComposing){event.preventDefault();form.requestSubmit();}});
  input.addEventListener('input',()=>{input.style.height='auto';input.style.height=Math.min(180,input.scrollHeight)+'px';});
  async function newChat(){
    if(busy||!state)return;
    try{editing=false;render(await api({action:'new_chat',conversation:state.conversation,next_conversation:key()}));input.value='';say('');closeNavigation();input.focus();}
    catch(error){accessError(error);}
  }
  async function selectChat(conversation){
    if(busy)return;
    try{render(await api({action:'select_chat',conversation}));say('');closeNavigation();input.focus();}
    catch(error){accessError(error);}
  }
  document.querySelectorAll('[data-chat-new],#portal-chat-new').forEach(b=>b.addEventListener('click',newChat));
  const menu=document.querySelector('.portal-menu'),nav=document.getElementById('portal-nav');
  function closeNavigation(){const restore=nav?.classList.contains('is-open')&&nav.contains(document.activeElement);menu?.setAttribute('aria-expanded','false');nav?.classList.remove('is-open');if(workspace)document.querySelector('.portal-content').inert=false;if(restore)menu?.focus();}
  menu?.addEventListener('click',()=>{const open=menu.getAttribute('aria-expanded')!=='true';menu.setAttribute('aria-expanded',String(open));nav.classList.toggle('is-open',open);if(workspace)document.querySelector('.portal-content').inert=open;if(open)nav.querySelector('button,a')?.focus();});
  document.addEventListener('keydown',event=>{if(event.key==='Escape'){closeNavigation();if(!workspace&&!panel.hidden)closePanel();}});
  const mobileWidth=matchMedia('(max-width:760px)');
  function syncViewport(){
    if(!workspace)return;
    if(mobileWidth.matches&&window.visualViewport)document.documentElement.style.setProperty('--portal-viewport-height',window.visualViewport.height+'px');
    else document.documentElement.style.removeProperty('--portal-viewport-height');
  }
  window.visualViewport?.addEventListener('resize',syncViewport);window.addEventListener('resize',syncViewport);syncViewport();
  function syncPanelMode(){
    if(workspace){if(!mobileWidth.matches)closeNavigation();return;}
    const modal=!workspace&&!panel.hidden&&mobileWidth.matches;
    if(modal){panel.setAttribute('role','dialog');panel.setAttribute('aria-modal','true');}
    else{panel.removeAttribute('role');panel.removeAttribute('aria-modal');}
    for(const item of document.querySelectorAll('.portal-content,.portal-sidebar,.portal-top'))item.inert=modal;
  }
  mobileWidth.addEventListener('change',syncPanelMode);
  function openPanel(){focusBefore=document.activeElement;root.append(panel);panel.hidden=false;bubble?.setAttribute('aria-expanded','true');syncPanelMode();input.focus();refresh(true);}
  function closePanel(){panel.hidden=true;bubble?.setAttribute('aria-expanded','false');syncPanelMode();focusBefore?.focus();}
  panel.addEventListener('keydown',event=>{
    if(event.key==='Tab'&&panel.getAttribute('aria-modal')==='true'){
      const items=[...panel.querySelectorAll('a,button,input,select,textarea')].filter(e=>!e.disabled&&e.getClientRects().length);
      if(event.shiftKey&&document.activeElement===items[0]){event.preventDefault();items.at(-1)?.focus();}
      else if(!event.shiftKey&&document.activeElement===items.at(-1)){event.preventDefault();items[0]?.focus();}
    }
  });
  bubble?.addEventListener('click',()=>panel.hidden?openPanel():closePanel());
  document.getElementById('portal-chat-close')?.addEventListener('click',closePanel);
  document.querySelectorAll('[data-portal-chat-prompt]').forEach(b=>b.addEventListener('click',()=>{if(panel.hidden)openPanel();input.value=b.dataset.portalChatPrompt;input.focus();}));
  window.addEventListener('beforeunload',event=>{if(editing||input.value.trim()){event.preventDefault();event.returnValue='';}});
  // Browser storage never contains conversation text, identity or device receipts.
  window.addEventListener('pagehide',clearPrivate);
  window.addEventListener('pageshow',event=>{if(event.persisted){refresh();loadDevices();loadPreference();}});
  document.addEventListener('visibilitychange',()=>{if(document.visibilityState==='visible'&&!busy)refresh(true);});
  if('BroadcastChannel' in window){const access=new BroadcastChannel('safeharbor-portal-access');document.querySelector('form[action="/portal/logout.php"]')?.addEventListener('submit',()=>access.postMessage('signed-out'));access.addEventListener('message',event=>{if(event.data==='signed-out'){clearPrivate();say(errors.sign_in);}});}
  async function loadDevices(){
    try{const next=await api(null,null,'?devices=1');const selected=devices.value;devices.replaceChildren(element('option','Choose a computer'));devices.options[0].value='';for(const item of next.devices||[]){const option=element('option',item.label);option.value=item.reference;devices.append(option);}devices.disabled=false;if(selected)devices.value=selected;else if(devices.options.length===2)devices.selectedIndex=1;for(const node of nodes.values())node.toolSignature=null;if(state)render(state);}
    catch(error){accessError(error);devices.disabled=true;devices.options[0].textContent='Computer tools unavailable';}
  }
  let preferenceControl=null;
  async function loadPreference(){
    try{
      const next=await api(null,null,'?diagnostic_preference=1');
      if(typeof next.diagnostic_preference?.automatic_diagnostics!=='boolean'||preferenceControl)return;
      const label=element('label',undefined,'portal-chat-foot');const checkbox=element('input');checkbox.type='checkbox';
      checkbox.checked=!next.diagnostic_preference.automatic_diagnostics;preferenceControl=checkbox;
      label.append(checkbox,element('span','Ask me before each automatic computer check'));form.after(label);
      checkbox.addEventListener('change',async()=>{
        checkbox.disabled=true;const ask=checkbox.checked;
        try{render(await api({action:'diagnostic_preference',automatic_diagnostics:!ask}));say(ask?'Computer checks will ask for approval in the Westy companion.':'Routine computer checks can run automatically. Scripts still require approval.');}
        catch(error){checkbox.checked=!ask;accessError(error);}finally{checkbox.disabled=false;}
      });
    }catch{ /* Existing chat remains available when general tools are not installed. */ }
  }
  if(home){home.append(panel);panel.hidden=false;}
  controls();say('Loading your private conversation…');refresh();loadDevices();loadPreference();
})();
