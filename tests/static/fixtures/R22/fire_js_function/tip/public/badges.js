// 가짜 배지 부품
export function renderBadgeList(items) {
  if (!items.length) return '없음';
  return items.map((x) => x.name).join(' · ');
}
