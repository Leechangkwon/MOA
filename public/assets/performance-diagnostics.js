/** Opt-in local diagnostics. No requests, identifiers, titles, content or persistent storage. */
let active = false;
let observers = [];
let counts;
let clsStart = null, clsLast = null, clsWindow = 0, generation = 0;
const finite = value => Number.isFinite(value) ? Math.round(value * 1000) / 1000 : 0;
function clear() {
  counts = { lcp_ms: 0, cls_observed: 0, long_task_count: 0, long_task_total_ms: 0,
    long_task_max_ms: 0, long_animation_frame_max_ms: 0, interaction_duration_max_ms: 0,
    interaction_entries: 0 };
  clsStart = clsLast = null; clsWindow = 0;
}
clear();

function observe(type, accept, options = {}) {
  if (typeof PerformanceObserver === 'undefined' || !PerformanceObserver.supportedEntryTypes?.includes(type)) { return; }
  const observingGeneration = generation;
  const observer = new PerformanceObserver(list => {
    if (active && observingGeneration === generation) { for (const entry of list.getEntries()) { accept(entry); } }
  });
  try { observer.observe({ type, buffered: true, ...options }); observers.push(observer); }
  catch { observer.disconnect(); }
}

export function start() {
  if (active) { return snapshot(); }
  // Fresh observers replay the available page buffer. Rebuild totals instead of counting it twice.
  clear(); generation += 1;
  active = true;
  observe('largest-contentful-paint', entry => { counts.lcp_ms = entry.startTime; });
  observe('layout-shift', entry => {
    if (entry.hadRecentInput) { return; }
    if (clsLast !== null && entry.startTime - clsLast < 1000 && entry.startTime - clsStart < 5000) { clsWindow += entry.value; }
    else { clsStart = entry.startTime; clsWindow = entry.value; }
    clsLast = entry.startTime;
    counts.cls_observed = Math.max(counts.cls_observed, clsWindow);
  });
  observe('longtask', entry => {
    counts.long_task_count += 1;
    counts.long_task_total_ms += entry.duration;
    counts.long_task_max_ms = Math.max(counts.long_task_max_ms, entry.duration);
  });
  observe('long-animation-frame', entry => { counts.long_animation_frame_max_ms = Math.max(counts.long_animation_frame_max_ms, entry.duration); });
  observe('event', entry => {
    if (!entry.interactionId) { return; }
    counts.interaction_entries += 1;
    counts.interaction_duration_max_ms = Math.max(counts.interaction_duration_max_ms, entry.duration);
  }, { durationThreshold: 16 });
  return snapshot();
}

export function stop() {
  active = false; generation += 1;
  for (const observer of observers) { observer.disconnect(); }
  observers = [];
  return snapshot();
}

export function reset() { stop(); clear(); return start(); }

export function snapshot() {
  const navigation = performance.getEntriesByType('navigation')[0];
  const resources = performance.getEntriesByType('resource');
  const navigationValues = navigation ? {
    ttfb_ms: finite(navigation.responseStart - navigation.startTime),
    dom_content_loaded_ms: finite(navigation.domContentLoadedEventEnd),
    load_ms: finite(navigation.loadEventEnd)
  } : {};
  const values = Object.fromEntries(Object.entries(counts).map(([key, value]) => [key, finite(value)]));
  return {
    schema: 1, active, navigation: navigationValues, observed: values,
    resources: {
      count: resources.length,
      transfer_bytes: finite(resources.reduce((sum, entry) => sum + entry.transferSize, navigation?.transferSize || 0)),
      decoded_bytes: finite(resources.reduce((sum, entry) => sum + entry.decodedBodySize, navigation?.decodedBodySize || 0))
    },
    notes: ['Local memory only; no network or persistent storage.',
      'Interaction maximum is a diagnostic sample, not formal INP.',
      'Observed CLS uses session windows; lifecycle and field coverage require separate validation.']
  };
}

export function download() {
  const url = URL.createObjectURL(new Blob([JSON.stringify(snapshot(), null, 2)], { type: 'application/json' }));
  const link = document.createElement('a'); link.href = url; link.download = 'MOA-performance.json';
  link.click(); setTimeout(() => URL.revokeObjectURL(url), 0);
}
