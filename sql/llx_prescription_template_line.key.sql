-- Copyright (C) 2026 modPrescription contributors
ALTER TABLE llx_prescription_template_line ADD INDEX idx_prescription_template_line_fk (fk_template);
ALTER TABLE llx_prescription_template_line ADD CONSTRAINT fk_prescription_template_line_template FOREIGN KEY (fk_template) REFERENCES llx_prescription_template (rowid);
