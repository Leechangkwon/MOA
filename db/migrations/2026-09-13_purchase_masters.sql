-- ─────────────────────────────────────────────────────────────
-- 2026-09-13_purchase_masters.sql  (19단계 — PRD F-28 기초정보 · F-29 엑셀 가져오기,
--                                   ARCHITECTURE 5.18 · 10장, docs/purchase/03-architecture-draft.md 3장)
--
-- 목적: 구매·재고 모듈이 데이터를 받아들일 **자리**를 만든다.
--       기초정보 6종(브랜드·지점·장소·거래처·분류/품목·인가량) + 가져오기 매핑,
--       그리고 계정에 붙는 두 축(`users.branch_id` · `users.purchase_role`).
--
-- ★ 전표·재고 표(p_docs · p_doc_lines · p_stock_moves · p_doc_seq …)는 여기 없다 — 20·21단계다.
--   지금 만들면 "쓰이지 않는 표에 권한만 열려 있는 상태" 가 된다.
--
-- 설계 요점
--   · 표 접두는 전부 `p_` 다. 본체 표(users·files·folders…)와 이름이 섞이지 않는다 (ARCH 5.18 「경계」).
--   · **삭제가 없다.** 기초정보를 그만 쓰는 것은 `is_active = false` 이고, 앱 계정에는
--     이 표들에 DELETE 권한 자체가 없다(아래 GRANT). 전표가 기초정보를 참조하게 되는
--     20단계 이후에도 "지워서 추적이 끊기는" 경로가 생기지 않는다.
--   · 지점 격리(R-65)의 근거 컬럼은 `p_locations.branch_id` 와 `p_item_branches.branch_id` 다.
--     판정 자체는 DB 가 아니라 `Authz::purchaseScopeWhere` 한 곳이 만든다(ARCH 4.1) —
--     여기서는 그 조건이 훑을 **인덱스**만 둔다.
--   · 품목코드는 **거래처 카탈로그 번호 그대로**이며 거래처 안에서만 유일하다
--     (`UNIQUE(vendor_id, code)`). 전역 유일로 두면 두 거래처가 같은 번호를 쓰는 순간 막힌다.
--   · `users.purchase_role` 의 CHECK 는 `Authz` 의 판정과 **같은 네 값**이다. 앱이 한 군데라도
--     검사를 빠뜨려도 표에 모르는 역할 문자열이 들어갈 수 없다(users_role_check 와 같은 원칙).
--   · `users.branch_id` 는 NULL 허용이다 — 구매팀·경영진은 지점이 없다. NULL 인 requester·stock 은
--     `purchaseScopeWhere` 가 `1=0`(아무것도 못 봄)으로 판정한다.
--
-- 실행: sudo -u postgres psql -d tbox_re -v ON_ERROR_STOP=1 \
--            -f db/migrations/2026-09-13_purchase_masters.sql
--       (새로 설치하는 DB 는 db/schema.sql 에 같은 내용이 들어 있어 이 파일이 필요 없다.)
--
-- 재실행 안전성(멱등): CREATE TABLE IF NOT EXISTS · ADD COLUMN IF NOT EXISTS ·
--   CREATE INDEX IF NOT EXISTS · 제약은 pg_constraint 를 먼저 확인하고 붙인다 · GRANT.
--   **값을 바꾸는 UPDATE·DELETE 가 한 줄도 없다** (CLAUDE.md 4장 "운영 데이터에 손대지 않는다").
--
-- 권한: 새 표이므로 여기서 GRANT 를 함께 준다(기존 표 권한이 자동으로 따라붙지 않는다).
--   tbox_app  : SELECT·INSERT·UPDATE (★ DELETE 없음) + 시퀀스 USAGE
--   tbox_backup: SELECT 만 (PRD S-08)
--   tbox_perm : 없음 — 이 롤은 permissions 표 하나만 본다 (ARCH 10장, SECURITY R-60)
--   같은 내용이 db/grants.sql 에도 들어간다(두 곳을 맞춘다).
-- ─────────────────────────────────────────────────────────────

\set ON_ERROR_STOP on

BEGIN;

-- ─── 브랜드 (치과·병원 …) ───
CREATE TABLE IF NOT EXISTS p_brands (
    id         BIGSERIAL   PRIMARY KEY,
    name       TEXT        NOT NULL,
    -- 회사 정보 (사용자 Q-18 확정 2026-09-13, ARCH 5.18) — **발주서 PDF 머리 정보의 출처**다.
    -- 20단계의 발주서 PDF 가 이 다섯 칸을 읽는다. 19단계에서는 저장·표시만 한다.
    --   logo_file_id  files 의 이미지 한 건(회사 로고). NULL 이면 로고 없이 낸다
    --   biz_no · ceo_name · address · tel  — **회사** 정보이며 개인정보가 아니다.
    --   그래서 변경 이력(감사 detail.before/after)에 값이 그대로 남아도 된다.
    -- 엑셀 가져오기 대상이 아니다(화면에서만 고친다) — 회사 정보는 대량 갱신할 값이 아니다.
    logo_file_id BIGINT     REFERENCES files(id),
    biz_no     TEXT,
    ceo_name   TEXT,
    address    TEXT,
    tel        TEXT,
    is_active  BOOLEAN     NOT NULL DEFAULT true,
    created_by BIGINT      NOT NULL REFERENCES users(id),
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT p_brands_name_len CHECK (length(name) BETWEEN 1 AND 60)
);
CREATE UNIQUE INDEX IF NOT EXISTS p_brands_name_uidx ON p_brands (lower(name));

-- ─── 지점 (부평점·일산점 …) ───
-- warehouse_location_id · central_location_id 는 p_locations 를 가리키므로 FK 는 아래에서 붙인다
-- (두 표가 서로를 참조한다 — 표 생성 순서 때문에 한쪽은 나중에 붙일 수밖에 없다).
CREATE TABLE IF NOT EXISTS p_branches (
    id                   BIGSERIAL   PRIMARY KEY,
    brand_id             BIGINT      NOT NULL REFERENCES p_brands(id),
    name                 TEXT        NOT NULL,
    warehouse_location_id BIGINT,
    central_location_id   BIGINT,
    is_active            BOOLEAN     NOT NULL DEFAULT true,
    created_by           BIGINT      NOT NULL REFERENCES users(id),
    created_at           TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at           TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT p_branches_name_len CHECK (length(name) BETWEEN 1 AND 60)
);
CREATE UNIQUE INDEX IF NOT EXISTS p_branches_brand_name_uidx ON p_branches (brand_id, lower(name));
CREATE INDEX IF NOT EXISTS p_branches_brand_idx ON p_branches (brand_id);

-- ─── 장소 (지점 × 용도) ───
--   kind: warehouse(창고) · central(중앙장) · or(수술방) · other(기타)
--   legacy_name — 이카운트의 「플란치과_부평점_B1F_의약품창고」 같은 **원본 이름** 보존 (시안 ⑭)
CREATE TABLE IF NOT EXISTS p_locations (
    id          BIGSERIAL   PRIMARY KEY,
    branch_id   BIGINT      NOT NULL REFERENCES p_branches(id),
    kind        TEXT        NOT NULL CHECK (kind IN ('warehouse','central','or','other')),
    name        TEXT        NOT NULL,
    code        TEXT,
    legacy_name TEXT,
    is_active   BOOLEAN     NOT NULL DEFAULT true,
    created_by  BIGINT      NOT NULL REFERENCES users(id),
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT p_locations_name_len CHECK (length(name) BETWEEN 1 AND 80)
);
-- ★ 지점 격리(R-65)가 훑는 인덱스. requester·stock 의 모든 장소 조회는
--   `Authz::purchaseScopeWhere` 가 만든 `branch_id = :p_my_branch` 를 지난다.
CREATE INDEX IF NOT EXISTS p_locations_branch_idx ON p_locations (branch_id);
CREATE UNIQUE INDEX IF NOT EXISTS p_locations_branch_name_uidx ON p_locations (branch_id, lower(name));

-- 지점 → 대표 장소 두 칸의 FK (위에서 미룬 것)
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'p_branches_warehouse_fkey') THEN
        ALTER TABLE p_branches ADD CONSTRAINT p_branches_warehouse_fkey
            FOREIGN KEY (warehouse_location_id) REFERENCES p_locations(id);
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'p_branches_central_fkey') THEN
        ALTER TABLE p_branches ADD CONSTRAINT p_branches_central_fkey
            FOREIGN KEY (central_location_id) REFERENCES p_locations(id);
    END IF;
END
$$;

-- ─── 거래처 ───
-- 지점 무관 마스터다(전 지점이 같은 거래처를 쓴다) → 지점 격리 조건이 붙지 않는다.
CREATE TABLE IF NOT EXISTS p_vendors (
    id         BIGSERIAL   PRIMARY KEY,
    code       TEXT,
    name       TEXT        NOT NULL,
    biz_no     TEXT,
    is_active  BOOLEAN     NOT NULL DEFAULT true,
    created_by BIGINT      NOT NULL REFERENCES users(id),
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT p_vendors_name_len CHECK (length(name) BETWEEN 1 AND 120)
);
CREATE UNIQUE INDEX IF NOT EXISTS p_vendors_name_uidx ON p_vendors (lower(name));
CREATE UNIQUE INDEX IF NOT EXISTS p_vendors_code_uidx ON p_vendors (code) WHERE code IS NOT NULL;

-- ─── 분류 (대 → 중 → 소, 3단) ───
CREATE TABLE IF NOT EXISTS p_categories (
    id         BIGSERIAL   PRIMARY KEY,
    parent_id  BIGINT      REFERENCES p_categories(id),
    name       TEXT        NOT NULL,
    level      INT         NOT NULL CHECK (level BETWEEN 1 AND 3),
    is_active  BOOLEAN     NOT NULL DEFAULT true,
    created_by BIGINT      NOT NULL REFERENCES users(id),
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT p_categories_name_len        CHECK (length(name) BETWEEN 1 AND 80),
    CONSTRAINT p_categories_not_self_parent CHECK (parent_id IS NULL OR parent_id <> id),
    -- 1단계는 부모가 없고, 2·3단계는 부모가 있어야 한다
    CONSTRAINT p_categories_level_parent
        CHECK ((level = 1 AND parent_id IS NULL) OR (level > 1 AND parent_id IS NOT NULL))
);
-- 같은 부모 안 이름 중복 금지. 1단계(부모 NULL)는 NULL 끼리 같지 않아 따로 본다(folders 와 같은 방식).
CREATE UNIQUE INDEX IF NOT EXISTS p_categories_parent_name_uidx
    ON p_categories (parent_id, lower(name)) WHERE parent_id IS NOT NULL;
CREATE UNIQUE INDEX IF NOT EXISTS p_categories_root_name_uidx
    ON p_categories (lower(name)) WHERE parent_id IS NULL;

-- ─── 품목 ───
--   code      거래처 카탈로그 번호 **그대로**. 같은 거래처 안에서만 유일하다
--   pack_qty  포장 하나에 든 낱개 수 (1 봉 = 2000 낱개)
--   price_in  입고단가 — **VAT 별도 공급가액** 기준 (PRD 초안 A5)
CREATE TABLE IF NOT EXISTS p_items (
    id            BIGSERIAL   PRIMARY KEY,
    brand_id      BIGINT      REFERENCES p_brands(id),
    vendor_id     BIGINT      NOT NULL REFERENCES p_vendors(id),
    code          TEXT        NOT NULL,
    name          TEXT        NOT NULL,
    spec          TEXT,
    unit          TEXT,
    pack_qty      INT         NOT NULL DEFAULT 1 CHECK (pack_qty >= 1),
    cat1_id       BIGINT      REFERENCES p_categories(id),
    cat2_id       BIGINT      REFERENCES p_categories(id),
    cat3_id       BIGINT      REFERENCES p_categories(id),
    stock_managed BOOLEAN     NOT NULL DEFAULT true,
    safety_qty    INT         NOT NULL DEFAULT 0 CHECK (safety_qty >= 0),
    lead_days     INT         NOT NULL DEFAULT 0 CHECK (lead_days >= 0),
    min_order_qty INT         NOT NULL DEFAULT 1 CHECK (min_order_qty >= 1),
    price_in      NUMERIC(14,2) CHECK (price_in IS NULL OR price_in >= 0),
    barcode       TEXT,
    is_active     BOOLEAN     NOT NULL DEFAULT true,
    created_by    BIGINT      NOT NULL REFERENCES users(id),
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT p_items_code_len CHECK (length(code) BETWEEN 1 AND 60),
    CONSTRAINT p_items_name_len CHECK (length(name) BETWEEN 1 AND 200)
);
-- 같은 거래처 안 코드 중복 금지 (가져오기 판정 insert/update 의 열쇠이기도 하다)
CREATE UNIQUE INDEX IF NOT EXISTS p_items_vendor_code_uidx ON p_items (vendor_id, code);
-- 품목 찾기 API(T-43)가 훑는 세 인덱스. `q` 는 코드·이름·바코드 3중 OR 이다.
CREATE INDEX IF NOT EXISTS p_items_code_idx ON p_items (code);
CREATE INDEX IF NOT EXISTS p_items_name_idx ON p_items (lower(name));
CREATE INDEX IF NOT EXISTS p_items_cat_idx  ON p_items (cat1_id, cat2_id, cat3_id);
CREATE INDEX IF NOT EXISTS p_items_vendor_idx ON p_items (vendor_id);
CREATE INDEX IF NOT EXISTS p_items_barcode_idx ON p_items (barcode) WHERE barcode IS NOT NULL;

-- ─── 품목 × 사용 지점 (비우면 브랜드 전체에서 쓴다) ───
-- ★ 품목 검색의 지점 격리는 이 표를 지난다 (R-65).
CREATE TABLE IF NOT EXISTS p_item_branches (
    item_id   BIGINT NOT NULL REFERENCES p_items(id),
    branch_id BIGINT NOT NULL REFERENCES p_branches(id),
    PRIMARY KEY (item_id, branch_id)
);
CREATE INDEX IF NOT EXISTS p_item_branches_branch_idx ON p_item_branches (branch_id);

-- ─── 지점별(장소별) 인가량 ───
-- effective_from 이 있는 이유: 인가량을 바꾼 이력이 남아야 과거 전표의 판단 근거를 되짚을 수 있다.
CREATE TABLE IF NOT EXISTS p_par_levels (
    id             BIGSERIAL   PRIMARY KEY,
    location_id    BIGINT      NOT NULL REFERENCES p_locations(id),
    item_id        BIGINT      NOT NULL REFERENCES p_items(id),
    qty            INT         NOT NULL CHECK (qty >= 0),
    effective_from DATE        NOT NULL DEFAULT CURRENT_DATE,
    is_active      BOOLEAN     NOT NULL DEFAULT true,
    created_by     BIGINT      NOT NULL REFERENCES users(id),
    created_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at     TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX IF NOT EXISTS p_par_levels_uidx
    ON p_par_levels (location_id, item_id, effective_from);
CREATE INDEX IF NOT EXISTS p_par_levels_item_idx ON p_par_levels (item_id);

-- ─── 엑셀 가져오기 열 매핑 (F-29 「같은 양식은 두 단계로」) ───
-- mapping_json 은 {엑셀 열 이름 → 우리 칸 이름} 한 겹 객체다. 파일 내용은 들어가지 않는다.
CREATE TABLE IF NOT EXISTS p_import_mappings (
    id           BIGSERIAL   PRIMARY KEY,
    kind         TEXT        NOT NULL CHECK (kind IN ('items','vendors','locations')),
    name         TEXT        NOT NULL,
    mapping_json JSONB       NOT NULL,
    is_active    BOOLEAN     NOT NULL DEFAULT true,
    created_by   BIGINT      NOT NULL REFERENCES users(id),
    created_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT p_import_mappings_name_len CHECK (length(name) BETWEEN 1 AND 80)
);
CREATE UNIQUE INDEX IF NOT EXISTS p_import_mappings_kind_name_uidx
    ON p_import_mappings (kind, lower(name));

-- ─── 계정에 붙는 두 축 (ARCH 5.18 「권한 두 축」) ───
--   branch_id     — 지점 격리의 기준. NULL 허용(구매팀·경영진은 지점이 없다)
--   purchase_role — 모듈 역할. NULL 이면 /purchase/* 전부 403 (Admin 제외)
-- 기본값을 넣지 않는다: 두 값은 사람이 정해야 하는 값이고, 조용한 기본값은
-- "모르는 사이에 권한이 생긴" 상태를 만든다 (users.access_level 을 지어내지 않는 것과 같은 원칙).
ALTER TABLE users ADD COLUMN IF NOT EXISTS branch_id     BIGINT;
ALTER TABLE users ADD COLUMN IF NOT EXISTS purchase_role TEXT;

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'users_branch_id_fkey') THEN
        ALTER TABLE users ADD CONSTRAINT users_branch_id_fkey
            FOREIGN KEY (branch_id) REFERENCES p_branches(id);
    END IF;
    -- 값 목록은 Authz::purchaseAuthorize 의 판정과 **같은 네 값**이다 (ARCH 4.1).
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'users_purchase_role_check') THEN
        ALTER TABLE users ADD CONSTRAINT users_purchase_role_check
            CHECK (purchase_role IS NULL
                   OR purchase_role IN ('requester','buyer','stock','viewer'));
    END IF;
END
$$;

-- 모듈 역할이 있는 계정만 훑는 조회(계정 관리 화면의 필터)용
CREATE INDEX IF NOT EXISTS users_purchase_role_idx ON users (purchase_role) WHERE purchase_role IS NOT NULL;

-- ─── 권한 (ARCH 10장 · db/grants.sql 과 같은 내용) ───
-- ★ DELETE 가 어디에도 없다 — 기초정보를 그만 쓰는 것은 is_active = false 다.
REVOKE ALL ON p_brands, p_branches, p_locations, p_vendors, p_categories,
              p_items, p_item_branches, p_par_levels, p_import_mappings FROM tbox_app;
GRANT SELECT, INSERT, UPDATE ON p_brands          TO tbox_app;
GRANT SELECT, INSERT, UPDATE ON p_branches        TO tbox_app;
GRANT SELECT, INSERT, UPDATE ON p_locations       TO tbox_app;
GRANT SELECT, INSERT, UPDATE ON p_vendors         TO tbox_app;
GRANT SELECT, INSERT, UPDATE ON p_categories      TO tbox_app;
GRANT SELECT, INSERT, UPDATE ON p_items           TO tbox_app;
GRANT SELECT, INSERT, UPDATE ON p_item_branches   TO tbox_app;
GRANT SELECT, INSERT, UPDATE ON p_par_levels      TO tbox_app;
GRANT SELECT, INSERT, UPDATE ON p_import_mappings TO tbox_app;

GRANT USAGE, SELECT ON SEQUENCE p_brands_id_seq          TO tbox_app;
GRANT USAGE, SELECT ON SEQUENCE p_branches_id_seq        TO tbox_app;
GRANT USAGE, SELECT ON SEQUENCE p_locations_id_seq       TO tbox_app;
GRANT USAGE, SELECT ON SEQUENCE p_vendors_id_seq         TO tbox_app;
GRANT USAGE, SELECT ON SEQUENCE p_categories_id_seq      TO tbox_app;
GRANT USAGE, SELECT ON SEQUENCE p_items_id_seq           TO tbox_app;
GRANT USAGE, SELECT ON SEQUENCE p_par_levels_id_seq      TO tbox_app;
GRANT USAGE, SELECT ON SEQUENCE p_import_mappings_id_seq TO tbox_app;
-- p_item_branches 는 (item_id, branch_id) 복합 PK 라 시퀀스가 없다.

-- 백업 계정은 다른 표와 같게 읽기만 (PRD S-08)
GRANT SELECT ON p_brands, p_branches, p_locations, p_vendors, p_categories,
                p_items, p_item_branches, p_par_levels, p_import_mappings TO tbox_backup;
GRANT SELECT ON ALL SEQUENCES IN SCHEMA public TO tbox_backup;

-- tbox_perm 에는 아무것도 주지 않는다 — 그 롤은 permissions 표 하나만 본다 (SECURITY R-60).
REVOKE ALL ON p_brands, p_branches, p_locations, p_vendors, p_categories,
              p_items, p_item_branches, p_par_levels, p_import_mappings FROM tbox_perm;

COMMIT;
