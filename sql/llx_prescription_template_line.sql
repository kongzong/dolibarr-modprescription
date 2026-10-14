-- Copyright (C) 2026 modPrescription contributors
CREATE TABLE llx_prescription_template_line(
	rowid				integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	fk_template			integer NOT NULL,
	position			smallint DEFAULT 0 NOT NULL,
	fk_product			integer DEFAULT NULL,
	product_ref			varchar(128) DEFAULT NULL,
	label				varchar(255) NOT NULL,
	qty					decimal(10,3) DEFAULT NULL,
	qty_unit			varchar(16) DEFAULT NULL,
	decoct_code			varchar(16) DEFAULT NULL,
	dose				decimal(10,3) DEFAULT NULL,
	dose_unit			varchar(16) DEFAULT NULL,
	route_code			varchar(16) DEFAULT NULL,
	freq_code			varchar(16) DEFAULT NULL,
	days				smallint DEFAULT NULL,
	sig_note			varchar(255) DEFAULT NULL
) ENGINE=innodb;
