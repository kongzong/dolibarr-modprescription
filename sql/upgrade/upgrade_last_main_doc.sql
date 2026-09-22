-- modPrescription upgrade 0.1.0-rc to 0.1.0: add the core last_main_doc
-- column used by commonGenerateDocument (writer keeps the relative path of
-- the last generated PDF on the main object).

ALTER TABLE llx_prescription ADD COLUMN last_main_doc varchar(255) DEFAULT NULL;
