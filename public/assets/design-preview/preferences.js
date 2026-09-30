/* Preview preferences are independent of production account/theme settings. */
(() => {
  'use strict';
  const valid = (value, choices, fallback) => choices.includes(value) ? value : fallback;
  const read = (key, fallback) => { try { return localStorage.getItem('qaredi.preview.' + key) ?? fallback; } catch { return fallback; } };
  const root = document.documentElement;
  const savedDesign = valid(read('design', 'claude'), ['claude', 'astra'], 'claude');
  root.dataset.design = valid(new URLSearchParams(location.search).get('design'), ['claude', 'astra'], savedDesign);
  const appearance = valid(read('appearance', 'system'), ['light', 'dark', 'system'], 'system');
  root.dataset.appearance = appearance;
  root.dataset.theme = appearance === 'system' ? (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light') : appearance;
  root.dataset.motion = read('motion', 'full') === 'reduce' ? 'reduce' : 'full';
  root.dataset.contrast = read('contrast', 'normal') === 'high' ? 'high' : 'normal';
})();
