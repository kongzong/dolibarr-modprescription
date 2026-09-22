-- modPrescription: one prescription (TCM decoction or western). Never
-- deleted: status 9 = voided with reason/user/date kept. Issued (1) locks
-- the record; 2 = dispensed is reserved for modPharmacy.

CREATE TABLE llx_prescription(
	rowid				integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity				integer DEFAULT 1 NOT NULL,
	ref					varchar(32) NOT NULL,
	presc_type			varchar(3) DEFAULT 'TCM' NOT NULL,
	fk_patient			integer NOT NULL,
	fk_medrecord		integer DEFAULT NULL,
	fk_doctor			integer NOT NULL,
	fk_department		integer DEFAULT NULL,
	date_presc			datetime NOT NULL,
	diagnosis_text		varchar(255) DEFAULT NULL,
	doses				integer DEFAULT NULL,
	decoct_mode			varchar(8) DEFAULT NULL,
	usage_note			text DEFAULT NULL,
	note				text DEFAULT NULL,
	allergy_override_reason	varchar(255) DEFAULT NULL,
	status				smallint DEFAULT 0 NOT NULL,
	date_issued			datetime DEFAULT NULL,
	fk_user_issue		integer DEFAULT NULL,
	date_dispensed		datetime DEFAULT NULL,
	fk_user_dispensed	integer DEFAULT NULL,
	void_reason			varchar(255) DEFAULT NULL,
	date_void			datetime DEFAULT NULL,
	fk_user_void		integer DEFAULT NULL,
	model_pdf			varchar(32) DEFAULT NULL,
	last_main_doc		varchar(255) DEFAULT NULL,
	fk_user_creat		integer DEFAULT NULL,
	fk_user_modif		integer DEFAULT NULL,
	date_creation		datetime NOT NULL,
	tms					timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;
