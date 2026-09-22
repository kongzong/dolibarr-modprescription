ALTER TABLE llx_c_prescription_decoct ADD UNIQUE INDEX uk_c_prescription_decoct_code (code);
ALTER TABLE llx_c_prescription_route ADD UNIQUE INDEX uk_c_prescription_route_code (code);
ALTER TABLE llx_c_prescription_freq ADD UNIQUE INDEX uk_c_prescription_freq_code (code);
ALTER TABLE llx_c_prescription_dose_unit ADD UNIQUE INDEX uk_c_prescription_dose_unit_code (code);
