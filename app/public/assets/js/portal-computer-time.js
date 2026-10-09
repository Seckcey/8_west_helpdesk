/* Selected endpoint evidence only. No browser/account timezone fallback. */
(function (root, factory) {
  const api = factory();
  if (typeof module === 'object' && module.exports) module.exports = api;
  else root.PortalComputerTime = api;
})(typeof globalThis === 'object' ? globalThis : this, function () {
  'use strict';
  function instant(value) {
    if (typeof value !== 'string') return null;
    const iso = /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(value) ? value.replace(' ', 'T') + 'Z' : value;
    if (!/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/.test(iso)) return null;
    const ms = Date.parse(iso);
    return Number.isFinite(ms) && new Date(ms).toISOString() === iso.slice(0,-1)+'.000Z' ? ms : null;
  }
  function evidence(value) {
    if (!value || value.schema !== 'milepost.computer_time.v1' || value.source !== 'agent_inventory'
      || typeof value.timezone !== 'string') return null;
    const server = instant(value.server_now), received = instant(value.received_at);
    const observed = instant(value.observed_at), expires = instant(value.expires_at);
    if ([server, received, observed, expires].includes(null) || received > server + 30000
      || Math.abs(observed-received) > 300000 || expires-received !== 28800000 || expires <= server) return null;
    try { new Intl.DateTimeFormat('en-US', {timeZone:value.timezone}).format(0); }
    catch { return null; }
    return {value, server, expires};
  }
  function create(value, monotonicNow) {
    const data=evidence(value);
    return data ? {...data, start:monotonicNow} : null;
  }
  function current(clock, monotonicNow) {
    if (!clock || !Number.isFinite(monotonicNow) || monotonicNow < clock.start) return null;
    const now=clock.server+monotonicNow-clock.start;
    return now < clock.expires ? now : null;
  }
  function format(value, clock, monotonicNow) {
    const ms=instant(value);
    if(ms===null)return 'Time unavailable';
    const valid=current(clock,monotonicNow)!==null;
    const zone=valid?clock.value.timezone:'UTC';
    const result=new Intl.DateTimeFormat(undefined,{timeZone:zone,year:'numeric',month:'short',day:'numeric',
      hour:'numeric',minute:'2-digit',timeZoneName:'shortOffset'}).format(ms);
    return result+' · '+zone+(valid?'':' (computer timezone unavailable)');
  }
  function summary(clock, monotonicNow) {
    const now=current(clock,monotonicNow);
    if(now===null)return 'Computer timezone unavailable · times shown in UTC';
    return 'Computer time: '+format(new Date(Math.floor(now/1000)*1000).toISOString().replace('.000Z','Z'),clock,monotonicNow)+' (last reported timezone)';
  }
  return {instant,create,current,format,summary};
});
