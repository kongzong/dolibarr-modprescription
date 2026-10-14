-- Copyright (C) 2026 modPrescription contributors
-- Prescription templates: reusable row sets for the prescribing form.
CREATE TABLE llx_prescription_template(
	rowid				integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity				integer DEFAULT 1 NOT NULL,
	name				varchar(128) NOT NULL,
	presc_type			varchar(4) NOT NULL,			-- 'TCM' | 'WM'
	doses				integer DEFAULT NULL,			-- TCM: number of doses prefilled
	diagnosis_text		varchar(255) DEFAULT NULL,		-- optional default diagnosis
	note				text DEFAULT NULL,
	fk_user_creat		integer DEFAULT NULL,
	date_creation		datetime NOT NULL,
	tms					timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;
