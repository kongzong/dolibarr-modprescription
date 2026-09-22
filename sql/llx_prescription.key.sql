ALTER TABLE llx_prescription ADD UNIQUE INDEX uk_prescription_ref (ref);
ALTER TABLE llx_prescription ADD INDEX idx_prescription_patient (fk_patient);
ALTER TABLE llx_prescription ADD INDEX idx_prescription_medrecord (fk_medrecord);
ALTER TABLE llx_prescription ADD INDEX idx_prescription_doctor (fk_doctor);
ALTER TABLE llx_prescription ADD INDEX idx_prescription_date (date_presc);
ALTER TABLE llx_prescription ADD INDEX idx_prescription_status (status);
ALTER TABLE llx_prescription ADD INDEX idx_prescription_entity (entity);
