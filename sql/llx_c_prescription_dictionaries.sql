-- modPrescription dictionaries (Home > Setup > Dictionaries). Plain integer
-- PK without auto increment: admin/dict.php computes MAX(rowid)+1.

CREATE TABLE llx_c_prescription_decoct(
	rowid	integer PRIMARY KEY,
	pos		smallint DEFAULT 0 NOT NULL,
	code	varchar(16) NOT NULL,
	label	varchar(64) NOT NULL,
	active	tinyint DEFAULT 1 NOT NULL
) ENGINE=innodb;

CREATE TABLE llx_c_prescription_route(
	rowid	integer PRIMARY KEY,
	pos		smallint DEFAULT 0 NOT NULL,
	code	varchar(16) NOT NULL,
	label	varchar(64) NOT NULL,
	active	tinyint DEFAULT 1 NOT NULL
) ENGINE=innodb;

CREATE TABLE llx_c_prescription_freq(
	rowid	integer PRIMARY KEY,
	pos		smallint DEFAULT 0 NOT NULL,
	code	varchar(16) NOT NULL,
	label	varchar(64) NOT NULL,
	active	tinyint DEFAULT 1 NOT NULL
) ENGINE=innodb;

CREATE TABLE llx_c_prescription_dose_unit(
	rowid	integer PRIMARY KEY,
	pos		smallint DEFAULT 0 NOT NULL,
	code	varchar(16) NOT NULL,
	label	varchar(64) NOT NULL,
	active	tinyint DEFAULT 1 NOT NULL
) ENGINE=innodb;
