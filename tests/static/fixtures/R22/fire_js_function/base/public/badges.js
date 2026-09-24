// 가짜 배지 부품
export function renderBadgeList(items) {
  return items.map((x) => x.name).join(', ');
}
