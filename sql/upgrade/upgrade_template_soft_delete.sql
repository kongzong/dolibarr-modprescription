-- 2026-10-18: soft delete for prescription templates (round-4 audit).
-- status 0 normal, 9 deleted. delete() now updates this flag instead of
-- removing the row, so a deleted template can still be audited/restored.
ALTER TABLE llx_prescription_template ADD COLUMN status smallint DEFAULT 0 NOT NULL AFTER note;
