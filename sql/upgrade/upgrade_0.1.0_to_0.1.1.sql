-- modPrescription upgrade 0.1.0 to 0.1.1: pharmacy dispense tracking on the
-- prescription (modPharmacy spec §2). Columns are nullable; existing rows
-- (draft/issued/voided) keep them NULL.

ALTER TABLE llx_prescription ADD COLUMN date_dispensed datetime DEFAULT NULL;
ALTER TABLE llx_prescription ADD COLUMN fk_user_dispensed integer DEFAULT NULL;
