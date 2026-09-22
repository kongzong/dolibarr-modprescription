-- modPrescription: drug lines. label/product_ref are snapshots. TCM lines use
-- qty (grams) + decoct_code; western lines use dose/dose_unit/route/freq/days
-- and qty/qty_unit as the total quantity. allergy_hit marks lines that hit
-- an allergy and were released by a doctor with override permission.

CREATE TABLE llx_prescription_line(
	rowid			integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	fk_prescription	integer NOT NULL,
	position		smallint DEFAULT 0 NOT NULL,
	fk_product		integer DEFAULT NULL,
	product_ref		varchar(128) DEFAULT NULL,
	label			varchar(255) NOT NULL,
	qty				decimal(10,3) DEFAULT NULL,
	qty_unit		varchar(16) DEFAULT NULL,
	decoct_code		varchar(16) DEFAULT NULL,
	dose			decimal(10,3) DEFAULT NULL,
	dose_unit		varchar(16) DEFAULT NULL,
	route_code		varchar(16) DEFAULT NULL,
	freq_code		varchar(16) DEFAULT NULL,
	days			smallint DEFAULT NULL,
	sig_note		varchar(255) DEFAULT NULL,
	allergy_hit		smallint DEFAULT 0 NOT NULL
) ENGINE=innodb;
