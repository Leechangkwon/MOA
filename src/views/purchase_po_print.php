<?php
declare(strict_types=1);

/**
 * src/views/purchase_po_print.php — 발주서 인쇄용 HTML (Q-18② 이카운트 발주서 양식, files #188)
 *
 * 공통 셸을 쓰지 않는다 — 인쇄물에 내비와 상단바가 끼면 안 된다.
 * 인쇄 규칙(A4 세로 `@page`)은 `/assets/purchase_docs.css` 의 `.po-print` 아래에 있다
 * (CSP 가 `style-src 'self'` 라 인라인 <style> 은 쓸 수 없다 — 그래서 외부 파일 하나다).
 *
 * ★ 이 파일이 문서에 글자를 넣는 곳은 전부 `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')` 이다
 *   (PRD S-03). JS 가 한 줄도 없고, 값은 모두 서버가 계산한 것이다.
 *
 * @var array<string,mixed>            $doc
 * @var array<string,mixed>            $header
 * @var list<array<string,mixed>>      $lines
 * @var array{supply:string,vat:string,total:string} $totals
 * @var string|null                    $logoUrl
 * @var array<string,mixed>            $user
 */

/** 짧은 이스케이프 도우미 — 이 파일 안에서만 쓴다. */
$e = static function ($v): string {
    return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
};
/** 금액·수량 보기 좋게 (값 자체는 서버가 만든 문자열이다) */
$money = static function ($v): string {
    return $v === null ? '' : number_format((float) $v);
};
/** 머리 값 하나 — buyer 가 고친 값이 있으면 그것, 없으면 전표에서 */
$head = static function (string $key, $fallback = null) use ($header) {
    $v = $header[$key] ?? null;
    return ($v === null || $v === '') ? $fallback : $v;
};
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>발주서 <?= $e($doc['doc_no']) ?></title>
<link rel="stylesheet" href="/assets/purchase_docs.css?v=<?= (int) filemtime(__DIR__ . '/../../public/assets/purchase_docs.css') ?>">
</head>
<body class="po-print">

<div class="po-sheet">

  <header class="po-head">
    <div class="po-head__company">
      <?php if ($logoUrl !== null): ?>
        <img class="po-logo" src="<?= $e($logoUrl) ?>" alt="">
      <?php endif; ?>
      <div class="po-company-text">
        <p class="po-company-name"><?= $e($head('company_name', '')) ?></p>
        <p class="po-company-line">사업자등록번호 <?= $e($head('company_biz_no', '-')) ?>
           · 대표 <?= $e($head('company_ceo', '-')) ?></p>
        <p class="po-company-line"><?= $e($head('company_address', '')) ?></p>
        <p class="po-company-line">TEL <?= $e($head('company_tel', '-')) ?></p>
      </div>
    </div>
    <h1 class="po-title">발 주 서</h1>
  </header>

  <table class="po-meta">
    <tbody>
      <tr>
        <th>일련번호</th><td class="po-mono"><?= $e($head('serial_no', $doc['doc_no'])) ?></td>
        <th>발주일자</th><td><?= $e($doc['doc_date']) ?></td>
      </tr>
      <tr>
        <th>수신 (거래처)</th><td><?= $e($head('receiver', $doc['vendor_name'])) ?></td>
        <th>납기일자</th><td><?= $e($head('due_date', '-')) ?></td>
      </tr>
      <tr>
        <th>참조</th><td><?= $e($head('cc', '-')) ?></td>
        <th>납품창고</th><td><?= $e($head('delivery_location', $doc['location_name'])) ?></td>
      </tr>
      <tr>
        <th>TEL</th><td><?= $e($head('tel', '-')) ?></td>
        <th>담당 (구매)</th><td><?= $e($head('manager_name', $doc['manager_name'])) ?></td>
      </tr>
    </tbody>
  </table>

  <p class="po-amount-words">
    합계금액 (부가세 포함) <strong><?= $e($money($totals['total'])) ?> 원</strong>
  </p>

  <table class="po-lines">
    <thead>
      <tr>
        <th class="po-col-no">#</th>
        <th>품목코드</th>
        <th>품목명 [규격]</th>
        <th class="po-num">수량</th>
        <th class="po-num">단가</th>
        <th class="po-num">공급가액</th>
        <th class="po-num">부가세</th>
        <th>창고명</th>
        <th>적요</th>
      </tr>
    </thead>
    <tbody>
      <?php $n = 0; foreach ($lines as $line): ?>
        <?php if (($line['status'] ?? '') === 'void') { continue; } $n++; ?>
        <tr>
          <td class="po-col-no"><?= $e((string) $n) ?></td>
          <td class="po-mono"><?= $e($line['code']) ?></td>
          <td><?= $e($line['name']) ?><?php if (($line['spec'] ?? null) !== null): ?>
            <span class="po-spec">[<?= $e($line['spec']) ?>]</span><?php endif; ?></td>
          <td class="po-num"><?= $e($money($line['qty'])) ?><?php if (($line['unit'] ?? null) !== null): ?>
            <span class="po-unit"><?= $e($line['unit']) ?></span><?php endif; ?></td>
          <td class="po-num"><?= $e($money($line['unit_price'])) ?></td>
          <td class="po-num"><?= $e($money($line['amount'])) ?></td>
          <td class="po-num"><?= $e($money($line['amount'] === null ? null : (string) round(((float) $line['amount']) * 0.1, 2))) ?></td>
          <td><?= $e($head('delivery_location', $doc['location_name'])) ?></td>
          <td><?= $e($line['remark']) ?><?php if (($line['src_doc_no'] ?? null) !== null): ?>
            <span class="po-src"><?= $e($line['src_doc_no']) ?></span><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if ($n === 0): ?>
        <tr><td class="po-empty" colspan="9">줄이 없습니다.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>

  <table class="po-totals">
    <tbody>
      <tr><th>공급가액</th><td class="po-num"><?= $e($money($totals['supply'])) ?></td></tr>
      <tr><th>부가세</th><td class="po-num"><?= $e($money($totals['vat'])) ?></td></tr>
      <tr class="po-total-row"><th>합계</th><td class="po-num"><?= $e($money($totals['total'])) ?></td></tr>
    </tbody>
  </table>

  <?php if (($header['memo'] ?? null) !== null || ($doc['memo'] ?? null) !== null): ?>
    <p class="po-memo">비고: <?= $e($header['memo'] ?? $doc['memo']) ?></p>
  <?php endif; ?>

  <footer class="po-foot">
    <p class="po-foot-line">
      <?php if (($doc['sent_at'] ?? null) !== null): ?>
        보냄 <?= $e($doc['sent_at']) ?> · <?= $e($doc['sent_by_name']) ?>
      <?php else: ?>
        아직 보내지 않은 발주서입니다 (검토 중).
      <?php endif; ?>
    </p>
    <p class="po-foot-line po-foot-hint">
      브라우저의 <strong>인쇄 → 「PDF 로 저장」</strong> 으로 파일을 만듭니다.
      금액은 부가세 별도 공급가액 기준으로 계산한 뒤 부가세 10% 를 더한 값입니다.
    </p>
  </footer>

</div>

</body>
</html>
