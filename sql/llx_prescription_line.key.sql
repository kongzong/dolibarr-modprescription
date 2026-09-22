ALTER TABLE llx_prescription_line ADD INDEX idx_prescription_line_presc (fk_prescription);
ALTER TABLE llx_prescription_line ADD INDEX idx_prescription_line_product (fk_product);
