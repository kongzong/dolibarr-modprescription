-- modPrescription: daily number sequence (CF-YYYYMMDD-NNN), same pattern as
-- modPatient / modMedRecord. Kept on module disable.
CREATE TABLE IF NOT EXISTS llx_prescription_sequence (
	ref_prefix	varchar(16) NOT NULL,
	last_value	bigint NOT NULL DEFAULT 0,
	PRIMARY KEY (ref_prefix)
) ENGINE=innodb;
